"""Private strict table validators only; no join, dedupe, split or training yet.

Object-dtype tables preserve Python integers and explicit None without coercion.
The caller supplies the exact ordered reviewed axes. No absent label is filled.
"""
import re
import pandas as pd
import pandera.pandas as pa

_TEXT_COLUMNS = ('case_id','case_hash','company_group_key','period_key','perimeter_key',
                 'source_revision','source_kind','topic_id')

class DatasetError(ValueError):
    """Sanitized table validation failure."""


def _fail(code):
    raise DatasetError(code) from None


def _axis(axis):
    if type(axis) is not list or not axis or any(type(x) is not str or not x for x in axis) or len(set(axis)) != len(axis):
        _fail('learning_dataset.axis_invalid')


def _binary(value):
    return type(value) is int and value in (0,1)


def _column(check, nullable=False):
    return pa.Column(object, checks=pa.Check(check, element_wise=True, ignore_na=False),
                     nullable=nullable, coerce=False)


def _schema_check(frame, schema):
    if not isinstance(frame, pd.DataFrame):
        _fail('learning_dataset.dataframe_required')
    try:
        return schema.validate(frame, lazy=False)
    except (pa.errors.SchemaError, pa.errors.SchemaErrors):
        _fail('learning_dataset.schema_invalid')

def validate_long_rows(frame, topic_ids):
    """Check complete per-case reviewed rows, preserving input and nulls."""
    _axis(topic_ids)
    columns = {key: _column(lambda v: type(v) is str and bool(v)) for key in _TEXT_COLUMNS}
    columns['case_hash'] = _column(lambda v: type(v) is str and re.fullmatch('[a-f0-9]{64}',v) is not None)
    columns['value'] = _column(lambda v: v is None or _binary(v), nullable=True)
    columns['observed_mask'] = _column(_binary)
    schema = pa.DataFrameSchema(columns, strict=True, coerce=False,
                                unique=['case_id','topic_id'])
    _schema_check(frame, schema)
    for _, group in frame.groupby('case_id', sort=False):
        if group['topic_id'].tolist() != topic_ids:
            _fail('learning_dataset.topic_axis_mismatch')
        for key in _TEXT_COLUMNS[:-1]:
            if group[key].nunique(dropna=False) != 1:
                _fail('learning_dataset.case_metadata_conflict')
        if group['source_kind'].iloc[0] not in ('human_product','report'):
            _fail('learning_dataset.source_ineligible')
    for source, value, mask in zip(frame['source_kind'],frame['value'],frame['observed_mask']):
        if (mask == 0 and (value is not None or source == 'human_product')) or (mask == 1 and not _binary(value)):
            _fail('learning_dataset.mask_value_invalid')
    if frame.empty:
        _fail('learning_dataset.empty')
    return frame.copy(deep=True)

def validate_matrices(values, masks, case_ids, topic_ids):
    """Validate aligned object-dtype Y/mask tables; return independent copies."""
    _axis(case_ids); _axis(topic_ids)
    for frame, is_mask in ((values,False),(masks,True)):
        schema = pa.DataFrameSchema({key: _column(_binary if is_mask else lambda v: v is None or _binary(v),
                                                  nullable=not is_mask) for key in topic_ids},
                                     strict=True, ordered=True, coerce=False)
        _schema_check(frame, schema)
        if frame.index.tolist() != case_ids or frame.columns.tolist() != topic_ids:
            _fail('learning_dataset.matrix_axis_mismatch')
    for value, mask in zip(values.to_numpy().flat, masks.to_numpy().flat):
        if (mask == 0 and value is not None) or (mask == 1 and not _binary(value)):
            _fail('learning_dataset.mask_value_invalid')
    return values.copy(deep=True), masks.copy(deep=True)

