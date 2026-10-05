"""Private passive validation. Integrity is not issuer authenticity or permission.

No transport, persistence, registry, training or synthetic eligibility bypass.
Errors expose codes only; callers must supply authenticated upstream authority.
"""
import json
import hashlib
from datetime import datetime
from pathlib import Path
import re
from functools import lru_cache
from jsonschema import Draft202012Validator, validators
from pydantic import ConfigDict, JsonValue, StrictInt, StrictStr, create_model

MAX_SAFE_INTEGER = 9007199254740991
_TIME = re.compile(r'\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\Z', re.ASCII)

class ContractError(ValueError):
    """Sanitized validation failure."""


def _fail(code):
    raise ContractError(code) from None


def _safe_int(value):
    if type(value) is not int or not 0 <= value <= MAX_SAFE_INTEGER:
        _fail('learning_contract.integer_invalid')


def _pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            _fail('learning_contract.duplicate_key')
        result[key] = value
    return result


def _invalid_number(_):
    _fail('learning_contract.number_invalid')


def _raw(raw_json):
    if type(raw_json) is not str:
        _fail('learning_contract.raw_json_required')
    try:
        value = json.loads(raw_json, object_pairs_hook=_pairs, parse_float=_invalid_number,
                           parse_constant=_invalid_number)
        if type(value) is not dict:
            _fail('learning_contract.object_required')
        stack = [(value, 1)]
        while stack:
            item, depth = stack.pop()
            if depth >= 512:
                _fail('learning_contract.depth_invalid')
            if type(item) is int:
                _safe_int(item)
            elif isinstance(item, dict):
                stack.extend((v, depth+1) for v in item.values())
            elif isinstance(item, list):
                stack.extend((v, depth+1) for v in item)
        canonical_json(value)  # Reject unpaired surrogates / invalid UTF-8.
        return value
    except (ValueError, TypeError, RecursionError, UnicodeError):
        _fail('learning_contract.json_invalid')


@lru_cache(maxsize=2)
def _schema_boundary(name):
    schema = json.loads((Path(__file__).resolve().parents[2] / 'contracts' / 'api' / f'{name}.schema.json').read_text(encoding='utf-8'))
    # Python's regex $ accepts a terminal newline. Enforce the existing SHA-256
    # contract's exact length at every reference without changing source schemas.
    schema['$defs']['Sha256'].update(minLength=64, maxLength=64)
    annotations = {'object': dict[str, JsonValue], 'array': list[JsonValue],
                   'integer': StrictInt, 'string': StrictStr}
    fields = {key: (annotations.get((schema['$defs'][prop['$ref'].split('/')[-1]] if '$ref' in prop else prop).get('type'), StrictStr), ...)
              for key, prop in schema['properties'].items()}
    model = create_model(name, __config__=ConfigDict(strict=True, extra='forbid'), **fields)
    checker = Draft202012Validator.TYPE_CHECKER.redefine('integer', lambda _, v: type(v) is int)
    strict_validator = validators.extend(Draft202012Validator, type_checker=checker)
    return model, strict_validator(schema)


def _validate_shape(value, name):
    model, schema = _schema_boundary(name)
    try:
        model.model_validate(value, strict=True)
        if next(schema.iter_errors(value), None) is not None:
            _fail('learning_contract.structure_invalid')
    except ValueError:
        _fail('learning_contract.structure_invalid')

def canonical_json(value):
    """Canonical UTF-8 bytes for contract values (object sort; array order kept)."""
    try:
        return json.dumps(value,sort_keys=True,ensure_ascii=False,separators=(',',':'),allow_nan=False).encode('utf-8')
    except (ValueError, TypeError, UnicodeError, RecursionError):
        _fail('learning_contract.canonical_invalid')

def case_digest(raw_json):
    value=_raw(raw_json); value.pop('case_hash',None)
    return hashlib.sha256(canonical_json(value)).hexdigest()

def manifest_digest(raw_json):
    value=_raw(raw_json); value.pop('canonical_digest',None)
    return hashlib.sha256(canonical_json(value)).hexdigest()

def parse_timestamp(value):
    """PHP-compatible RFC3339 subset with exact microsecond comparisons."""
    if type(value) is not str or not _TIME.fullmatch(value):
        _fail('learning_contract.timestamp_invalid')
    try:
        return datetime.fromisoformat(value)
    except ValueError:
        _fail('learning_contract.timestamp_invalid')


