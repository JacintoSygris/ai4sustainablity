import copy
import json
import unittest
import subprocess
from pathlib import Path
from datetime import datetime, timezone
from learning_case_contracts import (ContractError, validate_case, validate_manifest,
                                    canonical_json, case_digest, manifest_digest, parse_timestamp)
from learning_case_synthetic_helper import standalone_fixture, manifest_fixture, seal, digest


def sha_paths(value, schema, root=None, path=()):
    """Enumerate every concrete Sha256 reference from the unchanged contracts."""
    root = schema if root is None else root
    if schema.get('$ref') == '#/$defs/Sha256':
        yield path
        return
    if '$ref' in schema:
        schema = root['$defs'][schema['$ref'].split('/')[-1]]
    if isinstance(value, dict):
        for key, child in schema.get('properties', {}).items():
            yield from sha_paths(value[key], child, root, path + (key,))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from sha_paths(child, schema['items'], root, path + (index,))


def replace_path(value, path, replacement):
    for key in path[:-1]:
        value = value[key]
    value[path[-1]] = replacement


SHA_INVALID = {'newline': 'a'*64+'\n', 'crlf': 'a'*64+'\r\n',
               'short': 'a'*63, 'long': 'a'*65, 'uppercase': 'A'*64,
               'nonhex': 'g'*64}


