"""Native T09 synthetic transport and inner training regressions."""
import copy
import json
from pathlib import Path
from datetime import datetime
import unittest
import numpy as np
from learning_case_features import build_export_dataset, dataset_content_digest, FeatureError
from learning_case_training import split_dataset, train_candidate, predict_raw, TrainingError
from learning_case_evaluation import evaluate_raw


def native_request():
    from learning_portable_transport_helper import request
    return request()


def native_dataset():
    request=native_request(); state=copy.deepcopy(request['state'])
    dataset=build_export_dataset(request['bundle'],state=state,
        now=datetime.fromisoformat(json.loads(request['bundle']['manifest_json'])['issued_at']),trusted_local_launcher=True)
    return request,dataset,state


def candidate(dataset,cutoffs):
    policy=dict(schema_version='synthetic-raw-policy-v1',synthetic_only=True,promotion_allowed=False,
        label_thresholds={t:0.5 for t in dataset.topic_ids},sector_guard=False,crc_floor=False)
    split=split_dataset(dataset,**cutoffs)
    return split,train_candidate(dataset,split,synthetic_policy=policy,seed=42,
        synthetic_search={'synthetic_only':True,'trials':2,'C':[0.1,1.0,10.0]})


class BatchTests(unittest.TestCase):
    def test_native_bridge_and_real_inner_fit_raw_masks(self):
        request,dataset,state=native_dataset(); split,model=candidate(dataset,request['cutoffs'])
        self.assertGreater(len(model.pipelines),0)
        self.assertGreater(int(model.tuning_status.rsplit(':',1)[1]),0)
        self.assertEqual(dataset.X.columns.tolist(),['headquarters_country','employee_count_range','stock_listed'])
        self.assertEqual(dataset.metadata['recorded_at'].tolist(),[
            json.loads(line)['receipt']['recorded_at'] for line in sorted(request['bundle']['jsonl'].splitlines(),key=lambda l:json.loads(l)['reference']['case_id'])])
        ids=list(split.holdout); prediction,_=predict_raw(model,dataset.X.loc[ids])
        self.assertTrue(prediction['3'].isna().all())
        result=evaluate_raw(dataset.values.loc[ids],dataset.masks.loc[ids],prediction,ids,dataset.topic_ids,model.label_status)
        self.assertEqual(result['labels']['3']['status'],'not_evaluable')
        self.assertFalse(model.promotion_allowed)

    def test_default_bridge_and_mutated_native_snapshot_deny(self):
        request,dataset,state=native_dataset()
        with self.assertRaises(FeatureError):
            build_export_dataset(request['bundle'],state={},now=datetime.now())
        dataset.X.iloc[0,0]='CHANGED'
        # Even replacing the derived content seal cannot replace the native receipt.
        dataset.content_digest=dataset_content_digest(dataset)
        with self.assertRaises(TrainingError): candidate(dataset,request['cutoffs'])

    def test_outer_holdout_perturbation_invariant_inner_choice_and_fit(self):
        from learning_case_01b_synthetic_helper import fixture,CUTOFFS
        from learning_case_features import build_dataset
        from learning_case_synthetic_helper import digest,seal
        records=fixture()
        # Explicit synthetic training dates qualify temporal inner folds.
        for i,r in enumerate(records[:12]):
            r['case']['closure_evidence']['recorded_at']=f'2025-01-{i+1:02d}T00:00:00Z'
            seal(r['case']);r['features']['case_hash']=r['case']['case_hash']
        original=build_dataset(records,['101','102','103']);split,a=candidate(original,CUTOFFS)
        for r in records[16:]:
            r['features']['values']['headquarters_country']='HOLDOUT_ONLY'
            for label in r['case']['topic_labels'][:2]: label['value']=1-label['value']
            r['case']['p5_snapshot']['digest']=digest(r['features']['values']);seal(r['case'])
            r['features']['case_hash']=r['case']['case_hash'];r['features']['snapshot_digest']=r['case']['p5_snapshot']['digest']
        _,b=candidate(build_dataset(records,['101','102','103']),CUTOFFS)
        self.assertEqual(a.development_digest,b.development_digest)
        for label in a.pipelines:
            x,y=a.pipelines[label],b.pipelines[label]
            self.assertEqual(x.named_steps['classifier'].C,y.named_steps['classifier'].C)
            np.testing.assert_array_equal(x.named_steps['classifier'].coef_,y.named_steps['classifier'].coef_)
            for p,q in zip(x.named_steps['preprocessor'].named_transformers_['cat'].categories_,y.named_steps['preprocessor'].named_transformers_['cat'].categories_):
                np.testing.assert_array_equal(p,q)

    def test_native_script_default_off_does_not_read_stdin(self):
        import subprocess,os
        root=Path(__file__).resolve().parents[2]
        env=dict(os.environ);env.pop('I4S_BATCH_SYNTHETIC_CAPABILITY',None)
        import sys
        child=root/'scripts/learning-case-batch.py'
        self.assertTrue(child.is_file())
        result=subprocess.run([sys.executable,'-B',str(child)],input=b'not-json',capture_output=True,env=env,timeout=5)
        self.assertEqual(result.returncode,2);self.assertEqual(result.stdout,b'')
        self.assertNotIn(b'No such file',result.stderr);self.assertNotIn(b'cannot open',result.stderr)
    def test_repair_script_production_scope_inert_before_input(self):
        import importlib.util, os
        from unittest.mock import patch
        path=Path(__file__).resolve().parents[2]/'scripts/learning-case-batch.py'
        spec=importlib.util.spec_from_file_location('t09_repair_script',path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        class Forbidden:
            @property
            def buffer(self): raise AssertionError('input must not be read')
        for env in [dict(APP_ENV='production',I4S_BATCH_SYNTHETIC_CAPABILITY='test-namespace:t07-export'),
                    dict(APP_ENV='testing',I4S_BATCH_SYNTHETIC_CAPABILITY='invalid')]:
            with patch.dict(os.environ,env),patch.object(module.sys,'stdin',Forbidden()):
                self.assertEqual(module.main(),2)

    def test_repair_refresh_namespace_and_message_bounds(self):
        import importlib.util, os
        from unittest.mock import patch
        path=Path(__file__).resolve().parents[2]/'scripts/learning-case-batch.py'
        spec=importlib.util.spec_from_file_location('t09_repair_refresh',path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with patch.dict(os.environ,{'I4S_BATCH_REFRESH_PATH':str(path)}):
            with self.assertRaisesRegex(ValueError,'refresh_path_invalid'): module.read_refresh('a'*32)
        for raw in [b'x'*1048577,b'{}']:
            with self.assertRaisesRegex(ValueError,'input_limit'): module.decode_message(raw)

    def test_g12_witness_preserves_t08_selective_dedupe_and_quarantine(self):
        import importlib.util
        from tests.test_learning_case_dataset import t08_variant_bundle, t08_advance, t08_build
        path=Path(__file__).resolve().parents[2]/'scripts/learning-case-batch.py'
        spec=importlib.util.spec_from_file_location('t09_g12_witness',path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        for mode, count, allowed in [('identical',1,True),('conflict',0,False)]:
            with self.subTest(mode=mode):
                bundle=t08_variant_bundle(mode);fresh=t08_advance(bundle,2)
                projection=t08_build(bundle,{})
                witness=module.canonical_witness(dict(bundle=bundle,state={},refresh=fresh))
                self.assertEqual(len(json.loads(bundle['bindings_json'])['cases']),2)
                self.assertEqual(len(witness['state']['lineage'][witness['dataset_digest']]),count)
                self.assertEqual(witness['dataset_digest'],projection['dataset_digest'])
                self.assertEqual(witness['state']['lineage'][witness['dataset_digest']],projection['lineage'])
                self.assertEqual(witness['consumable'],allowed)

    def test_t10_actual_tuned_package_fresh_process(self):
        import uuid, subprocess, sys, os
        from learning_candidate_registry import LocalCandidateRegistry, ALLOWED_ROOT, _representation, digest
        request,dataset,state=native_dataset(); split,model=candidate(dataset,request['cutoffs'])
        binding=dict(token=request['token'],context_digest=request['context_digest'],
            authority_generation=json.loads(request['bundle']['manifest_json'])['generation'],
            authority_digest=json.loads(request['bundle']['manifest_json'])['canonical_digest'],
            mapping=dict(version='t10-explicit-fixture-v1',namespace='test-namespace:t10-synthetic-shadow',
                topic_ids=dataset.topic_ids,filter_keys=['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],approved_common_axis=False))
        registry=LocalCandidateRegistry(ALLOWED_ROOT/('01c-t10-'+uuid.uuid4().hex),case_binding=binding)
        receipt=registry.register_case(model,dataset,split,binding)
        loaded=registry.load_exact(receipt)
        self.assertEqual(digest(_representation(loaded)),digest(_representation(model)))
        a,b=predict_raw(model,dataset.X.loc[list(split.holdout)])
        c,d=predict_raw(loaded,dataset.X.loc[list(split.holdout)])
        self.assertEqual(a.to_dict(),c.to_dict()); self.assertEqual(b.to_dict(),d.to_dict())
        code='import json,sys; from learning_candidate_registry import LocalCandidateRegistry,_representation,digest; r=LocalCandidateRegistry(sys.argv[1],case_binding=json.loads(sys.argv[3])); print(digest(_representation(r.load_exact(json.loads(sys.argv[2])))))'
        cold=subprocess.run([sys.executable,'-B','-c',code,str(registry.root),json.dumps(receipt),json.dumps(binding)],capture_output=True,text=True,timeout=20,env=dict(os.environ))
        self.assertEqual(cold.returncode,0,cold.stderr)
        self.assertEqual(cold.stdout.strip(),digest(_representation(model)))