def build_authorized_dataset(jsonl, manifest_json, bindings_json, *, state, now,
                             trusted_local_launcher=False, synthetic_only=False, namespace=None):
    """Private launcher boundary. Flags are capabilities of the local harness, not HTTP claims.

    No digest authenticates Laravel. The trusted launcher owns transport and state.
    This function never accepts raw learning-case-v1 or computes its case_hash.
    """
    if trusted_local_launcher is not True or synthetic_only is not True or namespace != 'test-namespace:t07-export':
        _fail('learning_dataset.disabled')
    if type(state) is not dict or type(jsonl) is not str or len(jsonl.encode('utf-8')) > 1048576:
        _fail('learning_dataset.input_invalid')
    try:
        manifest, bindings = _manifest_bindings(manifest_json, bindings_json, state, now, namespace)
        entries = {x['case_id']: x for x in manifest['cases']}
        by_id = {}
        if type(bindings['cases']) is not list or len(bindings['cases']) > 256:
            _fail('learning_dataset.limit')
        for binding in bindings['cases']:
            _exact(binding, ('case_id','case_hash','source_revisions','rights_digest','policy_digest','export_digest',
                             'source_kind','source_revision','authorization_generation','authority','feature_vocab'))
            entry = {k: binding[k] for k in ('case_id','case_hash','source_revisions','rights_digest','policy_digest')}
            if binding['case_id'] in by_id or entry != entries.get(binding['case_id']):
                _fail('learning_dataset.binding_invalid')
            _counter(binding['authorization_generation'])
            if binding['source_kind'] not in ('human_product','report') or type(binding['source_revision']) is not str or not binding['source_revision']:
                _fail('learning_dataset.source_invalid')
            _sha(binding['export_digest'])
            by_id[binding['case_id']] = binding
        if set(by_id) != set(entries):
            _fail('learning_dataset.binding_invalid')
        parsed = []
        if jsonl and (not jsonl.endswith('\n') or len(jsonl.splitlines()) > 256):
            _fail('learning_dataset.jsonl_invalid')
        for raw in jsonl.splitlines():
            line = _projection(raw)
            identity = line['reference']['case_id']
            if identity not in manifest['eligible_case_ids']:
                continue
            binding = by_id.get(identity)
            if binding is None or binding['export_digest'] != hashlib.sha256((raw+'\n').encode('utf-8')).hexdigest():
                _fail('learning_dataset.export_binding_invalid')
            if (line['reference']['case_hash'] != binding['case_hash'] or line['source_revisions'] != binding['source_revisions']
                    or line['receipt']['authorization_digest'] != binding['rights_digest']
                    or line['receipt']['authorization_generation'] != binding['authorization_generation']):
                _fail('learning_dataset.revision_rights_invalid')
            authority = _authority(json.dumps(binding['authority']), line['authority'])
            _reviewed_projection(line, authority, binding['source_kind'])
            _features(line['X'], binding['feature_vocab'])
            parsed.append((line, binding))
        if {p['reference']['case_id'] for p, _ in parsed} != set(manifest['eligible_case_ids']):
            _fail('learning_dataset.missing_export')
        updated = _next_state(state, manifest, bindings, now)
        parsed = [(p,b) for p,b in parsed if b['case_id'] not in updated['excluded'] and b['case_id'] not in updated['tombstones']]
        out = _assemble_projection(parsed, manifest, updated)
        updated['lineage'][out['dataset_digest']] = copy.deepcopy(out['lineage'])
        state.clear(); state.update(updated)
        return out
    except (ContractError, ValueError, TypeError, KeyError, UnicodeError):
        _fail('learning_dataset.authorized_input_invalid')


# All new parsing stays below the historical validators, whose bodies are unchanged.
import copy
import hashlib
import json
from pydantic import ConfigDict, JsonValue, StrictStr, create_model
from learning_case_contracts import (_raw, _authority, canonical_json, validate_manifest, ContractError)
from learning_case_contracts import parse_timestamp


