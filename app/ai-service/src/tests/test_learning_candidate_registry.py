import copy
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
import uuid
import hashlib
from types import SimpleNamespace

from learning_case_01b_synthetic_helper import fixture, CUTOFFS
from learning_case_features import build_dataset
from learning_case_training import split_dataset, train_candidate
from test_learning_case_evaluation import policy
from learning_candidate_registry import LocalCandidateRegistry, RegistryError

from learning_candidate_registry import ALLOWED_ROOT
ROOT = ALLOWED_ROOT

def fitted():
    dataset = build_dataset(fixture(), ['101','102','103'])
    split = split_dataset(dataset, **CUTOFFS)
    return dataset, split, train_candidate(dataset, split, synthetic_policy=policy(), seed=42)

class RegistryTests(unittest.TestCase):
    def setUp(self):
        self.root = ROOT / ('01c-r1-test-' + uuid.uuid4().hex)
        self.registry = LocalCandidateRegistry(self.root)
        self.dataset, self.split, self.candidate = fitted()

    def register(self):
        return self.registry.register(self.candidate, self.dataset, self.split)

    def test_sql_actual_version_run_and_allowlist_readback(self):
        receipt = self.register()
        self.assertEqual(receipt['version'], '1')
        db=sqlite3.connect(self.root/'registry.sqlite')
        try:
            self.assertEqual(db.execute('select count(*) from model_versions').fetchone()[0], 1)
        finally:
            db.close()
        run = self.registry.client.get_run(receipt['run_id'])
        self.assertEqual(run.info.status, 'FINISHED')
        self.assertEqual(set(run.data.params), self.registry.PARAM_KEYS)
        self.assertEqual(set(run.data.metrics), {'train_cases','calibration_cases','holdout_cases','fitted_labels'})
        self.assertFalse(receipt['promotion_allowed'])
        self.registry.load_exact(receipt)

    def test_mutable_candidate_rejected_before_serialization(self):
        mutations = {
            'threshold_policy': lambda c: c.thresholds.update({'101':.2}),
            'wrong_policy': lambda c: c.policy.update({'sector_guard':True}),
            'missing_pipeline': lambda c: c.pipelines.pop('101'),
            'extra_pipeline': lambda c: c.pipelines.update({'103':c.pipelines['101']}),
            'axis': lambda c: c.topic_ids.reverse(),
            'status': lambda c: c.label_status.update({'101':'not_evaluable:single_class'}),
            'missing_status': lambda c: c.label_status.pop('103'),
            'support': lambda c: c.supports['101'].update({'positive':11}),
            'schema': lambda c: c.feature_schema.update({'missing_token':'other'}),
            'dependency': lambda c: c.dependency_versions.update({'scikit-learn':'0'}),
            'split': lambda c: setattr(c,'split_digest','a'*64),
            'metadata': lambda c: setattr(c,'training_case_ids',('other',)),
            'coefs': lambda c: c.pipelines['101'].named_steps['classifier'].coef_.fill(0),
            'classes': lambda c: c.pipelines['101'].named_steps['classifier'].classes_.fill(9),
        }
        for key, mutate in mutations.items():
            c = copy.deepcopy(self.candidate); mutate(c)
            with self.subTest(key=key), patch('learning_candidate_registry.joblib.dump', side_effect=AssertionError('serialized')) as dump:
                with self.assertRaises(RegistryError): self.registry.register(c,self.dataset,self.split)
                dump.assert_not_called()

    def test_exact_selector_alias_moves_and_external_digests_block_before_load(self):
        first = self.register(); second = self.register()
        self.registry.client.set_registered_model_alias(first['name'],'candidate',second['version'])
        self.assertEqual(self.registry.load_exact(first).topic_ids, ['101','102','103'])
        for field in ['artifact_sha256','policy_digest','schema_digest','manifest_digest','generation']:
            bad = dict(first); bad[field] = '0'*64 if field != 'generation' else 'stale'
            with self.subTest(field=field), patch('learning_candidate_registry.joblib.load', side_effect=AssertionError('deserialized')):
                with self.assertRaises(RegistryError): self.registry.load_exact(bad)

    def test_tamper_file_blocks_before_deserialization(self):
        receipt = self.register()
        path = self.registry.package_path(receipt)/'pipeline.joblib'
        path.write_bytes(path.read_bytes()+b'tamper')
        with patch('learning_candidate_registry.joblib.load', side_effect=AssertionError('deserialized')):
            with self.assertRaises(RegistryError): self.registry.load_exact(receipt)

    def test_failed_final_run_and_registry_readback_are_ineligible(self):
        receipt = self.register()
        self.registry.client.set_terminated(receipt['run_id'],status='FAILED')
        with self.assertRaises(RegistryError): self.registry.load_exact(receipt)
        with patch.object(self.registry.client,'get_model_version',side_effect=RuntimeError('readback')):
            with self.assertRaises(RegistryError): self.register()
        versions = self.registry.client.search_model_versions()
        self.assertTrue(any(v.tags.get('eligible') == 'false' for v in versions))
        self.assertTrue(list((self.root/'private').rglob('pipeline.joblib')))

    def test_finalization_exception_marks_failed_version_and_preserves_artifacts(self):
        real=self.registry.client.set_terminated
        def fail_finish(run_id,status):
            if status == 'FINISHED': raise RuntimeError('finish failed')
            return real(run_id,status=status)
        with patch.object(self.registry.client,'set_terminated',side_effect=fail_finish):
            with self.assertRaises(RegistryError): self.register()
        versions=self.registry.client.search_model_versions()
        self.assertEqual(len(versions),1)
        self.assertEqual(versions[0].tags['eligible'],'false')
        self.assertEqual(self.registry.client.get_run(versions[0].run_id).info.status,'FAILED')
        self.assertTrue(list(self.root.rglob('pipeline.joblib')))

    def test_untrusted_root_and_source_rejected(self):
        with self.assertRaises(RegistryError): LocalCandidateRegistry(Path(tempfile.gettempdir())/'learning-registry-outside-test')
        receipt = self.register()
        version = SimpleNamespace(source='file:///untrusted/pipeline.joblib')
        with patch.object(self.registry.client,'get_model_version',return_value=version), patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('load')):
            with self.assertRaises(RegistryError): self.registry.load_exact(receipt)

    def test_manifest_internal_axes_cardinality_policy_block_before_load(self):
        receipt=self.register(); path=self.registry.package_path(receipt)/'manifest.json'
        original=json.loads(path.read_text())
        for key in ['pipeline_axis','status_axis','support','threshold_policy','classes','coef_shape','dependency']:
            manifest=copy.deepcopy(original); rep=manifest['representation']
            if key == 'pipeline_axis': rep['pipelines']['103']=rep['pipelines']['101']
            if key == 'status_axis': rep['label_status'].pop('103')
            if key == 'support': rep['supports']['101']['observed']=99
            if key == 'threshold_policy': rep['thresholds']['101']=.3
            if key == 'classes': rep['pipelines']['101']['classes']=[0,2]
            if key == 'coef_shape': rep['pipelines']['101']['coef']=[[]]
            if key == 'dependency': manifest['dependency_digest']='a'*64
            data=json.dumps(manifest,sort_keys=True,separators=(',',':')).encode()
            path.write_bytes(data); bad=dict(receipt,manifest_digest=hashlib.sha256(data).hexdigest())
            self.registry.client.set_model_version_tag(receipt['name'],receipt['version'],'manifest_digest',bad['manifest_digest'])
            (self.registry.package_path(receipt)/'receipt.json').write_text(json.dumps(bad))
            (self.registry.package_path(receipt)/'completion.json').write_text(json.dumps({'state':'complete','receipt':bad}))
            with self.subTest(key=key), patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('loaded')) as load:
                with self.assertRaises(RegistryError): self.registry.load_exact(bad)
                load.assert_not_called()


    def test_r1_complete_packet_lineage_reconciliation(self):
        receipt=self.register(); package=self.registry.package_path(receipt)
        original=json.loads((package/'manifest.json').read_bytes())
        mutations={
            'calibration': lambda m: m['split'].update(calibration=[]),
            'holdout': lambda m: m['split'].update(holdout=[]),
            'excluded': lambda m: m['split'].update(excluded={'invented':'excluded'}),
            'cutoffs': lambda m: m['split'].update(cutoffs={}),
            'content': lambda m: m.update(dataset_content_digest='b'*64),
            'schema': lambda m: m.update(dataset_schema={'columns':[], 'topic_ids':[]}),
            'dataset': lambda m: m.update(dataset_digest='c'*64),
            'calibration_type': lambda m: m['split'].update(calibration=None),
            'holdout_type': lambda m: m['split'].update(holdout='wrong'),
            'excluded_type': lambda m: m['split'].update(excluded=[]),
            'cutoffs_type': lambda m: m['split'].update(cutoffs=False),
            'content_type': lambda m: m.update(dataset_content_digest=[]),
            'schema_type': lambda m: m.update(dataset_schema=False),
            'dataset_type': lambda m: m.update(dataset_digest=None),
        }
        for name,mutate in mutations.items():
            with self.subTest(name=name):
                manifest=copy.deepcopy(original); mutate(manifest)
                data=json.dumps(manifest,sort_keys=True,separators=(',',':')).encode()
                (package/'manifest.json').write_bytes(data)
                bad=dict(receipt,manifest_digest=hashlib.sha256(data).hexdigest())
                self.registry.client.set_model_version_tag(receipt['name'],receipt['version'],'manifest_digest',bad['manifest_digest'])
                (package/'receipt.json').write_text(json.dumps(bad))
                if (package/'completion.json').exists():
                    (package/'completion.json').write_text(json.dumps({'state':'complete','receipt':bad}))
                with self.assertRaises(RegistryError) as caught: self.registry.load_exact(bad)
                if name not in ['dataset','dataset_type']:
                    self.assertEqual(str(caught.exception),'registry.reloaded_packet_mismatch')
        (package/'manifest.json').write_text(json.dumps(original,sort_keys=True,separators=(',',':')))
        self.registry.client.set_model_version_tag(receipt['name'],receipt['version'],'manifest_digest',receipt['manifest_digest'])
        (package/'receipt.json').write_text(json.dumps(receipt))
        if (package/'completion.json').exists():
            (package/'completion.json').write_text(json.dumps({'state':'complete','receipt':receipt}))
        self.assertEqual(self.registry.load_exact(receipt).training_case_ids,self.candidate.training_case_ids)

    def test_r1_primary_secondary_finalization_matrix(self):
        cases=[(primary,status,tag) for primary in ['receipt','marker','finish_before','finish_after','readback','receipt_readback','marker_readback','marker_replace'] for status in [False,True] for tag in [False,True]]
        for primary,fail_status,fail_tag in cases:
            with self.subTest(primary=primary,status=fail_status,tag=fail_tag):
                self.setUp()
                client=self.registry.client
                real_finish=client.set_terminated; real_tag=client.set_model_version_tag
                real_get=client.get_model_version; real_write=Path.write_bytes
                real_read=Path.read_bytes
                import learning_candidate_registry as registry_module
                real_replace=registry_module.os.replace
                attempted=[]
                def finish(run_id,status):
                    if status=='FAILED': attempted.append('status')
                    if status=='FAILED' and fail_status: raise RuntimeError('secondary status')
                    if status=='FINISHED' and primary=='finish_before': raise PermissionError('primary finish')
                    result=real_finish(run_id,status=status)
                    if status=='FINISHED' and primary=='finish_after': raise PermissionError('primary finish')
                    return result
                def tag(name,version,key,value):
                    if key=='eligible' and value=='false': attempted.append('tag')
                    if key=='eligible' and value=='false' and fail_tag: raise RuntimeError('secondary tag')
                    return real_tag(name,version,key,value)
                def get(*args,**kwargs):
                    if primary=='readback': raise PermissionError('primary readback')
                    return real_get(*args,**kwargs)
                def write(path,data):
                    if (primary=='receipt' and path.name=='receipt.json') or (primary=='marker' and path.name.startswith('completion')):
                        raise PermissionError('primary persistence')
                    return real_write(path,data)
                def read(path):
                    if (primary=='receipt_readback' and path.name=='receipt.json') or (primary=='marker_readback' and path.name=='completion.pending'):
                        raise PermissionError('primary readback')
                    return real_read(path)
                def replace(source,target):
                    if primary=='marker_replace': raise PermissionError('primary publication')
                    return real_replace(source,target)
                with patch.object(Path,'read_bytes',autospec=True,side_effect=read), patch('learning_candidate_registry.os.replace',side_effect=replace), patch.object(client,'set_terminated',side_effect=finish), patch.object(client,'set_model_version_tag',side_effect=tag), patch.object(client,'get_model_version',side_effect=get), patch.object(Path,'write_bytes',autospec=True,side_effect=write):
                    with self.assertRaises(RegistryError) as caught: self.register()
                self.assertEqual(attempted,['status','tag'])
                self.assertIsInstance(caught.exception.__cause__,PermissionError)
                self.assertEqual(str(caught.exception),'registry.registration_ineligible')
                versions=client.search_model_versions(); self.assertEqual(len(versions),1)
                version=versions[0]
                if fail_status and fail_tag and primary in ['receipt','marker','receipt_readback','marker_readback','marker_replace']:
                    self.assertEqual(version.tags['eligible'],'true')
                    self.assertEqual(client.get_run(version.run_id).info.status,'FINISHED')
                receipt={key:version.tags[key] for key in ['namespace','generation','artifact_sha256','schema_digest','policy_digest','manifest_digest','mapping_digest']}
                receipt.update(name=version.name,version=str(version.version),run_id=version.run_id,promotion_allowed=False)
                with patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('deserialized')) as load:
                    with self.assertRaises(RegistryError): self.registry.load_exact(receipt)
                    load.assert_not_called()

    def test_r1_missing_nonterminal_and_wrong_completion_deny_before_unpickle(self):
        receipt=self.register(); marker=self.registry.package_path(receipt)/'completion.json'
        real_open=Path.open
        for value in [None,{}, {'state':'pending','receipt':receipt}, {'state':'complete','receipt':dict(receipt,version='99')}]:
            if value is not None: marker.write_text(json.dumps(value))
            def open_record(path,*args,**kwargs):
                if value is None and path==marker: raise FileNotFoundError('synthetic missing marker')
                return real_open(path,*args,**kwargs)
            with patch.object(Path,'open',autospec=True,side_effect=open_record), self.subTest(value=value), patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('deserialized')) as load:
                with self.assertRaises(RegistryError): self.registry.load_exact(receipt)
                load.assert_not_called()

    def test_t10_case_packet_exact_hash_and_completion_guard_before_deserialize(self):
        from tests.test_learning_case_batch import native_dataset,candidate
        from learning_candidate_registry import _representation,digest
        request,dataset,state=native_dataset(); split,model=candidate(dataset,request['cutoffs'])
        manifest=json.loads(request['bundle']['manifest_json'])
        binding=dict(token=request['token'],context_digest=request['context_digest'],authority_generation=manifest['generation'],
            authority_digest=manifest['canonical_digest'],mapping=dict(version='t10-explicit-fixture-v1',namespace='test-namespace:t10-synthetic-shadow',
                topic_ids=dataset.topic_ids,filter_keys=['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],approved_common_axis=False))
        registry=LocalCandidateRegistry(ROOT/('01c-r1-test-'+uuid.uuid4().hex),case_binding=binding)
        receipt=registry.register_case(model,dataset,split,binding)
        self.assertEqual(digest(_representation(registry.load_exact(receipt))),digest(_representation(model)))
        package=registry.package_path(receipt); raw=(package/'pipeline.joblib').read_bytes()
        (package/'pipeline.joblib').write_bytes(raw+b'tampered')
        with patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('must not deserialize')) as loader:
            with self.assertRaisesRegex(RegistryError,'hash_mismatch'): registry.load_exact(receipt)
            loader.assert_not_called()
        (package/'pipeline.joblib').write_bytes(raw)
        registry.client.set_model_version_tag(receipt['name'],receipt['version'],'eligible','false')
        with patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('must not deserialize')) as loader:
            with self.assertRaisesRegex(RegistryError,'ineligible'): registry.load_exact(receipt)
            loader.assert_not_called()
        registry.client.set_model_version_tag(receipt['name'],receipt['version'],'eligible','true')
        (package/'completion.json').write_bytes(b'{}')
        with patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('must not deserialize')) as loader:
            with self.assertRaisesRegex(RegistryError,'publication_incomplete'): registry.load_exact(receipt)
            loader.assert_not_called()

    def test_t10_repair_distinct_sql_versions_cold_previous_and_dependency_denial(self):
        from tests.test_learning_case_batch import native_dataset,candidate
        from learning_candidate_registry import _representation,digest,distribution_version
        import subprocess,sys
        request,dataset,state=native_dataset(); split,model=candidate(dataset,request['cutoffs'])
        manifest=json.loads(request['bundle']['manifest_json'])
        binding=dict(token=request['token'],context_digest=request['context_digest'],authority_generation=manifest['generation'],
            authority_digest=manifest['canonical_digest'],mapping=dict(version='t10-explicit-fixture-v1',namespace='test-namespace:t10-synthetic-shadow',
                topic_ids=dataset.topic_ids,filter_keys=['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],approved_common_axis=False))
        registry=LocalCandidateRegistry(ROOT/('01c-t10-'+uuid.uuid4().hex),case_binding=binding)
        first=registry.register_case(model,dataset,split,binding); second=registry.register_case(model,dataset,split,binding)
        self.assertEqual((first['version'],second['version']),('1','2'))
        self.assertNotEqual(first['generation'],second['generation']);self.assertNotEqual(first['run_id'],second['run_id'])
        with sqlite3.connect(registry.root/'registry.sqlite') as db:
            self.assertEqual(db.execute('select count(*) from model_versions').fetchone()[0],2)
        code='import json,sys; from learning_candidate_registry import LocalCandidateRegistry,_representation,digest; r=LocalCandidateRegistry(sys.argv[1],case_binding=json.loads(sys.argv[3])); print(digest(_representation(r.load_exact(json.loads(sys.argv[2])))))'
        cold=subprocess.run([sys.executable,'-B','-c',code,str(registry.root),json.dumps(first),json.dumps(binding)],capture_output=True,text=True,timeout=20,env=dict(os.environ))
        self.assertEqual(cold.returncode,0,cold.stderr);self.assertEqual(cold.stdout.strip(),digest(_representation(model)))
        def drift(key): return 'synthetic-drift' if key=='optuna' else distribution_version(key)
        with patch('learning_candidate_registry.distribution_version',side_effect=drift),patch('learning_candidate_registry.joblib.load',side_effect=AssertionError('deserialized')) as loader:
            with self.assertRaisesRegex(RegistryError,'code_or_tuning_dependency_mismatch'):registry.load_exact(first)
            loader.assert_not_called()
        self.assertEqual(digest(_representation(registry.load_exact(first))),digest(_representation(model)))


# ROOT vectors append after the complete historical raw prefix. No duplicate aliases.
_t11_previous_setup = RegistryTests.setUp

def _t11_setup(self):
    if not self._testMethodName.startswith('test_t11_root_'):
        return _t11_previous_setup(self)
    if os.name != 'nt' or not os.environ.get('I4S_T11_UNIT_ROOT'):
        self.skipTest('native registry roots require explicit isolated Windows fixture root')
    from tests.test_learning_case_batch import native_dataset
    request, dataset, state = native_dataset()
    manifest = json.loads(request['bundle']['manifest_json'])
    self.t11_binding = dict(token=request['token'], context_digest=request['context_digest'],
        authority_generation=manifest['generation'], authority_digest=manifest['canonical_digest'],
        mapping=dict(version='t10-explicit-fixture-v1', namespace='test-namespace:t10-synthetic-shadow',
        topic_ids=dataset.topic_ids, filter_keys=['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'], approved_common_axis=False))
    self.t11_root = Path(os.environ['I4S_T11_UNIT_ROOT'])
    self.t11_env = dict(APP_ENV='testing', I4S_BATCH_SYNTHETIC_CAPABILITY='test-namespace:t07-export',
        I4S_BATCH_NATIVE_ARTIFACT_ROOT=str(self.t11_root), I4S_BATCH_ADVERSARIAL_MODE='')
    self.t11_path = self.t11_root / ('05b-candidate-' + request['token']['batch_id'])
    self.t11_dataset, self.t11_request = dataset, request

RegistryTests.setUp = _t11_setup

def _t11_root_default(self):
    from learning_candidate_registry import allowed_root, ALLOWED_ROOT
    with patch.dict(os.environ, {}, clear=True):
        self.assertEqual(allowed_root(), ALLOWED_ROOT)
        with patch('learning_candidate_registry.MlflowClient') as sql:
            with self.assertRaises(RegistryError): LocalCandidateRegistry(self.t11_path, case_binding=self.t11_binding)
            sql.assert_not_called()

RegistryTests.test_t11_root_default_absent_legacy_unchanged = _t11_root_default

def _t11_root_positive(self):
    from learning_candidate_registry import allowed_root
    with patch.dict(os.environ, self.t11_env):
        self.assertEqual(allowed_root(), self.t11_root)
        registry = LocalCandidateRegistry(self.t11_path, case_binding=self.t11_binding)
        self.assertEqual(registry.root, self.t11_path)
        self.assertTrue((registry.root / 'registry.sqlite').is_file())

RegistryTests.test_t11_root_explicit_existing_native_positive = _t11_root_positive

def _t11_root_denials(self):
    from learning_candidate_registry import allowed_root
    vectors = [dict(APP_ENV='production'), dict(APP_ENV=''), dict(I4S_BATCH_SYNTHETIC_CAPABILITY=''),
        dict(I4S_BATCH_SYNTHETIC_CAPABILITY='other'), dict(I4S_BATCH_ADVERSARIAL_MODE='unapproved'),
        dict(I4S_BATCH_NATIVE_ARTIFACT_ROOT=''), dict(I4S_BATCH_NATIVE_ARTIFACT_ROOT=str(self.t11_root.parent)),
        dict(I4S_BATCH_NATIVE_ARTIFACT_ROOT=str(self.t11_root)+'/..'),
        dict(I4S_BATCH_NATIVE_ARTIFACT_ROOT=str(self.t11_root)+'/'),
        dict(I4S_BATCH_NATIVE_ARTIFACT_ROOT=str(self.t11_root.parent/'INVALID_UUID')),
        dict(I4S_BATCH_NATIVE_ARTIFACT_ROOT=str(self.t11_root.parent/uuid.uuid4().hex))]
    for vector in vectors:
        with self.subTest(vector=list(vector)), patch.dict(os.environ, dict(self.t11_env, **vector)), patch('learning_candidate_registry.MlflowClient') as sql, patch('learning_candidate_registry.joblib.dump') as dump:
            with self.assertRaises(RegistryError): allowed_root()
            with self.assertRaises(RegistryError): LocalCandidateRegistry(self.t11_path, case_binding=self.t11_binding)
            sql.assert_not_called(); dump.assert_not_called()
    with patch.dict(os.environ, self.t11_env), patch('learning_candidate_registry.MlflowClient') as sql, patch('learning_candidate_registry.joblib.dump') as dump:
        for path, binding in [(ROOT, self.t11_binding), (self.t11_path/'..', self.t11_binding), (self.t11_path, None)]:
            with self.subTest(path=str(path)), self.assertRaises(RegistryError): LocalCandidateRegistry(path, case_binding=binding)
        sql.assert_not_called(); dump.assert_not_called()

RegistryTests.test_t11_root_invalid_admission_and_escape_before_sql = _t11_root_denials

def _t11_root_refit(self):
    from tests.test_learning_case_batch import candidate
    split, model = candidate(self.t11_dataset, self.t11_request['cutoffs'])
    model.pipelines[next(iter(model.pipelines))].named_steps['classifier'].coef_[0,0] += 1
    with patch.dict(os.environ, self.t11_env):
        registry = LocalCandidateRegistry(self.t11_path, case_binding=self.t11_binding)
        with patch('learning_candidate_registry.joblib.dump') as dump, patch.object(registry.client, 'create_run') as create:
            with self.assertRaisesRegex(RegistryError, 'candidate_mutated'):
                registry.register_case(model, self.t11_dataset, split, self.t11_binding)
            dump.assert_not_called(); create.assert_not_called()

RegistryTests.test_t11_root_false_refit_before_serialization_and_publication = _t11_root_refit
