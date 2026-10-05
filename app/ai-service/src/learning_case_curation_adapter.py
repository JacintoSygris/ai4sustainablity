"""Pure private transport: structured commands only, never current authorization.

Free text is entirely withheld, including synthetic notes. No backend or SDK.
"""
import hashlib
import json
import re


class CurationError(ValueError):
    """Stable sanitized code, never untrusted text."""


def _deny():
    raise CurationError('learning_curation.transport_invalid') from None


def _pairs(pairs):
    out = {}
    for key, value in pairs:
        if key in out:
            _deny()
        out[key] = value
    return out


def _raw(raw):
    if type(raw) is not str or len(raw.encode('utf-8')) > 1048576:
        _deny()
    try:
        value = json.loads(raw, object_pairs_hook=_pairs, parse_float=lambda _: _deny(), parse_constant=lambda _: _deny())
        json.dumps(value, ensure_ascii=False).encode('utf-8')
    except (ValueError, UnicodeError, RecursionError):
        _deny()
    if type(value) is not dict:
        _deny()
    return value


def _keys(value, keys):
    if type(value) is not dict or set(value) != set(keys.split()):
        _deny()


def _hex(value):
    if type(value) is not str or re.fullmatch(r'[a-f0-9]{64}', value, re.ASCII) is None:
        _deny()


def _int(value, minimum=0):
    if type(value) is not int or not minimum <= value <= 9007199254740991:
        _deny()


def _id(value):
    if type(value) is not str or re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}', value, re.ASCII) is None:
        _deny()


def _ids(values, topic=False):
    if type(values) is not list or len(values) > 1024:
        _deny()
    for value in values:
        _id(value)
        if topic and re.fullmatch(r'[1-9][0-9]{0,15}', value, re.ASCII) is None:
            _deny()
    if len(values) != len(set(values)):
        _deny()


def _labels(labels, universe):
    if type(labels) is not list or not 1 <= len(labels) <= 1024:
        _deny()
    seen = []
    for row in labels:
        _keys(row, 'topic_id value observed_mask')
        _ids([row['topic_id']], topic=True)
        mask, value = row['observed_mask'], row['value']
        if type(mask) is not int or mask not in (0, 1):
            _deny()
        if (mask == 0 and value is not None) or (mask == 1 and (type(value) is not int or value not in (0, 1))):
            _deny()
        if row['topic_id'] not in universe or row['topic_id'] in seen:
            _deny()
        seen.append(row['topic_id'])


