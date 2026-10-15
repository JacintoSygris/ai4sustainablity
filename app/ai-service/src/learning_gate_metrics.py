"""Company-level gate metrics. No fitting or prediction here."""
import math
import os
import numpy as np

SCHEMA = 'learning-gate-metrics-v1'
DEFAULTS = dict(min_companies=30, min_positives=30, min_negatives=30,
                precision_delta=0.01, replicates=2000, confidence=0.95, seed=17)


def enabled():
    return os.getenv('LEARNING_GATE_METRICS_ENABLED', 'false') == 'true'


def policy(values=None):
    result = dict(DEFAULTS)
    if values is None:
        values = {k: os.environ['LEARNING_GATE_' + k.upper()] for k in result
                  if 'LEARNING_GATE_' + k.upper() in os.environ}
    if set(values) - set(result):
        raise ValueError('learning.gate_policy')
    for key, value in values.items():
        if isinstance(value, bool):
            raise ValueError('learning.gate_policy')
        number = float(value)
        if not math.isfinite(number):
            raise ValueError('learning.gate_policy')
        if isinstance(DEFAULTS[key], int):
            if number != int(number):
                raise ValueError('learning.gate_policy')
            number = int(number)
        result[key] = number
    for k in ('min_companies', 'min_positives', 'min_negatives'):
        if not 1 <= result[k] <= 1000000:
            raise ValueError('learning.gate_policy')
    if not (2 <= result['replicates'] <= 10000 and 0 < result['confidence'] < 1
            and 0 <= result['precision_delta'] < 1 and 0 <= result['seed'] <= 2**32 - 1):
        raise ValueError('learning.gate_policy')
    return result


def binary(values, axes):
    if type(values) is not dict or set(values) != set(axes) or any(type(v) is not int or v not in (0, 1) for v in values.values()):
        raise ValueError('learning.prediction_axes')


def ratios(counts):
    tp, fp, fn, _tn = np.moveaxis(np.asarray(counts, dtype=float), -1, 0)
    return np.stack([np.divide(n, d, out=np.full_like(d, np.nan), where=d != 0)
                     for n, d in ((tp, tp + fp), (tp, tp + fn), (2 * tp, 2 * tp + fp + fn))], axis=-1)


def interval(values, p):
    valid = values[np.isfinite(values)]
    ok = len(valid) >= math.ceil(p['replicates'] * .95)
    q = np.quantile(valid, [(1-p['confidence'])/2, (1+p['confidence'])/2], method='linear') if ok else [None, None]
    return dict(lower=q[0], upper=q[1], valid_replicates=len(valid),
                unavailable_reason=None if ok else 'insufficient_valid_replicates')


def measure(truths, predictions, axes, p):
    """Rows are distinct companies; paired draws keep their entire label vector."""
    p = policy(p)
    axes = sorted(axes)
    if not truths or set(predictions) != {'base', 'comparator', 'candidate'}:
        raise ValueError('learning.metrics_input')
    for truth in truths:
        if set(truth) != set(axes) or any(v is not None and (type(v) is not int or v not in (0, 1)) for v in truth.values()):
            raise ValueError('learning.truth_axes')
    y = np.array([[t[k] if t[k] is not None else -1 for k in axes] for t in truths])
    predictions = {name: predictions[name] for name in ('base', 'comparator', 'candidate')}
    counts = []
    for rows in predictions.values():
        if len(rows) != len(truths):
            raise ValueError('learning.metrics_rows')
        for row in rows:
            binary(row, axes)
        a = np.array([[r[k] for k in axes] for r in rows])
        counts.append(np.stack([(y == 1) & (a == 1), (y == 0) & (a == 1),
                                (y == 1) & (a == 0), (y == 0) & (a == 0)], axis=-1))
    counts = np.asarray(counts, dtype=np.int64)  # predictor, company, key, confusion
    totals = counts.sum(axis=1)
    rng = np.random.default_rng(p['seed'])
    # Bounded memory: one company draw at a time, never B*N*K cells.
    samples = np.empty((p['replicates'], 3, len(axes)+1, 3))
    for b in range(p['replicates']):
        draw = counts[:, rng.integers(len(y), size=len(y))].sum(axis=1)
        samples[b] = ratios(np.concatenate([draw.sum(axis=1, keepdims=True), draw], axis=1))
    def support(column):
        return {name: int((column == value).sum()) for name, value in
                [('positive_support', 1), ('negative_support', 0), ('unknown_support', -1)]}
    def metrics(total, draws):
        result = dict(zip(('tp', 'fp', 'fn', 'tn'), map(int, total)))
        for i, name in enumerate(('precision', 'recall', 'f1')):
            value = ratios(total)[i]
            result[name] = dict(value=float(value) if np.isfinite(value) else None,
                                unavailable_reason=None if np.isfinite(value) else 'zero_denominator',
                                ci=interval(draws[:, i], p))
        return result
    def section(index, column):
        values = totals.sum(axis=1) if index == 0 else totals[:, index-1]
        return {**support(column), 'predictors': {name: metrics(values[i], samples[:, i, index])
                for i, name in enumerate(predictions)},
                'paired_delta': {name: dict(value=(float(ratios(values[2])[i] - ratios(values[1])[i])
                    if np.isfinite(ratios(values[2])[i] - ratios(values[1])[i]) else None),
                    ci=interval(samples[:, 2, index, i] - samples[:, 1, index, i], p))
                    for i, name in enumerate(('precision', 'recall', 'f1'))}}
    return dict(companies=len(y), micro=section(0, y),
                per_topic={key: section(i+1, y[:, i]) for i, key in enumerate(axes)})