def _manifest_bindings(manifest_json, bindings_json, state, now, namespace):
    manifest = validate_manifest(manifest_json, state.get('generation', 0), now)
    bindings = _raw(bindings_json)
    _exact(bindings, ('schema_version','namespace','synthetic_only','promotion_allowed','purpose','manifest_digest','cases'), optional=('exclusions',))
    if (bindings['schema_version'] != 'learning-t07-bindings-v1' or bindings['namespace'] != namespace
            or bindings['synthetic_only'] is not True or bindings['promotion_allowed'] is not False
            or bindings['purpose'] != 'synthetic:learning' or bindings['manifest_digest'] != manifest['canonical_digest']):
        _fail('learning_dataset.binding_invalid')
    entries = {x['case_id']: x for x in manifest['cases']}
    seen = set()
    if type(bindings['cases']) is not list or len(bindings['cases']) > 256: _fail('learning_dataset.limit')
    for b in bindings['cases']:
        _exact(b, ('case_id','case_hash','source_revisions','rights_digest','policy_digest','export_digest','source_kind','source_revision','authorization_generation','authority','feature_vocab'))
        if b['case_id'] in seen or {k:b[k] for k in ('case_id','case_hash','source_revisions','rights_digest','policy_digest')} != entries.get(b['case_id']):
            _fail('learning_dataset.binding_invalid')
        seen.add(b['case_id']); _counter(b['authorization_generation']); _sha(b['export_digest'])
        _authority(json.dumps(b['authority']), {k:b['authority'][k] for k in ('framework_version','catalog_version','catalog_digest','mapping_version','mapping_digest')})
    if seen != set(entries): _fail('learning_dataset.binding_invalid')
    return manifest, bindings


def _next_state(state, manifest, bindings, now):
    updated = copy.deepcopy(state)
    updated.setdefault('tombstones', {}); updated.setdefault('excluded', {}); updated.setdefault('lineage', {}); updated.setdefault('invalidated', [])
    if 'issued_at' in updated and parse_timestamp(manifest['issued_at']) < parse_timestamp(updated['issued_at']):
        _fail('learning_dataset.clock_regression')
    current = {b['case_id']:b for b in bindings['cases'] if b['case_id'] in manifest['eligible_case_ids']}
    # Fresh bindings lack group/labels, so additions invalidate prior dedupe decisions.
    added_ids = set(current) - set(updated.get('current', {}))
    for id, previous in updated.get('current', {}).items():
        if current.get(id) != previous: updated['excluded'][id] = 'no_longer_exact_current'
    for exclusion in bindings.get('exclusions', []):
        _exact(exclusion, ('case_id','case_hash','reason')); _sha(exclusion['case_hash'])
        if type(exclusion['case_id']) is not str or not exclusion['case_id'] or exclusion['reason'] not in ('not_current','revoked','deleted'):
            _fail('learning_dataset.exclusion_invalid')
        updated['excluded'][exclusion['case_id']] = exclusion['reason']
    for kind, rows in manifest['tombstones'].items():
        for row in rows:
            if parse_timestamp(row['at']) > now: _fail('learning_dataset.future_tombstone')
            updated['tombstones'][row['case_id']] = {'case_hash':row['case_hash'],'kind':kind,'at':row['at']}
    for digest, lineage in updated['lineage'].items():
        if added_ids or any(row['case_id'] in updated['excluded'] or row['case_id'] in updated['tombstones'] or
               any(current.get(row['case_id'], {}).get(k) != row[k] for k in ('case_hash','source_revisions','rights_digest','policy_digest','export_digest','source_kind','source_revision')) for row in lineage):
            if digest not in updated['invalidated']: updated['invalidated'].append(digest)
    updated.update(generation=manifest['generation'],issued_at=manifest['issued_at'],current=current,
                   manifest_digest=manifest['canonical_digest'])
    return updated


