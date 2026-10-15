"""Synthetic activation tests; no real training, accounts or model promotion."""
import copy
import json
import os
import subprocess
import sys
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

from feedback_bandit import FeedbackBandit, digest, load_feedback_bandit
from feedback_bandit_registry import BanditRegistry, atomic
from learning_activation import bandit_mode, flag

SUBJECT = '12345678-1234-4234-8234-123456789abc'
OTHER = '22345678-1234-4234-8234-123456789abc'
TOPIC = 'esrs_e1_energy'
EXPIRES_AT = int(time.time()) + 86400


def initial():
    return FeedbackBandit('synthetic', [TOPIC], ['sector_C'], 'a' * 64, shadow=False)


def row(index=1, subject=SUBJECT):
    return {'id': digest(['synthetic', index]), 'subject': subject, 'stream': digest(subject), 'revision': '1:1:synthetic', 'expires_at': EXPIRES_AT,
            'event': {'version': 'p8-bandit-event-v1', 'profile': 'synthetic', 'model_sha256': 'a' * 64,
                      'feature_schema_sha256': digest(['sector_C']), 'encoder': 'p5-hash-tanh-v1',
                      'topic': TOPIC, 'context': [0.] * 16, 'base_score': .95, 'proposed': True, 'accepted': True}}


class BanditActivationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.registry = BanditRegistry(self.root)
        self.lease()

    def tearDown(self):
        self.temp.cleanup()

    def lease(self, subjects=(SUBJECT, OTHER), revisions=None):
        atomic(self.root / 'authority.json', {'expires_at': time.time() + 90, 'subjects': list(subjects), 'bandit_subjects': list(subjects),
            'bandit_revisions': revisions if revisions is not None else {digest(s): '1:1:synthetic' for s in subjects}})

    def sync(self, rows=None):
        return self.registry.sync(initial(), [row()] if rows is None else rows)

    def test_candidate_does_not_change_active(self):
        result = self.sync()
        self.assertEqual(result['candidate']['events_applied'], 1)
        self.assertIsNone(result['active'])
        self.assertIsNone(self.registry.active())

    def test_retry_is_idempotent_and_prefix_is_incremental(self):
        first = self.sync()
        self.assertEqual(first, self.sync())
        original = FeedbackBandit.update_event
        applied = []
        def spy(learner, event):
            applied.append(event['sequence'])
            return original(learner, event)
        with patch.object(FeedbackBandit, 'update_event', spy):
            second = self.sync([row(), row(2)])
        self.assertEqual(applied, [2])
        self.assertNotEqual(first['candidate']['id'], second['candidate']['id'])
        self.assertEqual(second['candidate']['events_applied'], 2)
        self.assertEqual(len(list(self.root.glob('bandit-*.json'))), 3)  # catalogue + two immutable versions

    def test_manual_promote_then_rollback_restores_previous_hash(self):
        first = self.sync()['candidate']
        self.registry.select('promote', first['id'], 'admin:1', 'synthetic test')
        second = self.sync([row(), row(2)])['candidate']
        self.assertEqual(self.registry.active().sequence, 1)
        self.registry.select('promote', second['id'], 'admin:1', 'synthetic test')
        self.assertEqual(self.registry.active().sequence, 2)
        self.registry.select('promote', second['id'], 'admin:1', 'synthetic repeated promotion')
        self.assertEqual(self.registry.summary()['previous']['id'], first['id'])
        result = self.registry.select('rollback', None, 'admin:1', 'synthetic test')
        self.assertEqual(result['active']['sha256'], first['sha256'])
        audit = self.registry.catalogue()['audit'][-1]
        self.assertEqual(audit['before'], second['sha256'])
        self.assertEqual(audit['after'], first['sha256'])

    def test_manual_only_and_stale_candidate_rejected(self):
        first = self.sync()['candidate']
        self.sync([row(), row(2)])
        for candidate, actor in [(first['id'], 'admin:1'), (first['id'], 'system')]:
            with self.assertRaises(ValueError):
                self.registry.select('promote', candidate, actor, 'synthetic')
        self.assertIsNone(self.registry.active())

    def test_withdrawal_invalidates_active_and_purges_derived_history(self):
        first = self.sync([row(), row(2, OTHER)])['candidate']
        self.registry.select('promote', first['id'], 'admin:1', 'synthetic')
        self.lease(subjects=(OTHER,))
        with self.assertRaises(ValueError):
            self.registry.active()
        self.registry.maintain()
        self.assertFalse((self.root / ('bandit-' + first['id'] + '.json')).exists())
        rebuilt = self.sync([row(2, OTHER)])
        self.assertEqual(rebuilt['candidate']['events_applied'], 1)
        self.assertIsNone(rebuilt['active'])
        with self.assertRaises(ValueError):
            self.sync([row()])

    def test_correction_rebuilds_and_stale_revision_cannot_serve(self):
        first = self.sync()['candidate']
        self.registry.select('promote', first['id'], 'admin:1', 'synthetic')
        corrected = row(2)
        corrected['revision'] = '1:2:synthetic'
        corrected['event']['accepted'] = False
        self.lease(revisions={digest(SUBJECT): '1:2:synthetic'})
        with self.assertRaises(ValueError):
            self.registry.active()
        result = self.sync([corrected])
        self.assertEqual(result['candidate']['events_applied'], 1)
        self.assertNotEqual(result['candidate']['sha256'], first['sha256'])

    def test_invalid_event_and_duplicate_id_never_commit_partial_batch(self):
        first = self.sync()
        invalid = row(2)
        invalid['event']['proposed'] = False
        invalid['event']['accepted'] = False
        for rows in ([row(), invalid], [row(), row()]):
            with self.assertRaises(ValueError):
                self.sync(rows)
            self.assertEqual(self.registry.summary(), first)

    def test_interrupted_catalogue_commit_keeps_previous_candidate(self):
        first = self.sync()
        with patch('feedback_bandit_registry.atomic', side_effect=OSError('synthetic disk failure')):
            with self.assertRaises(OSError):
                self.sync([row(), row(2)])
        self.assertEqual(self.registry.summary(), first)
        self.registry.maintain()
        self.assertEqual(len(list(self.root.glob('bandit-*.json'))), 2)

    def test_checksum_corruption_cannot_be_promoted(self):
        candidate = self.sync()['candidate']
        path = self.root / ('bandit-' + candidate['id'] + '.json')
        doc = json.loads(path.read_bytes()); doc['payload']['sequence'] = 900
        atomic(path, doc)
        with self.assertRaises(ValueError):
            self.registry.select('promote', candidate['id'], 'admin:1', 'synthetic')

    def test_expired_lease_and_expired_state_fail_closed(self):
        candidate = self.sync()['candidate']
        self.registry.select('promote', candidate['id'], 'admin:1', 'synthetic')
        with patch('feedback_bandit_registry.time.time', return_value=time.time() + 100):
            with self.assertRaises(ValueError):
                self.registry.active()
        cat = self.registry.catalogue()
        cat['versions'][candidate['id']]['created_at'] = time.time() - 31 * 86400
        atomic(self.registry.path, cat)
        with self.assertRaises(ValueError):
            self.registry.active()
        self.registry.maintain()
        self.assertIsNone(self.registry.summary()['active'])

    def load(self):
        return load_feedback_bandit('synthetic', feature_columns=['sector_C'], model_sha256='a' * 64, topics=[TOPIC])

    def test_serving_loads_only_promoted_version_and_bootstraps_empty_basis(self):
        env = {'LEARNING_ENGINE_AUTHORITY': 'consent', 'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_BANDIT_MODE': 'active', 'LEARNING_ENGINE_ROOT': str(self.root)}
        with patch.dict(os.environ, env):
            self.assertEqual(self.load().sequence, 0)
            candidate = self.sync()['candidate']
            self.assertEqual(self.load().sequence, 0)
            self.registry.select('promote', candidate['id'], 'admin:1', 'synthetic')
            self.assertEqual(self.load().envelope()['sha256'], candidate['sha256'])
            with patch.dict(os.environ, {'LEARNING_BANDIT_MODE': 'off'}):
                self.assertIsNone(self.load())
            with patch.dict(os.environ, {'LEARNING_BANDIT_MODE': 'shadow'}):
                self.assertTrue(self.load().config['shadow'])
            (self.root / 'authority.json').unlink()
            self.assertIsNone(self.load())

    def test_missing_directory_and_symlink_are_rejected(self):
        with self.assertRaises(ValueError):
            BanditRegistry(self.root / 'absent')
        alias = self.root / 'alias'; alias.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(ValueError):
            BanditRegistry(alias)

    def test_deployed_defaults_and_explicit_off(self):
        with patch.dict(os.environ, {'APP_ENV': 'production'}, clear=True):
            self.assertTrue(flag('LEARNING_ENGINE_ENABLED'))
            self.assertEqual(bandit_mode(), 'active')
            with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'false', 'LEARNING_BANDIT_MODE': 'off'}):
                self.assertFalse(flag('LEARNING_ENGINE_ENABLED'))
                self.assertEqual(bandit_mode(), 'off')
        with patch.dict(os.environ, {'APP_ENV': 'testing'}, clear=True):
            self.assertFalse(flag('LEARNING_ENGINE_ENABLED'))
            self.assertEqual(bandit_mode(), 'off')

    def test_local_cli_status_and_failure_protocol(self):
        source = Path(__file__).resolve().parents[1]
        command = [sys.executable, '-B', str(source / 'learning_activation_cli.py'), 'status', '--root', str(self.root)]
        env = {'PATH': os.environ['PATH'], 'PYTHONPATH': str(source), 'PYTHONDONTWRITEBYTECODE': '1'}
        result = subprocess.run(command, env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        status = json.loads(result.stdout)
        self.assertTrue(status['python'])
        self.assertIsNone(status['wp23']['active'])
        command[-1] = str(self.root / 'absent')
        result = subprocess.run(command, env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(json.loads(result.stderr)['status'], 'failed_closed')
        self.assertNotIn(str(self.root), result.stderr)

    def test_concurrent_retry_serializes_one_version(self):
        source = Path(__file__).resolve().parents[1]
        atomic(self.root / 'synthetic-input.json', [row()])
        script = """
import json, sys
from pathlib import Path
from feedback_bandit import FeedbackBandit
from feedback_bandit_registry import BanditRegistry
root = Path(sys.argv[1])
model = FeedbackBandit('synthetic', ['esrs_e1_energy'], ['sector_C'], 'a' * 64, shadow=False)
BanditRegistry(root).sync(model, json.loads((root / 'synthetic-input.json').read_text()))
"""
        env = {'PATH': os.environ['PATH'], 'PYTHONPATH': str(source), 'PYTHONDONTWRITEBYTECODE': '1', 'OPENBLAS_NUM_THREADS': '1'}
        children = [subprocess.Popen([sys.executable, '-B', '-c', script, str(self.root)], env=env,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(2)]
        for child in children:
            _, error = child.communicate(timeout=30)
            self.assertEqual(child.returncode, 0, error)
        self.assertEqual(len(self.registry.catalogue()['versions']), 1)
        self.assertEqual(self.registry.summary()['candidate']['events_applied'], 1)
