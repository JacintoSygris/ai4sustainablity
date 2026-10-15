"""Cross-process wiring of the optional learning hooks in the public layout.

The FastAPI app runs in its own process exactly like ``uvicorn public_model_app:app``
from ``app/ai-service`` (no PYTHONPATH). The learning CLIs run like the Laravel
LearningPythonBridge (argv, cleared environment, ``PYTHONPATH=src``, ``cwd=src``).
Both use the shipped ``model-artifacts/gpt41`` model and synthetic data only; the
state root is a temporary directory outside the repository.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
import uuid

AI_SERVICE_ROOT = Path(__file__).resolve().parents[2]
SRC = AI_SERVICE_ROOT / 'src'
PROFILE = 'new_format_732_v1_gpt41'
HEAVY_TRAINING_MODULES = ('lightgbm', 'xgboost', 'optuna', 'skmultilearn')
HOOK_MODULES = ('learning_production_runtime', 'feedback_bandit', 'learning_activation',
                'learning_production_registry')

SERVE = r'''
import json, sys
spec = json.loads(sys.argv[1])
if spec.get("block_hooks"):
    class Block:
        def find_spec(self, name, *args):
            if name in set(spec["hook_modules"]):
                raise ModuleNotFoundError("absent", name=name)
    sys.meta_path.insert(0, Block())
from fastapi.testclient import TestClient
import public_model_app
with TestClient(public_model_app.app) as client:
    health = client.get("/healthz").json()
    profiles = client.get("/model-profiles").json()
    responses = [client.post("/predict", json=payload) for payload in spec["payloads"]]
print(json.dumps({"health": health, "profiles": profiles,
                  "responses": [{"status": r.status_code, "body": r.json()} for r in responses],
                  "heavy_modules": sorted(m for m in spec["heavy_modules"] if m in sys.modules)}))
'''


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def company(employees, **overrides):
    return {'company_name': 'pseudonymous', 'model_profile': PROFILE, 'sector_list': ['C'],
            'subsidiaries_regions': ['EU'], 'products_services': ['A'], 'headquarters_country': 'Spain',
            'num_subsidiaries_countries': 1, 'employees_total': employees,
            'annual_turnover_million_euro': 5.0, 'stock_listed': False, 'reporting_currency': 'EUR',
            **overrides}


def positives(body):
    return sorted(key for key, value in body['esrs'].items() if value)


@unittest.skipUnless(os.name == 'posix', 'the learning registries use POSIX file locks')
@unittest.skipUnless(all(importlib.util.find_spec(name) for name in ('fastapi', 'httpx', 'sklearn')),
                     'FastAPI test client and scikit-learn are required')
@unittest.skipUnless((AI_SERVICE_ROOT / 'model-artifacts' / 'gpt41').is_dir(), 'public model artifacts absent')
class PublicLearningWiringTest(unittest.TestCase):
    def setUp(self):
        self._temp = tempfile.TemporaryDirectory(prefix='learning-wiring-')
        self.addCleanup(self._temp.cleanup)
        base = Path(self._temp.name).resolve()
        self.root, self.work = base / 'root', base / 'work'
        self.root.mkdir(mode=0o700)
        self.work.mkdir(mode=0o700)
        self.subjects = [str(uuid.UUID(int=i + 1)) for i in range(8)]
        self.bindings = {s: sha(('binding' + s).encode()) for s in self.subjects}
        self.bandit_mode = 'active'

    def base_env(self):
        return {'PATH': os.environ.get('PATH', ''), 'PYTHONDONTWRITEBYTECODE': '1',
                'OMP_NUM_THREADS': '1', 'OPENBLAS_NUM_THREADS': '1'}

    def learning_config(self):
        return {'LEARNING_ENGINE_ROOT': str(self.root), 'LEARNING_BANDIT_MODE': self.bandit_mode,
                'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_SERVING_ENABLED': 'true',
                'LEARNING_ENGINE_AUTHORITY': 'consent', 'LEARNING_ENGINE_SYNTHETIC_ONLY': 'false'}

    def serve(self, payloads, *, learning, block_hooks=False, drop=()):
        env = self.base_env()
        if learning:  # What the AI service unit carries when learning is deployed.
            env.update(self.learning_config(), LEARNING_DEPLOYED='true', LEARNING_ENGINE_SHADOW_ENABLED='false')
        for name in drop:  # Simulate a misconfigured AI service unit.
            env.pop(name)
        spec = {'payloads': payloads, 'block_hooks': block_hooks, 'hook_modules': HOOK_MODULES,
                'heavy_modules': HEAVY_TRAINING_MODULES}
        done = subprocess.run([sys.executable, '-c', SERVE, json.dumps(spec)], cwd=AI_SERVICE_ROOT, env=env,
                              capture_output=True, text=True, timeout=600, check=False)
        self.assertEqual(done.returncode, 0, done.stderr[-3000:])
        result = json.loads(done.stdout.strip().splitlines()[-1])
        for response in result['responses']:
            self.assertEqual(response['status'], 200, response['body'])
        return result

    def bridge(self, script, operation, *arguments):
        """Mirror App\\Services\\LearningPythonBridge::run() (argv, environment, cwd)."""
        env = dict(self.base_env(), PYTHONPATH=str(SRC), MLFLOW_ENABLE_ASYNC_LOGGING='false',
                   MLFLOW_ENABLE_TELEMETRY='false', **self.learning_config())
        done = subprocess.run([sys.executable, str(SRC / script), operation, '--root', str(self.root), *arguments],
                              cwd=SRC, env=env, capture_output=True, text=True, timeout=900, check=False)
        self.assertEqual(done.returncode, 0, f'{script} {operation}: {done.stderr.strip()[-1500:]}')
        return json.loads(done.stdout)

    def bridge_runtime_sha256(self):
        code = ('import learning_production_registry as r; '
                'print(r.digest({"versions": r.versions(), "code_sha256": r.code_hashes()}))')
        done = subprocess.run([sys.executable, '-c', code], cwd=SRC, env=dict(self.base_env(), PYTHONPATH=str(SRC)),
                              capture_output=True, text=True, timeout=300, check=False)
        self.assertEqual(done.returncode, 0, done.stderr[-1500:])
        return done.stdout.strip()

    def lease(self, *, mapping_sha256=None, revisions=None):
        # Laravel refreshes this 90-second authority lease from its consent ledger.
        (self.root / 'authority.json').write_bytes(canonical({
            'subjects': self.subjects, 'bindings': self.bindings, 'mapping_sha256': mapping_sha256,
            'expires_at': time.time() + 90, 'bandit_subjects': self.subjects,
            'bandit_revisions': revisions or {}}))

    def write(self, name, document):
        path = self.work / name
        path.write_bytes(canonical(document))
        return str(path), sha(path.read_bytes())

    def train_and_promote(self, held_input, held_base, revisions=None):
        """Train a gated supervised candidate on synthetic rows and promote it like the panel."""
        mapping = {'keys': [{'status': 'approved', 'python_esrs_key': 'esrs_e1_energy_use', 'ar16_topic_ids': [1]},
                            {'status': 'approved', 'python_esrs_key': 'esrs_e2_pollution_of_air', 'ar16_topic_ids': [2]}]}
        dataset = {'schema': 'learning-feedback-production-v1', 'rejected_rows': 0, 'rows': [
            {'subject': s, 'synthetic': False, 'group': sha(('group' + s).encode()),
             'authority_binding': self.bindings[s], 'consent_version': 'learning-feedback-bandit-2026-10-08',
             'p5_sha256': sha(s.encode()), 'generation': 1, 'p8_revision': 1, 'p9_revision': None,
             'input': company(10 + i * 20, sector_list=['C' if i % 2 else 'F']),
             'topics': {'1': {'mask': 1, 'value': int(i >= 4)}, '2': {'mask': 0, 'value': None}}, 'p9': []}
            for i, s in enumerate(self.subjects)]}
        truth = {k: (1 if v and k != 'esrs_e1_energy_use' else 0) for k, v in held_base.items()}
        heldout = {'schema': 'learning-heldout-v1', 'cases': [
            {'group': 'heldout-group', 'p5_sha256': 'b' * 64, 'input': held_input, 'truth': truth}]}
        dataset_path, dataset_sha = self.write('dataset.json', dataset)
        mapping_path, mapping_sha = self.write('mapping.json', mapping)
        heldout_path, heldout_sha = self.write('heldout.json', heldout)
        fixed = ['--heldout', heldout_path, '--heldout-sha256', heldout_sha]
        self.lease(mapping_sha256=mapping_sha, revisions=revisions)
        trained = self.bridge('learning_production_cli.py', 'train', '--dataset', dataset_path,
                              '--dataset-sha256', dataset_sha, '--mapping', mapping_path,
                              '--mapping-sha256', mapping_sha, '--code-commit', 'd' * 40, *fixed)
        self.assertEqual(trained['status'], 'registered')
        self.assertIs(trained['gate']['passed'], True)
        self.lease(mapping_sha256=mapping_sha, revisions=revisions)
        self.bridge('learning_production_cli.py', 'promote', '--candidate', trained['candidate_id'],
                    '--actor', 'admin:1', '--reason', 'wiring test', *fixed)
        return trained, mapping_sha

    def test_runtime_without_learning_hooks_serves_base(self):
        result = self.serve([company(150)], learning=True, block_hooks=True)
        self.assertEqual(result['health']['learning'], {'hooks_available': False})
        self.assertEqual(result['profiles']['learning'], {'hooks_available': False})
        body = result['responses'][0]['body']
        self.assertEqual(body['model_profile'], PROFILE)
        self.assertNotIn('feedback_bandit', body['mapping_metadata'])
        self.assertEqual(list(self.root.iterdir()), [])

    def test_learning_lifecycle_across_bridge_and_fastapi(self):
        probe, held_input = company(150), company(777, sector_list=['G'], products_services=['C'])

        # 1. Learning OFF (public default): base output, identity present, root untouched.
        off = self.serve([probe], learning=False)
        off_body = off['responses'][0]['body']
        self.assertEqual(off_body['model_profile'], PROFILE)
        self.assertEqual(off['health']['learning']['bandit_mode'], 'off')
        self.assertIsNone(off['profiles']['learning']['registry_readable'])
        self.assertIsNone(off['profiles']['learning']['effective_sha256'])
        self.assertEqual(off_body['mapping_metadata']['serving_identity']['runtime_config']['policy_active'],
                         {'label_thresholds': False, 'crc_recall_floor': False, 'sector_guard': False})
        self.assertNotIn('feedback_bandit', off_body['mapping_metadata'])
        self.assertEqual(list(self.root.iterdir()), [], 'learning OFF must not touch the state root')

        # 2. Bridge status identity equals the FastAPI identity; runtime fingerprints match.
        self.lease()
        status = self.bridge('learning_activation_cli.py', 'status')
        baseline = status['wp13']['serving']['baseline']['serving_identity_sha256']
        self.lease()
        prior = self.serve([probe], learning=True)
        prior_meta = prior['responses'][0]['body']['mapping_metadata']
        self.assertEqual(prior_meta['serving_identity']['serving_identity_sha256'], baseline)
        self.assertEqual(prior['profiles']['serving_identity_sha256'], baseline)
        self.assertEqual(prior['health']['learning']['runtime_sha256'], self.bridge_runtime_sha256())

        # 3. Empty prior: the policy binds its envelope but changes nothing.
        self.assertIs(prior_meta['serving_identity']['runtime_config']['policy_active']['feedback_bandit'], True)
        self.assertEqual(positives(prior['responses'][0]['body']), positives(off_body))
        basis = prior_meta['feedback_bandit']['basis']

        # 4. Bandit sync, promotion and application (Laravel-shaped ledger).
        stream, revision = sha(b'synthetic-stream'), '1:1:' + sha(b'basis')
        revisions = {stream: revision}

        def event_rows(count):
            rows = []
            for i, topic in enumerate(sorted(basis['base_scores'])[:count]):
                rows.append({'id': sha(f'event{i}'.encode()), 'subject': self.subjects[0], 'stream': stream,
                             'revision': revision, 'expires_at': time.time() + 86400, 'event': {
                                 'version': 'p8-bandit-event-v1', 'profile': PROFILE,
                                 'model_sha256': basis['model_sha256'],
                                 'feature_schema_sha256': basis['feature_schema_sha256'],
                                 'encoder': basis['encoder'], 'topic': topic, 'context': basis['context'],
                                 'base_score': basis['base_scores'][topic],
                                 'proposed': bool(prior['responses'][0]['body']['esrs'][topic]), 'accepted': True}})
            return rows

        def bandit_promote(count):
            ledger, ledger_sha = self.write(f'ledger-{count}.json', event_rows(count))
            self.lease(revisions=revisions)
            synced = self.bridge('learning_activation_cli.py', 'bandit-sync', '--dataset', ledger, '--digest', ledger_sha)
            self.assertEqual(synced['candidate']['events_applied'], count)
            self.lease(revisions=revisions)
            promoted = self.bridge('learning_activation_cli.py', 'bandit-promote', '--candidate',
                                   synced['candidate']['id'], '--actor', 'admin:1', '--reason', 'wiring test')
            self.assertEqual(promoted['active']['id'], synced['candidate']['id'])
            return promoted['active']

        first_bandit = bandit_promote(4)
        self.lease(revisions=revisions)
        banded = self.serve([probe, held_input], learning=True)
        banded_meta = banded['responses'][0]['body']['mapping_metadata']
        self.assertEqual(banded_meta['feedback_bandit']['sequence'], 4)
        self.assertEqual(banded_meta['serving_identity']['policy_sha256']['feedback_bandit'], first_bandit['sha256'])
        bandit_identity = banded_meta['serving_identity']['serving_identity_sha256']
        self.assertEqual(banded['profiles']['serving_identity_sha256'], bandit_identity)

        # 5. Supervised learning: train, gate, manual promotion, then FastAPI serves learning:<id>.
        trained, mapping_sha = self.train_and_promote(held_input, banded['responses'][1]['body']['esrs'], revisions)
        self.assertEqual(trained['gate']['baseline_identity'], bandit_identity)
        candidate = trained['candidate_id']
        self.lease(mapping_sha256=mapping_sha, revisions=revisions)
        learned = self.serve([probe], learning=True)
        learned_body = learned['responses'][0]['body']
        self.assertEqual(learned_body['model_profile'], 'learning:' + candidate)
        self.assertEqual(learned_body['mapping_metadata']['runtime_activation'], 'explicit_promoted_learning_candidate')
        self.assertIn('feedback_bandit', learned_body['mapping_metadata'])
        self.assertEqual(learned['heavy_modules'], [], 'serving a promoted model must not load trainer stacks')
        self.lease(mapping_sha256=mapping_sha, revisions=revisions)
        effective = self.bridge('learning_activation_cli.py', 'status')['wp13']
        self.assertEqual(effective['effective'], candidate)
        self.assertIs(learned['profiles']['learning']['registry_readable'], True)
        self.assertEqual(learned['profiles']['learning']['effective_sha256'], effective['effective_sha256'])

        # 6. A later bandit promotion changes the serving identity: the promoted
        #    supervised candidate falls back to base until it is retrained.
        bandit_promote(6)
        self.lease(mapping_sha256=mapping_sha, revisions=revisions)
        rebanded = self.serve([probe], learning=True)
        rebanded_body = rebanded['responses'][0]['body']
        self.assertEqual(rebanded_body['model_profile'], PROFILE)
        self.assertEqual(rebanded_body['mapping_metadata']['feedback_bandit']['sequence'], 6)
        self.lease(mapping_sha256=mapping_sha, revisions=revisions)
        status = self.bridge('learning_activation_cli.py', 'status')
        self.assertIsNone(status['wp13']['effective'])
        self.assertEqual(status['wp13']['fallback_reason'], 'selected_incompatible_or_unauthorized')
        self.assertEqual(rebanded['profiles']['serving_identity_sha256'],
                         status['wp13']['serving']['baseline']['serving_identity_sha256'])
        self.assertIsNone(rebanded['profiles']['learning']['effective_sha256'])
        self.assertIsNone(status['wp13']['effective_sha256'])

        # 7. Rollbacks, erasure and the kill switch.
        rolled = self.bridge('learning_production_cli.py', 'rollback', '--candidate', 'base',
                             '--actor', 'admin:1', '--reason', 'wiring test')
        self.assertEqual(rolled['status'], 'rolled_back')
        self.assertEqual(self.bridge('learning_production_cli.py', 'erase', '--subject', self.subjects[0])['status'],
                         'erased')
        self.lease(revisions=revisions)
        self.bridge('learning_activation_cli.py', 'bandit-rollback', '--actor', 'admin:1', '--reason', 'wiring test')
        self.lease(revisions=revisions)
        restored = self.serve([probe], learning=True)['responses'][0]['body']
        self.assertEqual(restored['model_profile'], PROFILE)
        self.assertEqual(restored['mapping_metadata']['serving_identity']['serving_identity_sha256'], bandit_identity)
        killed = self.serve([probe], learning=False)['responses'][0]['body']
        self.assertEqual(killed['esrs'], off_body['esrs'])
        self.assertNotIn('feedback_bandit', killed['mapping_metadata'])

    def test_effective_parity_detects_unreadable_registry_with_feedback_policy_off(self):
        # Without the feedback policy the base identity does not depend on the
        # state root, so only the effective candidate shows this drift.
        self.bandit_mode = 'off'
        probe, held_input = company(150), company(777, sector_list=['G'], products_services=['C'])
        self.lease()
        fresh = self.serve([probe, held_input], learning=True)
        self.assertIs(fresh['profiles']['learning']['registry_readable'], False, 'no candidate registered yet')
        self.assertIsNone(fresh['profiles']['learning']['effective_sha256'])
        trained, mapping_sha = self.train_and_promote(held_input, fresh['responses'][1]['body']['esrs'])
        self.lease(mapping_sha256=mapping_sha)
        panel = self.bridge('learning_activation_cli.py', 'status')['wp13']
        self.assertEqual(panel['effective'], trained['candidate_id'])
        self.assertRegex(panel['effective_sha256'], r'^[a-f0-9]{64}$')

        self.lease(mapping_sha256=mapping_sha)
        served = self.serve([probe], learning=True)
        self.assertEqual(served['responses'][0]['body']['model_profile'], 'learning:' + trained['candidate_id'])
        self.assertIs(served['profiles']['learning']['registry_readable'], True)
        self.assertEqual(served['profiles']['learning']['effective_sha256'], panel['effective_sha256'])

        # AI service unit without LEARNING_ENGINE_ROOT: base is served silently,
        # while the runtime fingerprint and the base identity still match.
        self.lease(mapping_sha256=mapping_sha)
        drifted = self.serve([probe], learning=True, drop=('LEARNING_ENGINE_ROOT',))
        self.assertEqual(drifted['responses'][0]['body']['model_profile'], PROFILE)
        self.assertEqual(drifted['health']['learning']['runtime_sha256'], self.bridge_runtime_sha256())
        self.assertEqual(drifted['profiles']['serving_identity_sha256'],
                         panel['serving']['baseline']['serving_identity_sha256'])
        self.assertIs(drifted['profiles']['learning']['registry_readable'], False)
        self.assertIsNone(drifted['profiles']['learning']['effective_sha256'])
        self.assertNotEqual(drifted['profiles']['learning']['effective_sha256'], panel['effective_sha256'])


if __name__ == '__main__':
    unittest.main()