def revalidate_authorized_dataset(dataset, manifest_json, bindings_json, *, state, now,
                                 trusted_local_launcher=False, synthetic_only=False, namespace=None):
    """Fresh local eligibility check only; no publication, acceptance, training or deletion."""
    if trusted_local_launcher is not True or synthetic_only is not True or namespace != 'test-namespace:t07-export':
        _fail('learning_dataset.disabled')
    try:
        manifest, bindings = _manifest_bindings(manifest_json, bindings_json, state, now, namespace)
        digest = dataset['dataset_digest']
        if digest != _dataset_digest(dataset['X'], dataset['Y'], dataset['masks'], dataset['lineage'], dataset['manifest_digest'], dataset['topic_ids']):
            _fail('learning_dataset.derivative_corrupt')
        if digest not in state.get('lineage', {}) or state['lineage'][digest] != dataset['lineage']:
            _fail('learning_dataset.unknown_derivative')
        updated = _next_state(state, manifest, bindings, now)
        inventory = [{'dataset_digest':d,'case_ids':sorted({r['case_id'] for r in updated['lineage'][d]}),
                      'disposition':'INVALIDATED_NEEDS_EXPLICIT_DELETION_AUTHORITY'} for d in updated['invalidated']]
        allowed = digest not in updated['invalidated'] and bool(dataset['lineage'])
        state.clear(); state.update(updated)
        return {'publication_allowed':allowed,'promotion_allowed':False,'purge_execution':'NOT_RUN','purge_inventory':inventory,
                'manifest_digest':manifest['canonical_digest'],'generation':manifest['generation']}
    except (ContractError, ValueError, TypeError, KeyError):
        _fail('learning_dataset.revalidation_invalid')


def _exact(value, keys, optional=()):
    if type(value) is not dict or not set(keys) <= set(value) or set(value) - set(keys) - set(optional):
        _fail('learning_dataset.structure_invalid')


def _counter(value):
    if type(value) is not int or not 0 <= value <= 9007199254740991:
        _fail('learning_dataset.integer_invalid')


def _sha(value):
    if type(value) is not str or re.fullmatch('[a-f0-9]{64}', value) is None:
        _fail('learning_dataset.digest_invalid')


_PROJECTION_KEYS = ('schema_version','namespace','synthetic_only','promotion_allowed','reference','company_group_key',
                    'period_scope','authority','source_revisions','provenance','receipt','X','topic_labels','topic_universe','p9_feedback')
_ProjectionModel = create_model('T07Projection', __config__=ConfigDict(strict=True, extra='forbid'),
                               **{k: (StrictStr if k in ('schema_version','namespace','company_group_key') else JsonValue, ...)
                                  for k in _PROJECTION_KEYS})


