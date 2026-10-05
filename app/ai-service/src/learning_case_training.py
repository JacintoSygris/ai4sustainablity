"""Standalone group/time split and observed-label training (no serving)."""
from dataclasses import dataclass
from learning_case_features import digest
from learning_case_contracts import parse_timestamp
import copy
import math
import platform
from importlib.metadata import version
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.preprocessing import OneHotEncoder
from sklearn.pipeline import Pipeline
from sklearn.linear_model import LogisticRegression
from learning_case_features import FEATURE_COLUMNS, EMPLOYEE_RANGES, feature_schema, dataset_content_digest
from learning_case_dataset import validate_matrices

class TrainingError(ValueError):
    pass

@dataclass(frozen=True)
class DatasetSplit:
    train: tuple[str, ...]
    calibration: tuple[str, ...]
    holdout: tuple[str, ...]
    excluded: dict[str, str]
    cutoffs: dict[str, str]
    split_digest: str

def split_dataset(dataset, *, train_end, calibration_end, holdout_end):
    """Half-open windows; quarantine whole groups spanning windows or future."""
    ids = dataset.case_ids
    cutoffs = dict(train_end=train_end,calibration_end=calibration_end,holdout_end=holdout_end)
    bounds = [parse_timestamp(x) for x in cutoffs.values()]
    if not bounds[0] < bounds[1] < bounds[2]:
        raise TrainingError('learning_training.cutoffs_invalid')
    m = dataset.metadata
    if (m.index.tolist() != ids or m.columns.tolist() != ['company_group_key','recorded_at','source_kind']
            or any(type(v) is not str or not v for v in m['company_group_key'])
            or any(v not in ('human_product','report') for v in m['source_kind'])):
        raise TrainingError('learning_training.metadata_invalid')
    windows = {}
    for case_id, t in zip(ids,m['recorded_at']):
        time = parse_timestamp(t)
        windows[case_id] = next((i for i,b in enumerate(bounds) if time < b), 3)
    assignments = {}; excluded = {}
    for _, group in m.groupby('company_group_key',sort=False):
        group_ids = group.index.tolist(); ws = {windows[i] for i in group_ids}
        reason = 'future_group' if 3 in ws else 'group_crosses_time_window' if len(ws) > 1 else None
        for i in group_ids:
            if reason: excluded[i] = reason
            else: assignments[i] = next(iter(ws))
    partitions = [tuple(i for i in ids if assignments.get(i) == w) for w in range(3)]
    payload = dict(train=partitions[0],calibration=partitions[1],holdout=partitions[2],
                   excluded=excluded,cutoffs=cutoffs,metadata=m.to_dict('index'))
    return DatasetSplit(*partitions,excluded,cutoffs,digest(payload))

@dataclass
class LearningCandidate:
    pipelines: dict[str, Pipeline]
    label_status: dict[str, str]
    supports: dict[str, dict]
    topic_ids: list[str]
    feature_schema: dict
    thresholds: dict[str, float]
    policy: dict
    dependency_versions: dict[str, str]
    seeds: dict[str, int]
    dataset_digest: str
    split_digest: str
    development_digest: str
    training_case_ids: tuple[str, ...]
    tuning_status: str
    promotion_allowed: bool = False


def _check_X(X):
    if (not isinstance(X,pd.DataFrame) or X.columns.tolist() != list(FEATURE_COLUMNS)
            or not X.index.is_unique or any(type(v) is not str or not v for v in X.to_numpy().flat)
            or any(v not in (*EMPLOYEE_RANGES,'__missing__') for v in X['employee_count_range'])
            or any(v not in ('true','false','__missing__') for v in X['stock_listed'])):
        raise TrainingError('learning_training.features_invalid')


