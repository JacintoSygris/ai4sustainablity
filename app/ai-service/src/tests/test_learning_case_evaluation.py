import copy
import unittest
import json
import warnings
from dataclasses import replace
import pandas as pd
from learning_case_01b_synthetic_helper import fixture, CUTOFFS
from learning_case_synthetic_helper import digest, seal
from learning_case_features import build_dataset, FeatureError, FEATURE_COLUMNS
from learning_case_training import split_dataset, TrainingError, train_candidate, predict_raw
import numpy as np
from sklearn.pipeline import Pipeline
from sklearn.linear_model import LogisticRegression
from learning_case_evaluation import evaluate_raw, EvaluationError

def policy():
    return dict(schema_version='synthetic-raw-policy-v1',synthetic_only=True,promotion_allowed=False,
                label_thresholds={'101':0.5,'102':0.5,'103':0.5},sector_guard=False,crc_floor=False)


class FeatureTests(unittest.TestCase):
    def test_snapshot_allowlist_axes_and_no_mutation(self):
        records = fixture(); before = copy.deepcopy(records)
        d = build_dataset(records, ['101','102','103'])
        self.assertEqual(records, before)
        self.assertEqual(d.X.columns.tolist(), list(FEATURE_COLUMNS))
        self.assertEqual(d.X.index.tolist(), d.case_ids)
        self.assertIsNone(d.values.iloc[0,2]); self.assertEqual(d.masks.iloc[0,2],0)
        self.assertEqual(d.metadata.columns.tolist(), ['company_group_key','recorded_at','source_kind'])
        d.X.iloc[0,0] = 'CHANGED'; self.assertEqual(records,before)

    def test_closed_envelope_rejects_leakage_and_mismatches(self):
        for mutation in ['notes','case_id','group','p8','extra','digest','hash','missing','policy','version','nan']:
            r = fixture()
            if mutation in ['notes','case_id','group','p8']: r[0]['features']['values'][mutation] = 'LEAK'
            if mutation == 'extra': r[0]['features']['live_lookup'] = True
            if mutation == 'digest': r[0]['features']['snapshot_digest'] = 'a'*64
            if mutation == 'hash': r[0]['features']['case_hash'] = 'b'*64
            if mutation == 'missing': r[0]['features']['values'].pop('stock_listed')
            if mutation == 'policy': r[0]['promotion_allowed'] = True
            if mutation == 'version': r[0]['features']['transform_version'] = 'unknown'
            if mutation == 'nan': r[0]['features']['values']['stock_listed'] = float('nan')
            with self.subTest(mutation=mutation), self.assertRaises(FeatureError): build_dataset(r,['101','102','103'])

    def test_duplicate_cases_and_unmasked_null_rejected(self):
        for kind in ['duplicate','null']:
            r = fixture()
            if kind == 'duplicate': r.append(copy.deepcopy(r[0]))
            else:
                r[0]['case']['topic_labels'][0]['value'] = None
                seal(r[0]['case']); r[0]['features']['case_hash'] = r[0]['case']['case_hash']
            with self.assertRaises(ValueError): build_dataset(r,['101','102','103'])


class SplitTests(unittest.TestCase):
    def test_groups_dates_and_sources_never_cross_partitions(self):
        r = fixture(); duplicate = copy.deepcopy(r[0]); c = duplicate['case']
        c['case_id'] = 'synthetic-other-source'; c['provenance']['source_kind'] = 'human_product'
        c['topic_labels'][2] = dict(topic_id='103',value=0,observed_mask=1)
        seal(c); duplicate['features']['case_id'] = c['case_id']; duplicate['features']['case_hash'] = c['case_hash']
        r.append(duplicate); d = build_dataset(r,['101','102','103']); s = split_dataset(d,**CUTOFFS)
        self.assertEqual(len(s.train),13); self.assertEqual(len(s.calibration),4); self.assertEqual(len(s.holdout),4)
        self.assertIn(c['case_id'],s.train)
        group_sets = [set(d.metadata.loc[list(ids),'company_group_key']) for ids in [s.train,s.calibration,s.holdout]]
        for a,b in [(0,1),(0,2),(1,2)]: self.assertFalse(group_sets[a] & group_sets[b])

    def test_cross_time_group_and_future_are_excluded(self):
        d = build_dataset(fixture(),['101','102','103'])
        d.metadata.loc['synthetic-holdout-0','company_group_key'] = d.metadata.iloc[0]['company_group_key']
        d.metadata.loc['synthetic-train-1','recorded_at'] = '2026-01-01T00:00:00Z'
        s = split_dataset(d,**CUTOFFS)
        self.assertEqual(s.excluded['synthetic-train-0'],'group_crosses_time_window')
        self.assertEqual(s.excluded['synthetic-holdout-0'],'group_crosses_time_window')
        self.assertEqual(s.excluded['synthetic-train-1'],'future_group')
        self.assertNotIn('synthetic-train-1',s.train)

    def test_boundaries_invalid_cutoffs_and_metadata_rejected(self):
        d = build_dataset(fixture(),['101','102','103'])
        d.metadata.loc['synthetic-train-0','recorded_at'] = CUTOFFS['train_end']
        s = split_dataset(d,**CUTOFFS); self.assertIn('synthetic-train-0',s.calibration)
        with self.assertRaises(TrainingError): split_dataset(d,**(CUTOFFS | dict(train_end=CUTOFFS['holdout_end'])))
        d.metadata.loc['synthetic-train-0','company_group_key'] = None
        with self.assertRaises(TrainingError): split_dataset(d,**CUTOFFS)


