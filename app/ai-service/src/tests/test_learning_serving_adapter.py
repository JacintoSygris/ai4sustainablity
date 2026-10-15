"""Synthetic public-only package; private imports are explicitly blocked."""
import importlib
import hashlib
import os
from pathlib import Path
import sys
import tempfile
import shutil
import subprocess
import unittest
from unittest.mock import patch
import learning_serving_adapter as adapter
from learning_production_registry import code_hashes
from learning_production_cli import resolve_comparator


class AdapterTests(unittest.TestCase):
    def test_public_alias_and_hash_inventory(self):
        from services import datatypes, service_predict
        with patch.dict(os.environ, LEARNING_SERVING_RUNTIME='public'):
            self.assertIs(adapter.company_type(), datatypes.CompanyData)
            self.assertIs(adapter.predictor(), service_predict)
            paths = adapter.sources()
            hashes = code_hashes()
            serving = Path(adapter.__file__).resolve().parents[1] / 'public_runtime/services'
            for name in ('service_predict', 'datatypes'):
                key = 'services/' + name + '.py'
                self.assertEqual(paths[key], (serving / (name + '.py')).resolve())
                self.assertEqual(hashes[key], hashlib.sha256(paths[key].read_bytes()).hexdigest())
            self.assertIn('learning_gate_metrics.py', hashes)
            self.assertIn('esrs_standard.py', hashes)
            self.assertIn('contracts/learning-gate-metrics-v1.schema.json', hashes)
            with patch.object(adapter, 'sources', return_value={**paths, 'synthetic': paths['services/datatypes.py']}):
                self.assertNotEqual(hashes, code_hashes())
        with patch.dict(os.environ, LEARNING_SERVING_RUNTIME='private'):
            with self.assertRaises(ValueError):
                adapter.predictor()

    def test_public_subprocess_with_only_shared_src_and_sibling_runtime(self):
        """Real sys.path/import resolution, no private services package copied."""
        source = Path(adapter.__file__).parent
        with tempfile.TemporaryDirectory() as tmp:
            app = Path(tmp)/'app'
            runtime_root = app/'ai-service'
            target = runtime_root/'src'
            package = runtime_root/'public_runtime'/'services'
            package.mkdir(parents=True)
            target.mkdir()
            for name in ('learning_serving_adapter.py', 'learning_production_registry.py',
                         'learning_production_cli.py', 'learning_production_runtime.py',
                         'wp13_feedback_bridge.py', 'wp13_feedback_training.py',
                         'learning_gate_metrics.py', 'learning_activation.py',
                         'feedback_bandit.py', 'feedback_bandit_registry.py', 'esrs_standard.py', 'train/masked_multilabel.py'):
                dest = target/name
                dest.parent.mkdir(parents=True,exist_ok=True)
                shutil.copyfile(source/name,dest)
            contract = app/'contracts'/'api'/'learning-gate-metrics-v1.schema.json'
            contract.parent.mkdir(parents=True)
            shutil.copyfile(source.parents[1]/'contracts/api'/contract.name,contract)
            (package.parent/'__init__.py').write_text('')
            (package/'__init__.py').write_text('')
            # Same model type used by the authentic layout, with no predictor imports.
            shutil.copyfile(adapter.sources()['services/datatypes.py'],package/'datatypes.py')
            (target/'services').mkdir()
            shutil.copyfile(source/'services/__init__.py',target/'services/__init__.py')
            (package/'service_predict.py').write_text(
                'def predict_esrs(c, **kw): return "synthetic-public"\n'
                'def resolve_model_profile(p): return p\n'
                'def model_serving_identity(p): return {"profile":p}\n')
            env = {**os.environ,'PYTHONPATH':str(target),
                   'LEARNING_SERVING_RUNTIME':'public','PYTHONDONTWRITEBYTECODE':'1'}
            script = '''
import importlib.abc, sys
class BlockPrivate(importlib.abc.MetaPathFinder):
    def find_spec(self, name, *args):
        if name in ('crc_recall_floor', 'sector_threshold_guard', 'sector_guard', 'train_model_yaml', 'utils.constants'):
            raise AssertionError('private imports blocked')
sys.meta_path.insert(0, BlockPrivate())
from learning_serving_adapter import company_type, predict_esrs, resolve_model_profile, model_serving_identity
from learning_production_registry import code_hashes
from wp13_feedback_training import production_features
from wp13_feedback_bridge import production_rows
assert predict_esrs(None) == 'synthetic-public'
assert company_type().__module__ == 'services.datatypes'
assert model_serving_identity(resolve_model_profile('p')) == {'profile':'p'}
hashes = code_hashes()
assert 'services/service_predict.py' in hashes
assert 'public_runtime/services/service_predict.py' not in hashes
assert 'feedback_bandit.py' in hashes
company = company_type().model_validate(dict(company_name='pseudonymous',sector_list=['C'],
    headquarters_country='Spain',num_subsidiaries_countries=0,employees_total=150,
    annual_turnover_million_euro=1,stock_listed=False,reporting_currency='EUR'))
assert production_features(company)['employees_total'] == 150
assert 'company_name' not in production_features(company)
print('synthetic-public-ok')
'''
            result = subprocess.run([sys.executable,'-B','-c',script],cwd=target,env=env,
                                    capture_output=True,text=True,timeout=30)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(result.stdout.strip(),'synthetic-public-ok')

    def test_selected_failure_never_falls_back(self):
        with patch.dict(os.environ, LEARNING_SERVING_RUNTIME='public'), patch('importlib.import_module',side_effect=ImportError('synthetic')) as mocked:
            with self.assertRaises(ImportError): adapter.predict_esrs(None)
            mocked.assert_called_once_with('services.service_predict')

    def test_real_public_gate_with_active_bandit_and_private_imports_blocked(self):
        """Real public predictor; only model artifacts and labels are synthetic."""
        source = Path(adapter.__file__).parent
        public = source.parent/'public_runtime'
        self.assertTrue(public.is_dir())
        with tempfile.TemporaryDirectory() as tmp:
            app = Path(tmp)/'app'
            runtime = app/'ai-service'
            target = runtime/'src'
            target.mkdir(parents=True)
            shutil.copytree(public, runtime/'public_runtime', ignore=shutil.ignore_patterns('__pycache__'))
            inventory = code_hashes()
            (target/'services').mkdir()
            shutil.copyfile(source/'services/__init__.py',target/'services/__init__.py')
            for name in inventory:
                if name.startswith(('services/', 'contracts/')):
                    continue
                dest = target/name
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source/name, dest)
            contract = app/'contracts/api/learning-gate-metrics-v1.schema.json'
            contract.parent.mkdir(parents=True)
            shutil.copyfile(source.parents[1]/'contracts/api'/contract.name, contract)
            env = {k: v for k, v in os.environ.items()
                   if not k.startswith(('LEARNING_', 'ESRS_', 'I4S_AI_', 'APP_ENV'))}
            env.update(PYTHONPATH=str(target),
                       LEARNING_SERVING_RUNTIME='public', PYTHONDONTWRITEBYTECODE='1',
                       LEARNING_GATE_METRICS_ENABLED='true', LEARNING_ENGINE_SERVING_ENABLED='false',
                       LEARNING_GATE_REPLICATES='100', I4S_AI_NEW_FORMAT_SCORE_THRESHOLD='.5')
            script = '''
import contextlib, hashlib, importlib.abc, io, json, os, sys, time
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
class BlockPrivate(importlib.abc.MetaPathFinder):
    def find_spec(self, name, *args):
        if name in ('crc_recall_floor', 'sector_threshold_guard', 'sector_guard', 'train_model_yaml', 'utils.constants'):
            raise AssertionError('private imports blocked: ' + name)
sys.meta_path.insert(0, BlockPrivate())
import numpy as np
from services import service_predict as sp
from feedback_bandit import FeedbackBandit, encode_context
from learning_serving_adapter import company_type
from learning_production_cli import baseline, protocol_document
from learning_production_registry import code_hashes, digest, evaluate
from learning_gate_metrics import policy, validate_contract
key, other = 'esrs_e1_energy_use', 'esrs_e2_pollution_of_air'
profile = sp.PUBLIC_MODEL_PROFILE
columns = ['sector_C']
classifier = SimpleNamespace(feature_names_in_=columns,
    predict=lambda df: [[1, 1]], predict_proba=lambda df: np.array([[1., .5]]))
bandit = FeedbackBandit(profile, [key, other], columns, 'a'*64,
    algorithm='epsilon_greedy', epsilon=1, min_events=1, shadow=False)
bandit.update(sequence=1, topic=other, context=encode_context({'sector_C':1}),
    base_score=.5, proposed=True, accepted=True)
state = Path('synthetic-bandit.json').resolve()
bandit.save(state)
os.environ['ESRS_FEEDBACK_BANDIT_PATH'] = str(state)
company = dict(company_name='synthetic', sector_list=['C'], headquarters_country='Spain',
    num_subsidiaries_countries=0, employees_total=150, annual_turnover_million_euro=1,
    stock_listed=False, reporting_currency='EUR', model_profile=profile)
document = {'schema':'learning-heldout-v1', 'cases':[
    {'group':'synthetic-' + str(i), 'p5_sha256':hashlib.sha256(str(i).encode()).hexdigest(),
     'input':company, 'truth':{key:1, other:0}} for i in range(30)]}
sets = {'heldout':(document, digest(document))}
class Candidate:
    def predict(self, company, base): return {key:1, other:0}
class Tie:
    def predict(self, company, base): return dict(base)
with patch.object(sp, 'load_profile_classifier', return_value=classifier), \
     patch.object(sp, 'load_profile_esrs_columns', return_value=[key, other]), \
     patch.object(sp, '_model_artifact_hashes', return_value={'esrs_classifier.pkl':'a'*64}), \
     patch('feedback_bandit.uuid.uuid4', side_effect=AssertionError('unfrozen bandit draw')), \
     contextlib.redirect_stdout(io.StringIO()):
    identity = sp.model_serving_identity(sp.resolve_model_profile(profile))
    assert identity['runtime_config']['policy_active']['feedback_bandit'] is True
    protocol = protocol_document(sets, identity, None, None, created_at=time.time(), dataset_sha256='b'*64)
    validate_contract(protocol, 'protocol')
    arguments = dict(metrics_policy=policy(), protocol_sha256=digest(protocol),
                     expected_identity=identity, decision_ids=protocol['decision_ids'])
    gate = evaluate(Candidate(), sets, baseline, **arguments)
    assert gate == evaluate(Candidate(), sets, baseline, **arguments)
    validate_contract(gate, 'receipt')
    assert gate['passed'] and gate['zero_positive_loss']
    fp = gate['report']['heldout']['micro']['predictors']['base']['fp']
    assert 0 < fp < 30, fp
    assert not evaluate(Tie(), sets, baseline, **arguments)['passed']
    assert company_type().__module__ == 'services.datatypes'
    hashes = code_hashes()
    assert hashes['esrs_standard.py'] == hashlib.sha256(Path('esrs_standard.py').read_bytes()).hexdigest()
    assert 'services/service_predict.py' in hashes
    assert 'public_runtime/services/service_predict.py' not in hashes
    assert 'crc_recall_floor' not in sys.modules
print('synthetic-real-public-gate-ok')
'''
            result = subprocess.run([sys.executable, '-B', '-c', script], cwd=target, env=env,
                                    capture_output=True, text=True, timeout=60)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.strip(), 'synthetic-real-public-gate-ok')

    def test_serving_disabled_means_base(self):
        with patch.dict(os.environ, LEARNING_ENGINE_SERVING_ENABLED='false'), patch('learning_production_cli.effective_active') as resolve:
            self.assertIsNone(resolve_comparator(object(),'a'*32,'b'*64))
            resolve.assert_not_called()