def _projection(raw):
    p = _raw(raw)  # duplicates, floats, depth and unsafe integers before Pydantic
    _exact(p, _PROJECTION_KEYS)
    _ProjectionModel.model_validate(p, strict=True)
    if p['schema_version'] != 'learning-case-export-v1' or p['namespace'] != 'test-namespace:t07-export' or p['synthetic_only'] is not True or p['promotion_allowed'] is not False:
        _fail('learning_dataset.projection_invalid')
    _exact(p['reference'], ('case_id','case_hash','expected_revisions','source_token','expected_authorization_generation'))
    _sha(p['reference']['case_hash']); _sha(p['company_group_key']); _sha(p['reference']['source_token'])
    _counter(p['reference']['expected_authorization_generation'])
    if type(p['reference']['case_id']) is not str or not p['reference']['case_id']:
        _fail('learning_dataset.identity_invalid')
    _exact(p['period_scope'], ('period_key','perimeter_key'))
    for value in p['period_scope'].values(): _sha(value)
    _exact(p['source_revisions'], ('p5','p6','p8','p9'))
    for revision in p['source_revisions'].values():
        _exact(revision, ('generation','revision','digest')); _counter(revision['generation']); _counter(revision['revision']); _sha(revision['digest'])
    _exact(p['reference']['expected_revisions'], ('p5','p6_base','p8','p9'))
    for revision in p['reference']['expected_revisions'].values():
        _exact(revision, ('generation','revision','epoch','digest'))
        _counter(revision['generation']); _counter(revision['revision']); _sha(revision['digest']); _sha(revision['epoch'])
    _exact(p['provenance'], ('synthetic_only','source_record_digest'))
    if p['provenance']['synthetic_only'] is not True: _fail('learning_dataset.source_invalid')
    _sha(p['provenance']['source_record_digest'])
    _exact(p['receipt'], ('schema_version','case_id','case_hash','source_token','p5_completion_reference',
                         'authorization_generation','authorization_digest','recorded_at','provenance','promotion_allowed','receipt_hash'))
    for key in ('case_id','case_hash','source_token'):
        if p['receipt'][key] != p['reference'][key]: _fail('learning_dataset.receipt_invalid')
    _counter(p['receipt']['authorization_generation'])
    for key in ('authorization_digest','receipt_hash'): _sha(p['receipt'][key])
    if p['receipt']['promotion_allowed'] is not False or p['receipt']['provenance'] != 'synthetic-only':
        _fail('learning_dataset.receipt_invalid')
    if type(p['topic_labels']) is not list: _fail('learning_dataset.labels_invalid')
    for label in p['topic_labels']:
        _exact(label, ('topic_id','value','observed_mask'))
        if type(label['topic_id']) is not str or not _binary(label['observed_mask']) or (label['value'] is not None and not _binary(label['value'])):
            _fail('learning_dataset.labels_invalid')
    _exact(p['topic_universe'], ('reviewed_topic_ids','outside_scope_topic_ids'))
    _exact(p['p9_feedback'], ('universe','decisions'))
    return p


def _reviewed_projection(p, a, source):
    for kind, universe, rows, key in (('topic',p['topic_universe'],p['topic_labels'],'topic_id'),
                                     ('datapoint',p['p9_feedback']['universe'],p['p9_feedback']['decisions'],'datapoint_id')):
        _exact(universe, ('reviewed_'+kind+'_ids','outside_scope_'+kind+'_ids'))
        reviewed, outside = universe['reviewed_'+kind+'_ids'], universe['outside_scope_'+kind+'_ids']
        for ids in (reviewed, outside):
            if type(ids) is not list or any(type(x) is not str for x in ids) or len(set(ids)) != len(ids): _fail('learning_dataset.universe_invalid')
        if set(reviewed) & set(outside) or set(reviewed+outside) != set(a[kind+'_ids']) or set(reviewed+outside) & set(a['ambiguous_'+kind+'_ids']):
            _fail('learning_dataset.universe_invalid')
        if type(rows) is not list or [r[key] for r in rows] != reviewed: _fail('learning_dataset.axis_invalid')
    for row in p['topic_labels']:
        if (row['observed_mask'] == 0 and (row['value'] is not None or source == 'human_product')) or (row['observed_mask'] == 1 and not _binary(row['value'])):
            _fail('learning_dataset.mask_value_invalid')
    for row in p['p9_feedback']['decisions']:
        _exact(row, ('datapoint_id','relevant','selected_to_answer','reason_codes'))
        if type(row['relevant']) is not bool or type(row['selected_to_answer']) is not bool or type(row['reason_codes']) is not list or any(type(x) is not str for x in row['reason_codes']):
            _fail('learning_dataset.p9_invalid')


