"""Private standalone synthetic P5 feature boundary."""
from dataclasses import dataclass
import copy
import hashlib
import json
import pandas as pd
from learning_case_contracts import canonical_json, validate_case, parse_timestamp
from learning_case_dataset import validate_matrices
FEATURE_COLUMNS = ('headquarters_country', 'employee_count_range', 'stock_listed')
SCHEMA_VERSION = 'learning-p5-features-v1'
TRANSFORM_VERSION = 'p5-small-categorical-v1'
EMPLOYEE_RANGES = ('1_9','10_49','50_249','250_499','500_999','1000_plus','not_sure')

def feature_schema():
    """Fresh serializable frozen-version definition, not a serving parity claim."""
    return dict(schema_version=SCHEMA_VERSION, transform_version=TRANSFORM_VERSION,
                columns=list(FEATURE_COLUMNS), employee_ranges=list(EMPLOYEE_RANGES),
                country='nonempty-controlled-string-or-null', stock_listed='bool-or-null',
                missing_token='__missing__', remainder='reject', snapshot_digest='sha256-canonical-values')

def digest(value):
    return hashlib.sha256(canonical_json(value)).hexdigest()

class FeatureError(ValueError):
    pass

@dataclass
class LearningDataset:
    X: pd.DataFrame
    values: pd.DataFrame
    masks: pd.DataFrame
    metadata: pd.DataFrame
    case_ids: list[str]
    topic_ids: list[str]
    dataset_digest: str
    content_digest: str = ''
    authorized_context: dict | None = None

def dataset_content_digest(dataset):
    """Seal derived matrices against accidental edits after envelope validation."""
    return digest(dict(X=dataset.X.to_dict('index'),values=dataset.values.to_dict('index'),
                       masks=dataset.masks.to_dict('index'),metadata=dataset.metadata.to_dict('index'),
                       case_ids=dataset.case_ids,topic_ids=dataset.topic_ids,schema=feature_schema()))

def build_dataset(records, topic_ids):
    """Consume only explicit synthetic envelopes; no live resolution or eligibility."""
    if type(records) is not list or not records:
        raise FeatureError('learning_features.records_invalid')
    records = copy.deepcopy(records)
    for r in records:
        if (type(r) is not dict or set(r) != {'namespace','synthetic_only','promotion_allowed','case','authority','features'}
                or r['namespace'] != 'standalone-synthetic-only' or r['synthetic_only'] is not True
                or r['promotion_allowed'] is not False):
            raise FeatureError('learning_features.synthetic_only')
        c = validate_case(json.dumps(r['case'], allow_nan=False), json.dumps(r['authority'], allow_nan=False))
        f = r['features']
        if (type(f) is not dict or set(f) != {'schema_version','transform_version','case_id','case_hash','snapshot_digest','values'}
                or f['schema_version'] != SCHEMA_VERSION or f['transform_version'] != TRANSFORM_VERSION
                or f['case_id'] != c['case_id'] or f['case_hash'] != c['case_hash']
                or c['p5_snapshot']['schema_version'] != 'p5-learning-input-v1'):
            raise FeatureError('learning_features.envelope_invalid')
        v = f['values']
        if type(v) is not dict or set(v) != set(FEATURE_COLUMNS):
            raise FeatureError('learning_features.allowlist_invalid')
        if (v['headquarters_country'] is not None and (type(v['headquarters_country']) is not str
                or not v['headquarters_country'] or v['headquarters_country'] == '__missing__')
                or v['employee_count_range'] is not None and v['employee_count_range'] not in EMPLOYEE_RANGES
                or v['stock_listed'] is not None and type(v['stock_listed']) is not bool):
            raise FeatureError('learning_features.value_invalid')
        if f['snapshot_digest'] != digest(v) or f['snapshot_digest'] != c['p5_snapshot']['digest']:
            raise FeatureError('learning_features.snapshot_mismatch')
        if c['topic_universe']['reviewed_topic_ids'] != topic_ids:
            raise FeatureError('learning_features.topic_axis_mismatch')
        parse_timestamp(c['closure_evidence']['recorded_at'])
    ids = [r['case']['case_id'] for r in records]
    if len(set(ids)) != len(ids):
        raise FeatureError('learning_features.duplicate_case')
    result = LearningDataset(
        pd.DataFrame([{k: '__missing__' if r['features']['values'][k] is None else
                          str(r['features']['values'][k]).lower() if k == 'stock_listed' else r['features']['values'][k]
                       for k in FEATURE_COLUMNS} for r in records], index=ids, columns=FEATURE_COLUMNS, dtype=object),
        pd.DataFrame([[t['value'] for t in r['case']['topic_labels']] for r in records], index=ids, columns=topic_ids, dtype=object),
        pd.DataFrame([[t['observed_mask'] for t in r['case']['topic_labels']] for r in records], index=ids, columns=topic_ids, dtype=object),
        pd.DataFrame([dict(company_group_key=r['case']['company_group_key'],recorded_at=r['case']['closure_evidence']['recorded_at'],source_kind=r['case']['provenance']['source_kind']) for r in records], index=ids, dtype=object),
        ids, list(topic_ids), digest(dict(records=records,topic_ids=topic_ids,feature_schema=feature_schema())))
    result.values, result.masks = validate_matrices(result.values,result.masks,ids,topic_ids)
    result.content_digest = dataset_content_digest(result)
    return result


def build_export_dataset(bundle, *, state, now, trusted_local_launcher=False):
    """Bridge actual T08 projection; never synthesize a standalone case envelope."""
    from learning_case_dataset import build_authorized_dataset
    if trusted_local_launcher is not True:
        raise FeatureError('learning_features.disabled')
    before = copy.deepcopy(state)
    projection = build_authorized_dataset(bundle['jsonl'], bundle['manifest_json'], bundle['bindings_json'],
        state=state, now=now, trusted_local_launcher=True, synthetic_only=True, namespace='test-namespace:t07-export')
    ids = projection['X'].index.tolist()
    if not ids:
        raise FeatureError('learning_features.empty')
    lines = {r['reference']['case_id']: r for r in map(json.loads, bundle['jsonl'].splitlines())}
    rows = []
    for row in projection['lineage']:
        recorded = lines[row['case_id']]['receipt']['recorded_at']
        parse_timestamp(recorded)
        rows.append(dict(company_group_key=row['company_group_key'], recorded_at=recorded, source_kind=row['source_kind']))
    X = projection['X'][list(FEATURE_COLUMNS)].copy(deep=True)
    X['stock_listed'] = X['stock_listed'].map(lambda v: str(v).lower()).astype(object)
    result = LearningDataset(X, projection['Y'], projection['masks'], pd.DataFrame(rows, index=ids, dtype=object),
        ids, projection['topic_ids'], projection['dataset_digest'], authorized_context=dict(
            bundle=copy.deepcopy(bundle), state=before, now=now, projection=projection))
    result.content_digest = dataset_content_digest(result)
    return result