def train_candidate(dataset, split, *, synthetic_policy, seed, synthetic_search=None):
    """Fit independent real sklearn pipelines only on observed train rows.

    Calibration is reserved; fixed caller-owned synthetic thresholds are not
    optimized. No qualified temporal inner folds or Optuna run in tranche 1b.
    """
    p = synthetic_policy
    if (type(p) is not dict or set(p) != {'schema_version','synthetic_only','promotion_allowed','label_thresholds','sector_guard','crc_floor'}
            or p['schema_version'] != 'synthetic-raw-policy-v1' or p['synthetic_only'] is not True
            or p['promotion_allowed'] is not False or p['sector_guard'] is not False or p['crc_floor'] is not False
            or type(p['label_thresholds']) is not dict or list(p['label_thresholds']) != dataset.topic_ids
            or any(type(v) not in (int,float) or not math.isfinite(v) or not 0 <= v <= 1 for v in p['label_thresholds'].values())
            or type(seed) is not int or not 0 <= seed < 2**32):
        raise TrainingError('learning_training.synthetic_policy_invalid')
    if dataset.content_digest != dataset_content_digest(dataset):
        raise TrainingError('learning_training.dataset_mutated')
    if dataset.authorized_context is not None:
        from learning_case_features import build_export_dataset
        context = dataset.authorized_context
        rebuilt = build_export_dataset(context['bundle'], state=copy.deepcopy(context['state']),
                                       now=context['now'], trusted_local_launcher=True)
        if rebuilt.dataset_digest != dataset.dataset_digest or rebuilt.content_digest != dataset.content_digest:
            raise TrainingError('learning_training.export_snapshot_changed')
    if synthetic_search is not None and (type(synthetic_search) is not dict or
            synthetic_search != {'synthetic_only':True,'trials':2,'C':[0.1,1.0,10.0]}):
        raise TrainingError('learning_training.search_invalid')
    if split != split_dataset(dataset,**split.cutoffs) or not split.train:
        raise TrainingError('learning_training.split_invalid')
    _check_X(dataset.X)
    if dataset.X.index.tolist() != dataset.case_ids:
        raise TrainingError('learning_training.case_axis_invalid')
    values,masks = validate_matrices(dataset.values,dataset.masks,dataset.case_ids,dataset.topic_ids)
    pipelines = {}; status = {}; supports = {}; selected = {}; trial_count = 0
    for label in dataset.topic_ids:
        observed = [i for i in split.train if masks.loc[i,label] == 1]
        y = values.loc[observed,label].astype(int)
        supports[label] = dict(observed=len(observed),positive=int((y == 1).sum()),negative=int((y == 0).sum()))
        if not observed:
            status[label] = 'not_evaluable:no_observations'; continue
        if y.nunique() != 2:
            status[label] = 'not_evaluable:single_class'; continue
        C = 1.0
        if synthetic_search is not None:
            import optuna
            dates = sorted({parse_timestamp(dataset.metadata.loc[i,'recorded_at']) for i in observed})
            folds = []
            for date in dates:
                validation = [i for i in observed if parse_timestamp(dataset.metadata.loc[i,'recorded_at']) == date]
                groups = set(dataset.metadata.loc[validation,'company_group_key'])
                earlier = [i for i in observed if parse_timestamp(dataset.metadata.loc[i,'recorded_at']) < date
                           and dataset.metadata.loc[i,'company_group_key'] not in groups]
                if earlier and values.loc[earlier,label].nunique() == 2:
                    folds.append((earlier,validation))
            if not folds:
                raise TrainingError('learning_training.no_inner_folds')
            folds = folds[-2:]
            def objective(trial):
                candidate_C = trial.suggest_categorical('C', synthetic_search['C'])
                errors = []
                for inner_train, inner_validation in folds:
                    inner = _pipeline(seed, candidate_C)
                    inner.fit(dataset.X.loc[inner_train], values.loc[inner_train,label].astype(int))
                    prediction = inner.predict(dataset.X.loc[inner_validation])
                    errors.extend((prediction != values.loc[inner_validation,label].astype(int).to_numpy()).tolist())
                return sum(errors) / len(errors)
            study = optuna.create_study(direction='minimize', sampler=optuna.samplers.TPESampler(seed=seed))
            study.optimize(objective, n_trials=synthetic_search['trials'], timeout=5)
            trial_count += len(study.trials)
            C = study.best_params['C']
        selected[label] = C
        pipeline = _pipeline(seed, C)
        pipeline.fit(dataset.X.loc[observed],y)
        pipelines[label] = pipeline; status[label] = 'fitted'
    development = dict(X=dataset.X.loc[list(split.train)].to_dict('index'),
                       values=values.loc[list(split.train)].to_dict('index'),masks=masks.loc[list(split.train)].to_dict('index'),
                       schema=feature_schema(),policy=p,seed=seed,train=split.train)
    if synthetic_search is not None:
        development.update(search=synthetic_search, selected=selected,
                           metadata=dataset.metadata.loc[list(split.train)].to_dict('index'))
    return LearningCandidate(pipelines,status,supports,list(dataset.topic_ids),feature_schema(),
                             copy.deepcopy(p['label_thresholds']),copy.deepcopy(p),
                             {'python':platform.python_version(),**{key:version(key) for key in ('scikit-learn','pandas','numpy')}},
                             {'estimator':seed},dataset.dataset_digest,split.split_digest,digest(development),split.train,
                             f'optuna:inner_group_time:{trial_count}' if synthetic_search is not None else 'deferred:no_qualified_group_time_folds')


def _pipeline(seed, C):
    return Pipeline([
        ('preprocessor',ColumnTransformer([('cat',OneHotEncoder(handle_unknown='ignore'),list(FEATURE_COLUMNS))],remainder='drop')),
        ('classifier',LogisticRegression(C=C,random_state=seed,max_iter=1000,solver='liblinear'))])

def predict_raw(bundle, X):
    """Ordered raw labels/scores; unavailable estimators remain explicit None."""
    _check_X(X)
    if bundle.feature_schema != feature_schema() or bundle.promotion_allowed is not False:
        raise TrainingError('learning_training.bundle_invalid')
    labels = pd.DataFrame([[None for _ in bundle.topic_ids] for _ in X.index],index=X.index,columns=bundle.topic_ids,dtype=object)
    scores = labels.copy(deep=True)
    if X.empty:
        return labels,scores
    for label,pipeline in bundle.pipelines.items():
        probabilities = pipeline.predict_proba(X)[:,list(pipeline.named_steps['classifier'].classes_).index(1)]
        scores[label] = pd.Series([float(v) for v in probabilities],index=X.index,dtype=object)
        labels[label] = pd.Series([int(v >= bundle.thresholds[label]) for v in probabilities],index=X.index,dtype=object)
    return labels,scores