def _features(x, vocab):
    _exact(x, ('schema_version','input_schema_version','transform_version','digest','values'))
    if (x['schema_version'],x['input_schema_version'],x['transform_version']) != ('learning-p5-features-v1','p5-learning-input-v1','p5-small-categorical-v1'):
        _fail('learning_dataset.feature_version_invalid')
    _exact(x['values'], ('employee_count_range','headquarters_country','stock_listed'))
    # Exact source-reviewed CharacterizationOptions vocab; no fitting or inference.
    expected = {'employee_count_range':['1_9','10_49','50_249','250_499','500_999','1000_plus','not_sure'],
                'headquarters_country':['Spain','Portugal','France','Germany','Italy','United Kingdom','United States','Other']}
    if vocab != expected or type(x['values']['stock_listed']) is not bool:
        _fail('learning_dataset.feature_vocab_invalid')
    for key, values in expected.items():
        if type(x['values'][key]) is not str or x['values'][key] not in values: _fail('learning_dataset.feature_vocab_invalid')
    if x['digest'] != hashlib.sha256(canonical_json(x['values'])).hexdigest(): _fail('learning_dataset.feature_digest_invalid')


def _assemble_projection(parsed, manifest, state):
    axes = [b['authority']['topic_ids'] for _, b in parsed]
    if axes and any(a != axes[0] for a in axes): _fail('learning_dataset.incompatible_axes')
    axis = axes[0] if axes else []
    if parsed and any(b['authority'] != parsed[0][1]['authority'] for _, b in parsed):
        _fail('learning_dataset.incompatible_authority')
    groups, quarantine, collapsed = {}, [], []
    for p, b in parsed:
        key = (p['company_group_key'],p['period_scope']['period_key'],p['period_scope']['perimeter_key'])
        groups.setdefault(key, []).append((p,b))
    for group in groups.values():
        signatures = {canonical_json({'X':p['X'],'labels':p['topic_labels'],'universe':p['topic_universe'],
                                      'revisions':p['source_revisions'],'source_kind':b['source_kind']}) for p,b in group}
        if len(signatures) != 1:
            quarantine.extend(sorted({p['reference']['case_id'] for p,_ in group}))
        else:
            collapsed.append(min(group, key=lambda row:row[0]['reference']['case_id']))
    parsed = sorted(collapsed, key=lambda row:row[0]['reference']['case_id'])
    ids = [p['reference']['case_id'] for p, _ in parsed]
    values, masks, features, lineage = [], [], [], []
    for p, b in parsed:
        labels = {r['topic_id']:r for r in p['topic_labels']}
        values.append([labels[t]['value'] if t in labels else None for t in axis])
        masks.append([labels[t]['observed_mask'] if t in labels else 0 for t in axis])
        features.append(p['X']['values'])
        lineage.append({k:b[k] for k in ('case_id','case_hash','source_revisions','rights_digest','policy_digest','export_digest','source_kind','source_revision')}
                       | {'feature_digest':p['X']['digest'],'company_group_key':p['company_group_key'],'period_scope':p['period_scope']})
    y = pd.DataFrame(values, index=ids, columns=axis, dtype=object)
    m = pd.DataFrame(masks, index=ids, columns=axis, dtype=object)
    if ids: y, m = validate_matrices(y, m, ids, axis)
    x = pd.DataFrame(features, index=ids, columns=['employee_count_range','headquarters_country','stock_listed'], dtype=object)
    digest = _dataset_digest(x, y, m, lineage, manifest['canonical_digest'], axis)
    state['generation'] = manifest['generation']
    return {'X':x,'Y':y,'masks':m,'lineage':lineage,'dataset_digest':digest,'manifest_digest':manifest['canonical_digest'],
            'manifest_generation':manifest['generation'],'topic_ids':axis,'quarantine':quarantine,'promotion_allowed':False}


def _dataset_digest(x, y, masks, lineage, manifest_digest, axis):
    ids = [row['case_id'] for row in lineage]
    if x.index.tolist() != ids or x.columns.tolist() != ['employee_count_range','headquarters_country','stock_listed']:
        _fail('learning_dataset.feature_axis_invalid')
    if ids: validate_matrices(y, masks, ids, axis)
    return hashlib.sha256(canonical_json({'lineage':lineage,'manifest_digest':manifest_digest,'topic_ids':axis,
                                         'X':x.to_dict('records'),'Y':y.values.tolist(),'masks':masks.values.tolist()})).hexdigest()
