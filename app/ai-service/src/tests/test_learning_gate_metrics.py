"""Synthetic arithmetic fixtures; no real quality evidence."""
import itertools
import unittest
import numpy as np
from learning_gate_metrics import measure, policy, improvement, interval


class MetricsTests(unittest.TestCase):
    def measured(self, y, base, candidate=None, **options):
        return measure(y, dict(base=base, comparator=base, candidate=candidate or base), y[0], policy(options))

    def test_confusion_unknown_and_undefined(self):
        y = [dict(a=1, b=0, c=None), dict(a=1, b=0, c=None)]
        a = [dict(a=1, b=1, c=1), dict(a=0, b=0, c=0)]
        r = self.measured(y, a, replicates=100)
        m = r['micro']['predictors']['base']
        self.assertEqual([m[k] for k in ('tp','fp','fn','tn')], [1,1,1,1])
        self.assertEqual([m[k]['value'] for k in ('precision','recall','f1')], [.5,.5,.5])
        self.assertEqual(r['micro']['unknown_support'], 2)
        for k in ('precision','recall','f1'):
            self.assertIsNone(r['per_topic']['c']['predictors']['base'][k]['value'])
            self.assertEqual(r['per_topic']['c']['predictors']['base'][k]['ci']['valid_replicates'], 0)

    def test_paired_company_bootstrap_independent_enumeration(self):
        y = [dict(a=1,b=0), dict(a=0,b=1)]
        base = [dict(a=1,b=1), dict(a=1,b=0)]
        candidate = [dict(a=1,b=0), dict(a=1,b=1)]
        p = policy(dict(replicates=100, seed=17))
        r = self.measured(y, base, candidate, **p)
        # Enumerate every possible company resample, calculating directly from labels.
        exact = {}
        for sample in itertools.product(range(2), repeat=2):
            values = []
            for predicted in (base, candidate):
                tp = sum(y[i][k] == 1 and predicted[i][k] == 1 for i in sample for k in ('a','b'))
                fp = sum(y[i][k] == 0 and predicted[i][k] == 1 for i in sample for k in ('a','b'))
                values.append(tp/(tp+fp) if tp+fp else np.nan)
            exact[sample] = values[1]-values[0]
        rng = np.random.default_rng(17)
        deltas = np.array([exact[tuple(rng.integers(2,size=2))] for _ in range(100)])
        self.assertEqual(r['micro']['paired_delta']['precision']['ci'], interval(deltas,p))
        self.assertEqual(r, self.measured(y, base, candidate, **p))

    def test_policy_validation(self):
        for bad in [dict(seed=-1),dict(replicates=1),dict(confidence=1),dict(precision_delta=float('nan')),
                    dict(min_companies=0),dict(seed=1.5),dict(seed=True),dict(extra=1)]:
            with self.assertRaises(ValueError): policy(bad)

    def test_tie_false_positives_support_and_strict_boundary(self):
        y = [dict(a=1,b=0)]*30
        base = [dict(a=1,b=1)]*30
        better = [dict(a=1,b=0)]*30
        p = policy(dict(replicates=100))
        self.assertFalse(improvement(self.measured(y,base,**p),p)['passed'])
        self.assertFalse(improvement(self.measured(y,better,base,**p),p)['passed'])
        r = self.measured(y,base,better,**p)
        self.assertTrue(improvement(r,p)['passed'])
        r['micro']['paired_delta']['precision']['ci']['lower'] = .01
        self.assertFalse(improvement(r,p)['passed'])
        r['companies']=29
        self.assertEqual(improvement(r,p)['reason'],'insufficient_evidence')

    def test_malformed_predictions_fail_closed(self):
        for value in [None,True,2,'1']:
            with self.assertRaises(ValueError): self.measured([dict(a=1)], [dict(a=value)],replicates=2)
        with self.assertRaises(ValueError): self.measured([dict(a=True)], [dict(a=1)],replicates=2)

    def test_valid_replicate_floor_is_fixed_without_redrawing(self):
        p=policy(dict(replicates=100))
        self.assertIsNone(interval(np.array([1.0]*94+[np.nan]*6),p)['lower'])
        self.assertEqual(interval(np.array([1.0]*95+[np.nan]*5),p)['lower'],1.0)
