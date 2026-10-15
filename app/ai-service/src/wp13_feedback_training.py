"""Supervised production feedback trainer and inference overlay (public layout).

One masked RandomForest per approved singleton key; unobserved cells stay out of
training and untrained keys keep the base model output. Inference threshold 0.5.
Training, evaluation and promotion are driven only by learning_production_cli.
"""
import wp13_feedback_bridge as bridge

RF = {'n_estimators': 100, 'random_state': 42, 'n_jobs': 1}


class ProductionFeedbackModel:
    """Masked RF overlay: unobserved/single-class keys keep the baseline output.

    No P9 decision is converted into a materiality label or reporting obligation.
    P9 is retained in the data fingerprint and explicit support counts only.
    """
    def __init__(self, vectorizer, estimator, keys):
        self.vectorizer, self.estimator, self.keys = vectorizer, estimator, keys

    def predict(self, company, baseline):
        result = dict(baseline)
        values = self.estimator.predict(self.vectorizer.transform([production_features(company)]).toarray())[0]
        result.update({key: int(value) for key, value in zip(self.keys, values)})
        return result


def production_features(company):
    """Single training/inference encoder; no identity or output in features."""
    from learning_serving_adapter import company_type
    CompanyData = company_type()
    c = company if isinstance(company, CompanyData) else CompanyData.model_validate(company)
    result = {key: getattr(c, key) for key in (
        'headquarters_country', 'employees_total', 'num_subsidiaries_countries',
        'annual_turnover_million_euro', 'stock_listed', 'reporting_currency')}
    for key in ('sector_list', 'subsidiaries_regions', 'products_services'):
        for value in getattr(c, key):
            result[key + ':' + value] = 1
    # Optional inputs have identical handling on both sides.
    for key in ('company_size',):
        result[key] = getattr(c, key) or 'unavailable'
    import math
    bridge.require(all(not isinstance(v, float) or math.isfinite(v) for v in result.values()))
    return result


def train_production_feedback(document, mapping_document, *, synthetic_only=True):
    import numpy as np
    from sklearn.feature_extraction import DictVectorizer
    from sklearn.ensemble import RandomForestClassifier
    from train.masked_multilabel import MaskedPerLabelClassifier
    from threadpoolctl import threadpool_limits
    rows, axes = bridge.production_rows(document, mapping_document, synthetic_only=synthetic_only)
    keys, labels, masks = [], [], []
    for key, topics in sorted(axes.items()):
        # Ambiguous multi-topic keys cannot establish child labels safely.
        if len(topics) != 1 or key.endswith('_summary'):
            continue
        cells = [r['topics'].get(topics[0], {'mask': 0, 'value': None}) for r in rows]
        observed = [c['value'] for c in cells if c['mask'] == 1]
        if set(observed) != {0, 1}:
            continue
        keys.append(key)
        labels.append([c['value'] if c['mask'] else 0 for c in cells])
        masks.append([c['mask'] for c in cells])
    bridge.require(bool(keys))
    vectorizer = DictVectorizer()
    x = vectorizer.fit_transform([production_features(r['input']) for r in rows]).toarray()
    estimator = MaskedPerLabelClassifier(RandomForestClassifier(**RF))
    with threadpool_limits(limits=1):
        estimator.fit(x, np.asarray(labels).T, np.asarray(masks).T)
    model = ProductionFeedbackModel(vectorizer, estimator, keys)
    return model, {'training_rows': len(rows), 'trained_keys': keys,
                   'known_cells': int(np.asarray(masks).sum()),
                   'unknown_cells_excluded': int(np.asarray(masks).size - np.asarray(masks).sum()),
                   'p9_review_decisions': sum(len(r['p9']) for r in rows),
                   'p9_use': 'provenance_only_never_materiality_or_obligation_labels',
                   'estimator': RF, 'threshold': .5}
