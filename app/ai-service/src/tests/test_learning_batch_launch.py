"""Cold-process regressions for the historical synthetic Laravel child protocol."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest


ROOT = Path(__file__).resolve().parents[2]
CHILD = ROOT / 'scripts/learning-case-batch.py'


class BatchLaunchTests(unittest.TestCase):
    def run_child_code(self, code, *args, input='{}\n'):
        env = dict(os.environ, PYTHONPATH=str(ROOT / 'src'),
                   PYTHONDONTWRITEBYTECODE='1', APP_ENV='testing',
                   I4S_BATCH_SYNTHETIC_CAPABILITY='test-namespace:t07-export')
        for key in ('I4S_BATCH_NATIVE_ARTIFACT_ROOT', 'I4S_BATCH_ADVERSARIAL_MODE'):
            env.pop(key, None)
        return subprocess.run([sys.executable, '-B', '-c', code, *args],
                              input=input, text=True, capture_output=True,
                              cwd=ROOT, env=env, timeout=20)

    def test_synthetic_admission_does_not_import_operational_registry(self):
        result = self.run_child_code('''
import sys
class BlockOperational:
    def find_spec(self, fullname, *args):
        if fullname in {'learning_production_registry', 'wp13_feedback_bridge',
                        'pandas', 'numpy', 'mlflow', 'joblib'}:
            raise ModuleNotFoundError('blocked optional dependency', name=fullname)
sys.meta_path.insert(0, BlockOperational())
from learning_candidate_registry import ALLOWED_ROOT, allowed_root, LocalCandidateRegistry
assert allowed_root() == ALLOWED_ROOT
assert 'learning_production_registry' not in sys.modules
print('synthetic_admission_ok')
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), 'synthetic_admission_ok')

    def test_operational_registry_remains_explicitly_importable(self):
        result = self.run_child_code('''
import sys
import learning_candidate_registry as registry
assert 'learning_production_registry' not in sys.modules
from learning_candidate_registry import ProductionCandidateRegistry
from learning_production_registry import ProductionCandidateRegistry as expected
assert ProductionCandidateRegistry is expected
''')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_missing_dependency_reports_only_allowlisted_name(self):
        for dependency, expected in [('pandas', ':pandas'),
                                     ('private_input_sentinel', '')]:
            with self.subTest(dependency=dependency):
                result = self.run_child_code('''
import runpy, sys
class BlockPandas:
    def find_spec(self, fullname, *args):
        if fullname == 'pandas':
            raise ModuleNotFoundError('private traceback /secret/path', name=sys.argv[2])
sys.meta_path.insert(0, BlockPandas())
runpy.run_path(sys.argv[1], run_name='__main__')
''', str(CHILD), dependency)
                self.assertEqual(result.returncode, 3)
                self.assertEqual(result.stdout, '')
                self.assertEqual(result.stderr.strip(), 'learning_batch.dependency_missing' + expected)

    def test_actual_launcher_fits_refreshes_witnesses_and_cold_loads(self):
        # The same file protocol used by Artisan, with synthetic helper receipts.
        # Production imports are unavailable: this lane must remain independent.
        import shutil
        import uuid
        from tests.learning_portable_transport_helper import request, encode, digest
        from learning_candidate_registry import ALLOWED_ROOT
        payload = request()
        payload['token']['batch_id'] = uuid.uuid4().hex
        payload['candidate_stage'] = dict(
            version='t10-explicit-fixture-v1', namespace='test-namespace:t10-synthetic-shadow',
            topic_ids=['1', '2', '3'],
            filter_keys=['esrs_e1_energy', 'esrs_e2_pollution_of_air', 'esrs_e3_summary'],
            approved_common_axis=False)
        fresh = dict(payload['bundle'])
        manifest = json.loads(fresh['manifest_json'])
        bindings = json.loads(fresh['bindings_json'])
        manifest['generation'] += 1
        manifest.pop('canonical_digest')
        manifest['canonical_digest'] = digest(manifest)
        bindings['manifest_digest'] = manifest['canonical_digest']
        fresh.update(manifest_json=encode(manifest), bindings_json=encode(bindings))
        # Keep artifacts in the existing trusted synthetic root; no serving root.
        ALLOWED_ROOT.mkdir(parents=True, exist_ok=True)
        artifact = ALLOWED_ROOT / ('05a-batch-' + payload['token']['batch_id'])
        artifact.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, artifact)
        candidate_root = ALLOWED_ROOT / ('05b-candidate-' + payload['token']['batch_id'])
        self.addCleanup(lambda: shutil.rmtree(candidate_root) if candidate_root.exists() else None)
        (artifact / 'refresh.json').write_text(json.dumps({'bundle': fresh}) + '\n')
        (artifact / 'refresh-ready').write_text('ready')
        code = '''
import runpy, sys, os
class BlockOperational:
    def find_spec(self, fullname, *args):
        if fullname == 'learning_production_registry':
            raise ModuleNotFoundError('operational lane unavailable', name=fullname)
sys.meta_path.insert(0, BlockOperational())
os.environ['I4S_BATCH_REFRESH_PATH'] = sys.argv.pop(1)
os.environ['MLFLOW_ENABLE_TELEMETRY'] = 'false'
sys.argv.pop(0)
runpy.run_path(sys.argv[0], run_name='__main__')
'''
        result = self.run_child_code(code, str(artifact / 'refresh.json'), str(CHILD), input=json.dumps(payload) + '\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        ready, output = map(json.loads, result.stdout.splitlines())
        self.assertEqual(ready, {'ready': True})
        self.assertGreater(output['receipt']['fit_heads'], 0)
        self.assertGreater(output['receipt']['optuna_trials'], 0)
        self.assertFalse(output['receipt']['promotion_allowed'])
        self.assertEqual(output['receipt']['authority_generation'], manifest['generation'])
        self.assertEqual(output['receipt']['authority_digest'], manifest['canonical_digest'])
        self.assertEqual(output['receipt']['batch_id'], payload['token']['batch_id'])
        witness = self.run_child_code(code, str(artifact / 'refresh.json'), str(CHILD), '--witness',
                                     input=json.dumps(dict(payload, refresh=fresh)) + '\n')
        self.assertEqual(witness.returncode, 0, witness.stderr)
        canonical = json.loads(witness.stdout)
        self.assertEqual(canonical['dataset_digest'], output['receipt']['dataset_digest'])
        self.assertEqual(canonical['state'], output['state'])
        check = self.run_child_code(code, str(artifact / 'refresh.json'), str(CHILD), '--candidate-check',
                                   input=json.dumps({'package': output['candidate'], 'features': {
                                       'data': [['Spain', '50_249', 'true']],
                                       'index': ['synthetic-unseen-company'],
                                       'columns': ['headquarters_country', 'employee_count_range', 'stock_listed']}}) + '\n')
        self.assertEqual(check.returncode, 0, check.stderr)
        loaded = json.loads(check.stdout)
        self.assertEqual(loaded['dataset_digest'], output['receipt']['dataset_digest'])
        self.assertIn('proposal', loaded)


if __name__ == '__main__':
    unittest.main()