class ContractTests(unittest.TestCase):
    def setUp(self):
        self.fixture = standalone_fixture()
        self.case, self.authority = self.fixture['case'], self.fixture['authority']

    def check(self, case=None, authority=None):
        return validate_case(json.dumps(case or self.case), json.dumps(authority or self.authority))

    def test_case_sha_fields_reject_invalid_exactly_before_hash_or_authority(self):
        schema_path = Path(__file__).resolve().parents[3] / 'contracts/api/learning-case-v1.schema.json'
        paths = list(sha_paths(self.case, json.loads(schema_path.read_text(encoding='utf-8'))))
        self.assertEqual(len(paths), 12)
        for path in paths:
            modes = ('matches', 'mismatch') if path[0] == 'authority' else ('matches',)
            for mode in modes:
                for kind, bad in SHA_INVALID.items():
                    with self.subTest(path=path, authority=mode, invalid=kind):
                        c, a = copy.deepcopy(self.case), copy.deepcopy(self.authority)
                        replace_path(c, path, bad)
                        if path[0] == 'authority' and mode == 'matches':
                            a[path[-1]] = bad
                        if path != ('case_hash',):
                            seal(c)  # Invalid nested value covered by a valid raw seal.
                        with self.assertRaisesRegex(ContractError, '^learning_contract.structure_invalid$'):
                            self.check(c, a)

    def test_manifest_sha_fields_reject_invalid_exactly_before_hash(self):
        schema_path = Path(__file__).resolve().parents[3] / 'contracts/api/learning-eligibility-v1.schema.json'
        paths = list(sha_paths(manifest_fixture(), json.loads(schema_path.read_text(encoding='utf-8'))))
        self.assertEqual(len(paths), 12)
        for path in paths:
            for kind, bad in SHA_INVALID.items():
                with self.subTest(path=path, invalid=kind):
                    m = manifest_fixture()
                    replace_path(m, path, bad)
                    if path != ('canonical_digest',):
                        m['canonical_digest'] = manifest_digest(json.dumps(m))
                    with self.assertRaisesRegex(ContractError, '^learning_contract.structure_invalid$'):
                        validate_manifest(json.dumps(m), 7, parse_timestamp('2026-10-02T09:30:00Z'))

    def test_php_negative_sha_parity_source_and_nested_manifest(self):
        service = Path(__file__).resolve().parents[3] / 'web/app/Services/LearningCaseContract.php'
        command = ("require $argv[1]; $v=json_decode(stream_get_contents(STDIN),true); "
                   "$c=new \\App\\Services\\LearningCaseContract; try { "
                   "if ($v['kind']==='case') { $c->assertEligibleLearningCase($v['raw'],$v['authority']); } "
                   "else { $c->assertEligibilityManifest($v['raw'],7,new DateTimeImmutable('2026-10-02T09:30:00Z')); } "
                   "echo 'ACCEPT'; } catch (InvalidArgumentException $e) { echo $e->getMessage(); }")
        for kind in ('case', 'manifest'):
            for invalid in (False, True):
                with self.subTest(kind=kind, invalid=invalid):
                    if kind == 'case':
                        c = copy.deepcopy(self.case)
                        if invalid:
                            c['provenance']['source_record_digest'] += '\n'
                        raw = json.dumps(seal(c))
                        payload = dict(kind=kind, raw=raw, authority=json.dumps(self.authority))
                        expected = 'learning_case.provenance_invalid'
                        validate = lambda: validate_case(raw, payload['authority'])
                    else:
                        m = manifest_fixture()
                        if invalid:
                            m['cases'][0]['source_revisions']['p9']['digest'] += '\n'
                        m['canonical_digest'] = manifest_digest(json.dumps(m))
                        raw = json.dumps(m)
                        payload = dict(kind=kind, raw=raw)
                        expected = 'learning_manifest.cases_invalid'
                        validate = lambda: validate_manifest(raw, 7, parse_timestamp('2026-10-02T09:30:00Z'))
                    result = subprocess.run(['php', '-r', command, str(service)],
                                            input=json.dumps(payload).encode(), capture_output=True, timeout=10)
                    self.assertEqual(result.returncode, 0, result.stderr.decode())
                    self.assertEqual(result.stdout.decode(), expected if invalid else 'ACCEPT')
                    if invalid:
                        with self.assertRaisesRegex(ContractError, '^learning_contract.structure_invalid$'):
                            validate()
                    else:
                        validate()

    def test_complete_human_and_report_masks(self):
        self.assertFalse(self.fixture['promotion_allowed'])
        self.assertEqual(self.check()['case_id'], 'case-001')
        c = copy.deepcopy(self.case)
        c['provenance']['source_kind'] = 'report'
        c['topic_labels'][1].update(value=None, observed_mask=0)
        self.assertIsNone(self.check(seal(c))['topic_labels'][1]['value'])

    def test_nonbinary_labels_and_masks_never_coerce(self):
        for field in ('value', 'observed_mask'):
            for bad in (True, False, '0', 0.0, 1.0, float('nan'), None, 2):
                with self.subTest(field=field, bad=repr(bad)):
                    c = copy.deepcopy(self.case)
                    c['topic_labels'][0][field] = bad
                    with self.assertRaises(ContractError):
                        self.check(c)

    def test_missing_extra_duplicate_labels_and_universe(self):
        for mutation in ('missing', 'extra', 'duplicate', 'scope', 'unknown', 'ambiguous'):
            c, a = copy.deepcopy(self.case), copy.deepcopy(self.authority)
            if mutation == 'missing': c['topic_labels'].pop()
            if mutation == 'extra': c['topic_labels'].append(dict(topic_id='103', value=0, observed_mask=1))
            if mutation == 'duplicate': c['topic_labels'].append(c['topic_labels'][0].copy())
            if mutation == 'scope': c['topic_universe']['outside_scope_topic_ids'].append('101')
            if mutation == 'unknown': a['topic_ids'].remove('101')
            if mutation == 'ambiguous': a['ambiguous_topic_ids'].append('101')
            with self.subTest(mutation=mutation), self.assertRaises(ContractError): self.check(seal(c), a)

    def test_authority_hash_rights_closure_and_synthetic_fail_closed(self):
        for section, field, bad in [('authority','catalog_digest','0'*64), ('authority','mapping_digest','0'*64),
                                     ('rights','state','revoked'), ('rights','policy_status','unapproved'),
                                     ('closure_evidence','reviewed_universe',False),
                                     ('closure_evidence','declaration_status','unaccepted'),
                                     ('provenance','source_kind','synthetic')]:
            c = copy.deepcopy(self.case); c[section][field] = bad
            with self.subTest(field=field), self.assertRaises(ContractError): self.check(seal(c))
        c = copy.deepcopy(self.case); c['case_hash'] = '0'*64
        with self.assertRaises(ContractError): self.check(c)

    def test_report_absence_is_null_human_is_complete(self):
        for source, mask, value in [('report',0,0), ('report',1,None), ('human_product',0,None)]:
            c = copy.deepcopy(self.case); c['provenance']['source_kind'] = source
            c['topic_labels'][0].update(value=value, observed_mask=mask)
            with self.assertRaises(ContractError): self.check(seal(c))

    def test_raw_duplicate_keys_container_and_nested_extras(self):
        raw = json.dumps(self.case)
        for bad in [raw.replace('"case_id":', '"case_id":"other","case_id":', 1),
                    raw.replace('"case_id":', '"case\\u005fid":"other","case_id":', 1), '[]',
                    raw.replace('"reviewed_topic_ids": ["101", "102"]', '"reviewed_topic_ids": {"0":"101","1":"102"}')]:
            with self.assertRaises(ContractError): validate_case(bad, json.dumps(self.authority))
        for key in ['period_scope','authority','provenance','p5_snapshot','p6_snapshot','topic_universe',
                    'datapoint_universe','rights','closure_evidence']:
            c = copy.deepcopy(self.case); c[key]['extra'] = 1
            with self.subTest(key=key), self.assertRaises(ContractError): self.check(c)

    def test_safe_integer_precision_and_calendar(self):
        for bad in [True,'1',1.0,-1,9007199254740992]:
            c = copy.deepcopy(self.case); c['source_revisions']['p5']['revision'] = bad
            with self.assertRaises(ContractError): self.check(c)
        c = copy.deepcopy(self.case); c['source_revisions']['p5']['revision'] = 9007199254740991
        self.check(seal(c))
        for bad in ['2026-02-31T00:00:00Z','2026-10-02T24:00:00Z','2026-10-02T00:00:00.1234567Z',
                    '2026-10-02T00:00:00+24:00','2026-10-02T00:00:60Z']:
            with self.assertRaises(ContractError): parse_timestamp(bad)
        self.assertEqual(parse_timestamp('2026-10-02T09:00:00.123456Z').microsecond, 123456)

    def test_php_golden_canonical_and_array_order(self):
        m = manifest_fixture()
        self.assertEqual(manifest_digest(json.dumps(m)), 'cc97b31fe4c4905a9bd34dd161a6c8566c4456c21e7aecbf319836a4f8efcc7d')
        self.assertEqual(canonical_json({'z':['ñ','/','\u2028','\u2029'], 'a':{}}),
                         '{"a":{},"z":["ñ","/","\u2028","\u2029"]}'.encode())
        self.assertEqual(case_digest(json.dumps(self.case)), self.case['case_hash'])
        self.assertEqual(digest(dict(reversed(list(m.items())))), digest(m))
        self.assertNotEqual(digest([1,0]), digest([0,1]))
        vector = dict(z=['array/first','array-second'], a='ordinary-é / " \\ \n \t \u2028\u2029', canonical_digest='0'*64)
        self.assertEqual(manifest_digest(json.dumps(vector)), 'dda763900bd26b1f7e83a1ac873e93d8663a0702b235611ca41e33546e1bfedd')

    def test_php_live_case_hash_and_microsecond_parity(self):
        # Pure class invocation; no Laravel bootstrap, database, env or real cases.
        service = Path(__file__).resolve().parents[3] / 'web/app/Services/LearningCaseContract.php'
        command = "require $argv[1]; $raw=stream_get_contents(STDIN); echo \\App\\Services\\LearningCaseContract::learningCaseDigest($raw);"
        c = copy.deepcopy(self.case)
        c['closure_evidence']['recorded_at']='2026-10-02T09:00:00.123456+02:30'
        c['datapoint_decisions'][0]['note']='synthetic-é / \u2028\u2029'
        raw=json.dumps(seal(c),ensure_ascii=False)
        result=subprocess.run(['php','-r',command,str(service)],input=raw.encode(),capture_output=True,timeout=10)
        self.assertEqual(result.returncode,0, 'PHP pure class execution failed')
        self.assertEqual(result.stdout.decode(),case_digest(raw))
        for timestamp in ['2026-10-02T09:00:00.000001Z','2026-10-02T09:00:00.999999-00:00',
                          '2026-10-02T09:00:00.123456+23:59']:
            result=subprocess.run(['php','-r','echo (new DateTimeImmutable($argv[1]))->format("U.u");',timestamp],
                                  capture_output=True,timeout=10)
            parsed=parse_timestamp(timestamp)
            self.assertEqual(result.returncode,0)
            self.assertEqual(result.stdout.decode(),f'{int(parsed.timestamp())}.{parsed.microsecond:06d}')

    def test_empty_arrays_strict_nested_types_and_malformed_raw(self):
        c=copy.deepcopy(self.case)
        for kind in ['topic','datapoint']:
            c[f'{kind}_universe'][f'reviewed_{kind}_ids']=[]
        c['topic_labels']=[]; c['datapoint_decisions']=[]
        self.check(seal(c))
        for raw in ['{','{"x":NaN}','{"x":1e999}','{"x":9007199254740992}',
                    '{"x":"\\ud800"}', '{"x":'+ '['*512+'0'+']'*512+'}']:
            with self.assertRaises(ContractError): validate_case(raw,json.dumps(self.authority))
        for section,field,bad in [('datapoint_decisions','relevant',1),('source_revisions','p5',[]),
                                   ('closure_evidence','final_for_period_scope',1)]:
            c=copy.deepcopy(self.case)
            target=c[section][0] if isinstance(c[section],list) else c[section]
            target[field]=bad
            with self.assertRaises(ContractError): self.check(c)

    def test_failure_diagnostics_only_expose_code(self):
        raw=json.dumps(self.case).replace('"case_id": "case-001"','"case_id": 123')
        try:
            validate_case(raw,json.dumps(self.authority))
        except ContractError as error:
            self.assertEqual(str(error),'learning_contract.structure_invalid')
            self.assertTrue(error.__suppress_context__)
        else: self.fail('invalid case accepted')

    def test_manifest_hash_generation_half_open_microseconds(self):
        m = manifest_fixture(); now = parse_timestamp('2026-10-02T09:30:00Z')
        self.assertEqual(validate_manifest(json.dumps(m), 7, now)['generation'],8)
        for previous in [8, True, '7',7.0,-1,9007199254740992]:
            with self.assertRaises(ContractError): validate_manifest(json.dumps(m), previous, now)
        for clock in ['2026-10-02T08:59:59.999999Z','2026-10-02T10:00:00Z']:
            with self.assertRaises(ContractError): validate_manifest(json.dumps(m),7,parse_timestamp(clock))
        validate_manifest(json.dumps(m),7,parse_timestamp('2026-10-02T09:59:59.999999Z'))
        m['generation']=9
        with self.assertRaises(ContractError): validate_manifest(json.dumps(m),7,now)

    def test_manifest_tombstones_and_membership(self):
        for mutation in ['tombstone','duplicate','eligible','cross_tombstone']:
            m = manifest_fixture()
            if mutation == 'tombstone': m['tombstones']['revoked'][0]['case_id']='case-001'
            if mutation == 'duplicate': m['cases'].append(copy.deepcopy(m['cases'][0]))
            if mutation == 'eligible': m['eligible_case_ids'].append('absent')
            if mutation == 'cross_tombstone': m['tombstones']['deleted'][0]['case_id']='case-revoked'
            m['canonical_digest']=digest({k:v for k,v in m.items() if k!='canonical_digest'})
            with self.subTest(mutation=mutation), self.assertRaises(ContractError):
                validate_manifest(json.dumps(m),7,datetime(2026,10,2,9,30,tzinfo=timezone.utc))


if __name__ == '__main__': unittest.main()