def _export(raw):
    if type(raw) is not str or raw.startswith('\ufeff') or raw.count('\n') != 1 or not raw.endswith('\n'):
        _deny()
    v = _raw(raw[:-1])
    _keys(v, 'schema_version namespace synthetic_only promotion_allowed reference company_group_key period_scope authority source_revisions provenance receipt X topic_labels topic_universe p9_feedback')
    if v['schema_version'] != 'learning-case-export-v1' or v['namespace'] != 'test-namespace:t07-export' or v['synthetic_only'] is not True or v['promotion_allowed'] is not False:
        _deny()
    ref = v['reference']
    _keys(ref, 'case_id case_hash expected_revisions source_token expected_authorization_generation')
    _id(ref['case_id']); _hex(ref['case_hash']); _hex(ref['source_token']); _int(ref['expected_authorization_generation'], 1)
    _keys(ref['expected_revisions'], 'p5 p6_base p8 p9')
    for header in ref['expected_revisions'].values():
        _keys(header, 'generation revision epoch digest')
        _int(header['generation']); _int(header['revision']); _hex(header['epoch']); _hex(header['digest'])
    _hex(v['company_group_key']); _keys(v['period_scope'], 'period_key perimeter_key')
    for value in v['period_scope'].values(): _hex(value)
    _keys(v['authority'], 'framework_version catalog_version catalog_digest mapping_version mapping_digest')
    for key, value in v['authority'].items():
        if key.endswith('_digest'): _hex(value)
        else: _id(value)
    _keys(v['source_revisions'], 'p5 p6 p8 p9')
    for key, header in v['source_revisions'].items():
        _keys(header, 'generation revision digest')
        _int(header['generation']); _int(header['revision']); _hex(header['digest'])
        if key != 'p6' and any(header[k] != ref['expected_revisions'][key][k] for k in header): _deny()
    _keys(v['provenance'], 'synthetic_only source_record_digest')
    if v['provenance']['synthetic_only'] is not True: _deny()
    _hex(v['provenance']['source_record_digest'])
    receipt = v['receipt']
    _keys(receipt, 'schema_version case_id case_hash source_token p5_completion_reference authorization_generation authorization_digest recorded_at provenance promotion_allowed receipt_hash')
    if receipt['schema_version'] != 'learning-case-closure-receipt-v1' or receipt['provenance'] != 'synthetic-only' or receipt['promotion_allowed'] is not False: _deny()
    for key in ['case_id','case_hash','source_token']:
        if receipt[key] != ref[key]: _deny()
    _int(receipt['authorization_generation'], 1)
    if receipt['authorization_generation'] != ref['expected_authorization_generation']: _deny()
    for key in ['p5_completion_reference','authorization_digest','receipt_hash']: _hex(receipt[key])
    if type(receipt['recorded_at']) is not str or re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z', receipt['recorded_at'], re.ASCII) is None: _deny()
    x = v['X']
    _keys(x, 'schema_version input_schema_version transform_version digest values')
    if (x['schema_version'], x['input_schema_version'], x['transform_version']) != ('learning-p5-features-v1','p5-learning-input-v1','p5-small-categorical-v1'): _deny()
    _hex(x['digest']); _keys(x['values'], 'employee_count_range headquarters_country stock_listed')
    if type(x['values']['stock_listed']) is not bool: _deny()
    for key in ['employee_count_range','headquarters_country']:
        if type(x['values'][key]) is not str or not x['values'][key] or len(x['values'][key]) > 128: _deny()
    encoded = json.dumps(x['values'], separators=(',', ':'), ensure_ascii=False).encode('utf-8')
    if hashlib.sha256(encoded).hexdigest() != x['digest']: _deny()
    universe = v['topic_universe']
    _keys(universe, 'reviewed_topic_ids outside_scope_topic_ids')
    for ids in universe.values(): _ids(ids, topic=True)
    if set(universe['reviewed_topic_ids']) & set(universe['outside_scope_topic_ids']): _deny()
    _labels(v['topic_labels'], universe['reviewed_topic_ids'])
    if set(row['topic_id'] for row in v['topic_labels']) != set(universe['reviewed_topic_ids']): _deny()
    p9 = v['p9_feedback']; _keys(p9, 'universe decisions'); _keys(p9['universe'], 'reviewed_datapoint_ids outside_scope_datapoint_ids')
    for ids in p9['universe'].values(): _ids(ids)
    if set(p9['universe']['reviewed_datapoint_ids']) & set(p9['universe']['outside_scope_datapoint_ids']): _deny()
    if type(p9['decisions']) is not list: _deny()
    seen = []
    for row in p9['decisions']:
        _keys(row, 'datapoint_id relevant selected_to_answer reason_codes')
        if row['datapoint_id'] not in p9['universe']['reviewed_datapoint_ids'] or row['datapoint_id'] in seen: _deny()
        seen.append(row['datapoint_id'])
        if type(row['relevant']) is not bool or type(row['selected_to_answer']) is not bool: _deny()
        _ids(row['reason_codes'])
    if set(seen) != set(p9['universe']['reviewed_datapoint_ids']): _deny()
    return v


def annotation_command(export_jsonl, input_json):
    """No authority captured: Laravel must reconstruct current export on import."""
    exported = _export(export_jsonl)
    value = _raw(input_json)
    _keys(value, 'schema_version expected_annotation_revision command_id topic_labels note')
    if value['schema_version'] != 'learning-case-curation-input-v1': _deny()
    _int(value['expected_annotation_revision']); _hex(value['command_id'])
    if value['expected_annotation_revision'] == 9007199254740991: _deny()
    if value['note'] is not None and type(value['note']) is not str: _deny()
    _labels(value['topic_labels'], exported['topic_universe']['reviewed_topic_ids'])
    return {'schema_version':'learning-case-annotation-command-v1',
            'export_digest':hashlib.sha256(export_jsonl.encode('utf-8')).hexdigest(),
            'reference':exported['reference'], 'expected_annotation_revision':value['expected_annotation_revision'],
            'command_id':value['command_id'], 'topic_labels':value['topic_labels'],
            'notes':{'status':'withheld','reason':'free_text_transport_disabled'}}