class TrainingTests(unittest.TestCase):
    def test_pinned_sklearn_scipy_fit_without_optimizer_warnings(self):
        with warnings.catch_warnings():
            warnings.simplefilter('error')
            self.candidate()

    def candidate(self, records=None):
        d = build_dataset(records or fixture(),['101','102','103'])
        return d, train_candidate(d,split_dataset(d,**CUTOFFS),synthetic_policy=policy(),seed=42)

    def test_real_observed_label_pipeline_unseen_group_variable_predictions(self):
        d,b = self.candidate()
        self.assertEqual(b.label_status['103'],'not_evaluable:no_observations')
        self.assertNotIn('103',b.pipelines)
        self.assertEqual(b.supports['101'],{'observed':12,'positive':6,'negative':6})
        self.assertIsInstance(b.pipelines['101'],Pipeline)
        self.assertIsInstance(b.pipelines['101'].named_steps['classifier'],LogisticRegression)
        p,scores = predict_raw(b,d.X.loc[list(split_dataset(d,**CUTOFFS).holdout)])
        self.assertEqual(p['101'].tolist(),[0,1,0,1]); self.assertEqual(p['102'].tolist(),[1,0,1,0])
        self.assertTrue(p['103'].isna().all()); self.assertTrue(scores['103'].isna().all())
        self.assertIsNone(p.iloc[0,2]); self.assertIsNone(scores.iloc[0,2])
        self.assertTrue(np.isfinite(scores[['101','102']].to_numpy(dtype=float)).all())
        self.assertEqual(b.dependency_versions['scikit-learn'],'1.7.0')
        self.assertEqual(b.topic_ids,['101','102','103'])
        self.assertFalse(b.promotion_allowed); self.assertEqual(b.tuning_status,'deferred:no_qualified_group_time_folds')

    def test_holdout_only_perturbation_cannot_change_fit_vocab_seed_thresholds(self):
        d,b = self.candidate(); r = fixture()
        for item in r[16:]:
            c = item['case']; f = item['features']; f['values']['headquarters_country'] = 'HOLDOUT_PERTURBED'
            f['values']['stock_listed'] = not f['values']['stock_listed']
            for t in c['topic_labels'][:2]: t['value'] = 1-t['value']
            c['p5_snapshot']['digest'] = digest(f['values']); seal(c)
            f['case_hash'] = c['case_hash']; f['snapshot_digest'] = c['p5_snapshot']['digest']
        changed,other = self.candidate(r)
        self.assertNotEqual(b.dataset_digest,other.dataset_digest)
        for attr in ['development_digest','split_digest','seeds','thresholds','feature_schema','supports']:
            self.assertEqual(getattr(b,attr),getattr(other,attr))
        for label in b.pipelines:
            a = b.pipelines[label]; z = other.pipelines[label]
            np.testing.assert_array_equal(a.named_steps['classifier'].coef_,z.named_steps['classifier'].coef_)
            np.testing.assert_array_equal(a.named_steps['classifier'].intercept_,z.named_steps['classifier'].intercept_)
            for x,y in zip(a.named_steps['preprocessor'].named_transformers_['cat'].categories_,
                           z.named_steps['preprocessor'].named_transformers_['cat'].categories_):
                np.testing.assert_array_equal(x,y)
                self.assertNotIn('UNSEEN',x); self.assertNotIn('HOLDOUT_PERTURBED',x)

    def test_mask_zero_rows_do_not_fit_label_vocab_or_create_constant_model(self):
        r = fixture()
        for i,item in enumerate(r[:12]):
            c=item['case']; c['topic_labels'][1] = dict(topic_id='102',value=1,observed_mask=1)
            if i == 0:
                c['topic_labels'][0] = dict(topic_id='101',value=None,observed_mask=0)
                item['features']['values']['headquarters_country'] = 'MASKED_ONLY'
            c['p5_snapshot']['digest'] = digest(item['features']['values']); seal(c)
            item['features']['case_hash'] = c['case_hash']; item['features']['snapshot_digest'] = c['p5_snapshot']['digest']
        d,b=self.candidate(r)
        self.assertEqual(b.supports['101']['observed'],11)
        self.assertEqual(b.label_status['102'],'not_evaluable:single_class'); self.assertNotIn('102',b.pipelines)
        self.assertNotIn('MASKED_ONLY',b.pipelines['101'].named_steps['preprocessor'].named_transformers_['cat'].categories_[0])

    def test_invalid_policy_axes_and_split_rejected(self):
        d=build_dataset(fixture(),['101','102','103']); s=split_dataset(d,**CUTOFFS)
        for bad in [policy() | dict(promotion_allowed=True),policy() | dict(label_thresholds={'101':float('nan')}),{}]:
            with self.assertRaises(TrainingError): train_candidate(d,s,synthetic_policy=bad,seed=42)
        b=self.candidate()[1]
        with self.assertRaises(TrainingError): predict_raw(b,d.X.assign(notes='LEAK'))
        with self.assertRaises(TrainingError): predict_raw(b,d.X[list(reversed(d.X.columns))])

    def test_mutated_snapshot_matrix_or_forged_split_cannot_train(self):
        d=build_dataset(fixture(),['101','102','103']); s=split_dataset(d,**CUTOFFS)
        forged=replace(s,train=s.train+s.holdout,holdout=())
        with self.assertRaises(TrainingError): train_candidate(d,forged,synthetic_policy=policy(),seed=42)
        for component in ['X','values']:
            d=build_dataset(fixture(),['101','102','103']); s=split_dataset(d,**CUTOFFS)
            if component=='X': d.X.iloc[0,0]='TAMPERED'
            else: d.values.iloc[0,0]=1
            with self.subTest(component=component),self.assertRaises(TrainingError):
                train_candidate(d,s,synthetic_policy=policy(),seed=42)