def improvement(report, p):
    m = report['micro']
    lower = m['paired_delta']['precision']['ci']['lower']
    sufficient = (report['companies'] >= p['min_companies'] and m['positive_support'] >= p['min_positives']
                  and m['negative_support'] >= p['min_negatives'] and lower is not None
                  and all(m['predictors'][n]['precision']['value'] is not None for n in ('comparator', 'candidate')))
    passed = sufficient and lower > p['precision_delta']
    return dict(endpoint='heldout.keys.micro.precision', threshold=p['precision_delta'],
                passed=bool(passed), reason=None if passed else ('improvement_not_demonstrated' if sufficient else 'insufficient_evidence'))


def administrative_gate(gate, detail=False):
    """Allowlisted aggregate projection: never return arbitrary manifest fields."""
    import re
    def only(obj, keys):
        return {k: obj[k] for k in keys if k in obj}
    result = only(gate, ('passed', 'rule', 'gold_oos', 'fixed_sha256', 'baseline_identity'))
    result['sets'] = [only(s, ('name', 'unit', 'cases', 'positive_support', 'served_true_positives',
                     'candidate_true_positives', 'lost_true_positives', 'served_recall', 'candidate_recall')) for s in gate['sets']]
    if gate.get('schema') != SCHEMA:
        return result
    result.update(only(gate, ('schema', 'protocol_sha256', 'zero_positive_loss', 'primary', 'policy', 'failure_reasons')))
    def metric(m):
        return {**only(m, ('value', 'unavailable_reason')), 'ci': only(m['ci'], ('lower', 'upper', 'valid_replicates', 'unavailable_reason'))}
    def section(s):
        return {**only(s, ('positive_support', 'negative_support', 'unknown_support')),
                'predictors': {n: {**only(s['predictors'][n], ('tp', 'fp', 'fn', 'tn')),
                    **{k: metric(s['predictors'][n][k]) for k in ('precision', 'recall', 'f1')}}
                    for n in ('base', 'comparator', 'candidate')},
                'paired_delta': {k: metric(s['paired_delta'][k]) for k in ('precision', 'recall', 'f1')}}
    result['report'] = {}
    for name, r in gate['report'].items():
        if name not in ('heldout', 'gold_oos'):
            raise ValueError('learning.gate_set')
        result['report'][name] = dict(unit=r['unit'], companies=r['companies'], micro=section(r['micro']))
        if detail:
            if len(r['per_topic']) > 256 or any(not re.fullmatch(r'(?:esrs_[a-z0-9_]+|[ESG][0-9]?)', k) for k in r['per_topic']):
                raise ValueError('learning.gate_axes')
            result['report'][name]['per_topic'] = {k: section(v) for k, v in r['per_topic'].items()}
    validate_contract(result, 'administrative_projection')
    return result


def validate_contract(document, kind):
    """The isolated learning environment already pins jsonschema; no new dependency."""
    import json
    from pathlib import Path
    from jsonschema import Draft202012Validator
    def schema():
        return json.loads((Path(__file__).resolve().parents[2] / 'contracts/api/learning-gate-metrics-v1.schema.json').read_text())
    contract = schema()
    Draft202012Validator({'$ref': '#/$defs/' + kind, '$defs': contract['$defs']}).validate(document)
    # jsonschema permits IEEE non-finite floats; the subprocess protocol doesn't.
    json.dumps(document, allow_nan=False)