def _ids(value):
    if type(value) is not list or any(type(v) is not str or not v for v in value) or len(set(value)) != len(value):
        _fail('learning_contract.ids_invalid')
    return value


def _authority(raw_json, declared):
    authority = _raw(raw_json)
    extra = {'topic_ids','datapoint_ids','ambiguous_topic_ids','ambiguous_datapoint_ids'}
    if set(authority) != set(declared) | extra:
        _fail('learning_authority.structure_invalid')
    if any(authority[k] != v for k,v in declared.items()):
        _fail('learning_case.authority_mismatch')
    for key in extra:
        _ids(authority[key])
    return authority


def _universe(case, authority, kind, labels, id_field):
    universe = case[f'{kind}_universe']
    reviewed = universe[f'reviewed_{kind}_ids']
    outside = universe[f'outside_scope_{kind}_ids']
    if set(reviewed) & set(outside):
        _fail('learning_case.scope_overlap')
    if not set(reviewed+outside) <= set(authority[f'{kind}_ids']) or set(reviewed+outside) & set(authority[f'ambiguous_{kind}_ids']):
        _fail('learning_case.unknown_or_ambiguous_id')
    actual = [row[id_field] for row in labels]
    if len(actual) != len(set(actual)) or set(actual) != set(reviewed):
        _fail('learning_case.label_set_mismatch')

def validate_case(raw_json, authority_json):
    """Return a newly decoded case; synthetic provenance always fails closed."""
    case = _raw(raw_json)
    _validate_shape(case, 'learning-case-v1')
    authority = _authority(authority_json, case['authority'])
    source = case['provenance']['source_kind']
    if source == 'synthetic':
        _fail('learning_case.synthetic_not_eligible')
    _universe(case, authority, 'topic', case['topic_labels'], 'topic_id')
    _universe(case, authority, 'datapoint', case['datapoint_decisions'], 'datapoint_id')
    for label in case['topic_labels']:
        mask, value = label['observed_mask'], label['value']
        if type(mask) is not int or mask not in (0,1):
            _fail('learning_case.mask_invalid')
        if (mask == 0 and (source == 'human_product' or value is not None)) or (mask == 1 and (type(value) is not int or value not in (0,1))):
            _fail('learning_case.label_invalid')
    rights, closure = case['rights'], case['closure_evidence']
    if rights['state'] != 'granted' or rights['policy_status'] != 'approved':
        _fail('learning_case.rights_not_eligible')
    if closure['declaration_status'] != 'accepted' or closure['reviewed_universe'] is not True or closure['final_for_period_scope'] is not True:
        _fail('learning_case.closure_not_accepted')
    parse_timestamp(closure['recorded_at'])
    if case['case_hash'] != case_digest(raw_json):
        _fail('learning_case.hash_mismatch')
    return case

def validate_manifest(raw_json, previous_generation, now):
    """Validate freshness against explicit caller clock; no IO or inferred rights."""
    _safe_int(previous_generation)
    manifest = _raw(raw_json)
    _validate_shape(manifest, 'learning-eligibility-v1')
    if not isinstance(now, datetime) or now.tzinfo is None or now.utcoffset() is None:
        _fail('learning_manifest.clock_invalid')
    issued, until = parse_timestamp(manifest['issued_at']), parse_timestamp(manifest['valid_until'])
    if manifest['generation'] <= previous_generation or not issued <= now < until:
        _fail('learning_manifest.freshness_invalid')
    ids = [c['case_id'] for c in manifest['cases']]
    tombstones = manifest['tombstones']['revoked'] + manifest['tombstones']['deleted']
    tombstone_ids = [c['case_id'] for c in tombstones]
    for row in tombstones:
        parse_timestamp(row['at'])
    if len(set(ids)) != len(ids) or len(set(tombstone_ids)) != len(tombstone_ids) or set(ids) & set(tombstone_ids):
        _fail('learning_manifest.membership_invalid')
    if not set(manifest['eligible_case_ids']) <= set(ids):
        _fail('learning_manifest.eligible_case_unknown')
    if manifest['canonical_digest'] != manifest_digest(raw_json):
        _fail('learning_manifest.hash_mismatch')
    return manifest