class EvaluationTests(unittest.TestCase):
    def matrices(self):
        ids=['a','b','c','d']; topics=['101','103']
        y=pd.DataFrame([[1,None],[1,None],[0,None],[None,None]],index=ids,columns=topics,dtype=object)
        m=pd.DataFrame([[1,0],[1,0],[1,0],[0,0]],index=ids,columns=topics,dtype=object)
        p=pd.DataFrame([[1,None],[0,None],[1,None],[1,None]],index=ids,columns=topics,dtype=object)
        return y,m,p,ids,topics,{'101':'fitted','103':'not_evaluable:no_observations'}

    def test_masked_tp_fn_fp_zero_support_null_and_no_nan(self):
        result=evaluate_raw(*self.matrices())
        a=result['labels']['101']; self.assertEqual((a['tp'],a['fn'],a['fp'],a['support']),(1,1,1,3))
        self.assertEqual(a['recall'],0.5); self.assertEqual(a['precision'],0.5)
        z=result['labels']['103']; self.assertEqual(z['support'],0); self.assertIsNone(z['recall'])
        self.assertIsNone(z['precision']); self.assertIsNone(z['tp']); self.assertEqual(z['status'],'not_evaluable')
        json.dumps(result,allow_nan=False)

    def test_unknowns_cannot_be_negatives_nan_bool_float_or_reordered_axes(self):
        for mode in ['fake_negative','nan_truth','nan_prediction','bool','float','axis','missing_prediction']:
            y,m,p,ids,topics,status=self.matrices()
            if mode=='fake_negative': y.loc['d','101']=0
            if mode=='nan_truth': y.loc['a','101']=float('nan')
            if mode=='nan_prediction': p.loc['a','101']=float('nan')
            if mode=='bool': p.loc['a','101']=True
            if mode=='float': p.loc['a','101']=1.0
            if mode=='axis': p=p[['103','101']]
            if mode=='missing_prediction': p.loc['a','101']=None
            with self.subTest(mode=mode),self.assertRaises(ValueError): evaluate_raw(y,m,p,ids,topics,status)

    def test_zero_positive_and_zero_proposed_denominators_are_null(self):
        y,m,p,ids,topics,status=self.matrices()
        y.loc[['a','b','c'],'101']=0; p['101']=pd.Series([0,0,0,0],index=ids,dtype=object)
        a=evaluate_raw(y,m,p,ids,topics,status)['labels']['101']
        self.assertIsNone(a['recall']); self.assertIsNone(a['precision']); self.assertEqual(a['support'],3)

    def test_real_holdout_metrics_use_only_observed_raw_predictions(self):
        d=build_dataset(fixture(),['101','102','103']); split=split_dataset(d,**CUTOFFS)
        b=train_candidate(d,split,synthetic_policy=policy(),seed=42); ids=list(split.holdout)
        p,_=predict_raw(b,d.X.loc[ids])
        result=evaluate_raw(d.values.loc[ids],d.masks.loc[ids],p,ids,d.topic_ids,b.label_status)
        self.assertEqual(result['aggregate']['support'],8)
        self.assertEqual(result['aggregate']['tp'],4); self.assertEqual(result['aggregate']['fn'],0)
        self.assertEqual(result['labels']['103']['status'],'not_evaluable')
