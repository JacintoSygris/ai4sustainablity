"""Synthetic fixtures only: operational plumbing, not model quality evidence."""
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import time
import unittest
import uuid
from unittest.mock import patch

from services.datatypes import CompanyData, Prediction
from learning_production_registry import (ProductionCandidateRegistry, BASE_PROFILE,
    digest, encoded, evaluate, read_fixed)
from learning_production_runtime import apply_learning
from wp13_feedback_bridge import production_rows
from wp13_feedback_training import train_production_feedback, production_features

KEY = 'esrs_e1_energy_use'
OTHER = 'esrs_e2_pollution_of_air'
IDENTITY = 'a' * 64
REQUESTER = str(uuid.UUID(int=100))


def company(n=10):
    return {'company_name': 'pseudonymous', 'model_profile': BASE_PROFILE, 'learning_shadow_authorized': True,
            'learning_shadow_subject': REQUESTER,
            'sector_list': ['Manufacturing'], 'subsidiaries_regions': ['EU'],
            'products_services': ['Manufacturing'], 'headquarters_country': 'Spain',
            'num_subsidiaries_countries': 0, 'employees_total': n,
            'annual_turnover_million_euro': 1.0, 'stock_listed': False, 'reporting_currency': 'EUR'}


def baseline(c, positive=0):
    return Prediction(esrs={KEY: positive, OTHER: 1}, model_profile=BASE_PROFILE,
        mapping_metadata={'serving_identity': {'serving_identity_sha256': IDENTITY}})


def dataset():
    return {'schema': 'learning-feedback-production-v1', 'rejected_rows': 0, 'rows': [
        {'subject': str(uuid.UUID(int=i + 1)), 'synthetic': True,
         'group': hashlib.sha256(('group' + str(i)).encode()).hexdigest(),
         'authority_binding': hashlib.sha256(('binding' + str(i)).encode()).hexdigest(),
         'consent_version': 'learning-feedback-bandit-2026-10-08', 'p5_sha256': hashlib.sha256(str(i).encode()).hexdigest(),
         'generation': 1, 'p8_revision': 1, 'p9_revision': 1, 'input': company(i + 1),
         'topics': {'1': {'mask': 1, 'value': int(i >= 4)}, '2': {'mask': 0, 'value': None}},
         'p9': [{'datapoint_id': 'SYNTHETIC-ID', 'relevant': True, 'selected_to_answer': False}]}
        for i in range(8)]}


def mapping():
    return {'keys': [{'status': 'approved', 'python_esrs_key': KEY, 'ar16_topic_ids': [1]},
                     {'status': 'approved', 'python_esrs_key': OTHER, 'ar16_topic_ids': [2]}]}


def fixed():
    return {'heldout': ({'schema': 'learning-heldout-v1', 'cases': [
        {'group': 'synthetic-heldout-group', 'p5_sha256': 'b' * 64,
         'input': company(100), 'truth': {KEY: 1, OTHER: 1}}]}, 'c' * 64)}


class Constant:
    def __init__(self, value): self.value = value
    def predict(self, company, base): return {**base, KEY: self.value}


class LearningProductionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'registry'
        self.registry = ProductionCandidateRegistry(self.root, create=True)
        self.data = dataset()
        self.model, self.training = train_production_feedback(self.data, mapping())
        self.subjects = [r['subject'] for r in self.data['rows']]
        self.bindings = {r['subject']: r['authority_binding'] for r in self.data['rows']}
        self.lease()

    def lease(self, subjects=None, expiry=None):
        (self.root / 'authority.json').write_bytes(encoded({
            'subjects': self.subjects + [REQUESTER] if subjects is None else subjects,
            'bindings': self.bindings,
            'mapping_sha256': digest(mapping()),
            'expires_at': time.time() + 90 if expiry is None else expiry}))

    def register(self, synthetic=True, passed=True):
        gate = evaluate(self.model, fixed(), baseline)
        gate['passed'] = passed
        return self.registry.register(self.model, {
            'dataset_sha256': digest(self.data), 'mapping_sha256': digest(mapping()),
            'authority_bindings': self.bindings,
            'code_commit': 'd' * 40, 'subjects': self.subjects, 'groups': [r['group'] for r in self.data['rows']], 'training_input_sha256': [],
            'synthetic': synthetic, 'training': self.training, 'gate': gate,
            'baseline_identity': IDENTITY, 'compared_active': None})

    def activate(self, candidate):
        manifest, _ = self.registry.load(candidate)
        self.registry.activate(candidate, 'admin:1', manifest['gate'], self.registry.pointer('active'))

    def test_off_by_default_never_opens_registry(self):
        result = baseline(company())
        with patch.dict(os.environ, {}, clear=True), patch('learning_production_runtime.ProductionCandidateRegistry') as registry:
            self.assertIs(apply_learning(CompanyData(**company()), result), result)
            registry.assert_not_called()

    def test_masked_training_uses_known_singleton_keys_only(self):
        self.assertEqual(self.model.keys, [KEY])
        self.assertEqual(self.training['known_cells'], 8)
        self.assertEqual(self.training['p9_review_decisions'], 8)
        result = self.model.predict(company(1), {KEY: 0, OTHER: 1})
        self.assertEqual(result[OTHER], 1)
        self.assertNotIn('company_name', production_features(company()))
        changed = company(); changed['company_name'] = 'ignored test name'
        self.assertEqual(production_features(changed), production_features(company()))

    def test_unknown_cell_never_becomes_negative(self):
        data = dataset(); data['rows'][0]['topics']['1'] = {'mask': 0, 'value': None}
        _, receipt = train_production_feedback(data, mapping())
        self.assertEqual(receipt['known_cells'], 7)
        self.assertEqual(receipt['unknown_cells_excluded'], 1)

    def test_real_sources_require_explicit_mode_and_consent_version(self):
        data = dataset(); data['rows'][0]['synthetic'] = False
        with self.assertRaises(ValueError): production_rows(data, mapping())
        production_rows(data, mapping(), synthetic_only=False)
        data['rows'][0]['consent_version'] = 'old'
        with self.assertRaises(ValueError): production_rows(data, mapping(), synthetic_only=False)

    def test_bridge_rejects_identity_unknown_fields_duplicate_subjects_and_invalid_masks(self):
        changes = [lambda d: d['rows'][0]['input'].update(company_name='private'),
                   lambda d: d['rows'][0].update(email='private'),
                   lambda d: d['rows'][1].update(subject=d['rows'][0]['subject']),
                   lambda d: d['rows'][0]['topics']['1'].update(mask=0),
                   lambda d: d['rows'][0]['p9'][0].update(note='private')]
        for change in changes:
            data = dataset(); change(data)
            with self.subTest(change=changes.index(change)), self.assertRaises(ValueError):
                production_rows(data, mapping())

    def test_zero_recall_loss_blocks_each_report_even_if_aggregate_can_improve(self):
        gate = evaluate(Constant(0), fixed(), lambda c: baseline(c, 1))
        self.assertFalse(gate['passed'])
        self.assertEqual(gate['sets'][0]['lost_true_positives'], 1)
        self.assertTrue(evaluate(Constant(1), fixed(), lambda c: baseline(c, 1))['passed'])

    def test_gate_also_protects_current_served_candidate(self):
        self.assertFalse(evaluate(Constant(0), fixed(), baseline, comparator=Constant(1))['passed'])

    def test_gold_oos_is_evaluated_and_bad_gold_blocks(self):
        sets = fixed(); sets['gold_oos'] = copy.deepcopy(sets['heldout'])
        sets['gold_oos'][0]['cases'][0]['input']['employees_total'] = 200
        gate = evaluate(Constant(0), sets, lambda c: baseline(c, int(c.employees_total == 200)))
        self.assertEqual(gate['gold_oos'], 'evaluated'); self.assertFalse(gate['passed'])

    def test_gate_rejects_overlap_empty_missing_axis_and_vacuous_sets(self):
        for change in [lambda s: s['heldout'][0].update(cases=[]),
                       lambda s: s['heldout'][0]['cases'][0].update(truth={KEY: 1}),
                       lambda s: s['heldout'][0]['cases'][0].update(truth={KEY: 0, OTHER: 0})]:
            sets = fixed(); change(sets)
            with self.assertRaises(ValueError): evaluate(Constant(1), sets, baseline)
        with self.assertRaises(ValueError):
            evaluate(Constant(1), fixed(), baseline, excluded_subjects=['synthetic-heldout-group'])
        with self.assertRaises(ValueError):
            evaluate(Constant(1), fixed(), baseline, excluded_inputs=['b' * 64])

    def test_register_does_not_promote_and_provenance_roundtrips(self):
        candidate = self.register()
        self.assertIsNone(self.registry.pointer('active'))
        self.assertEqual(self.registry.pointer('shadow'), candidate)
        manifest, model = self.registry.load(candidate)
        self.assertEqual(manifest['dataset_sha256'], digest(self.data))
        self.assertEqual(manifest['code_commit'], 'd' * 40)
        self.assertEqual(model.predict(company(), baseline(company()).esrs), self.model.predict(company(), baseline(company()).esrs))

    def test_synthetic_and_no_go_candidates_cannot_be_promoted(self):
        for synthetic, passed in [(True, True), (False, False)]:
            candidate = self.register(synthetic, passed)
            with self.assertRaises(ValueError): self.activate(candidate)
        self.assertIsNone(self.registry.pointer('active'))

    def test_manual_promotion_audit_and_rollback_are_independent_of_later_batches(self):
        first = self.register(False); self.activate(first)
        second = self.register(False); self.activate(second)
        self.register(False)  # Later batch must not break rollback.
        self.registry.rollback(first, 'admin:1', IDENTITY)
        self.assertEqual(self.registry.pointer('active'), first)
        self.registry.rollback(None, 'admin:1', IDENTITY)
        self.assertIsNone(self.registry.pointer('active'))
        with self.registry.connect(readonly=True) as db:
            self.assertEqual(db.execute("SELECT count(*) FROM audit WHERE action='promoted'").fetchone()[0], 2)
            self.assertEqual(db.execute("SELECT actor FROM audit WHERE action='rollback' LIMIT 1").fetchone()[0], 'admin:1')

    def test_stale_promoter_and_unpromoted_rollback_fail(self):
        first = self.register(False); second = self.register(False); self.activate(first)
        manifest, _ = self.registry.load(second)
        with self.assertRaises(ValueError): self.registry.activate(second, 'admin:1', manifest['gate'], None)
        with self.assertRaises(ValueError): self.registry.rollback(second, 'admin:1', IDENTITY)

    def test_changed_fixed_set_cannot_pass_promotion(self):
        candidate = self.register(False); manifest, _ = self.registry.load(candidate)
        changed = copy.deepcopy(manifest['gate']); changed['fixed_sha256']['heldout'] = 'f' * 64
        with self.assertRaises(ValueError): self.registry.activate(candidate, 'admin:1', changed, None)

    def test_corrupt_pickle_is_rejected_before_deserialization(self):
        candidate = self.register(False)
        with self.registry.connect() as db: db.execute('UPDATE candidates SET model=? WHERE id=?', (b'bad', candidate))
        with patch('joblib.load') as loader, self.assertRaises(ValueError): self.registry.load(candidate)
        loader.assert_not_called()

    def test_expired_missing_and_withdrawn_authority_fail_closed(self):
        candidate = self.register(False)
        for subjects, expiry in [([], time.time() + 80), (self.subjects, time.time() - 1), (self.subjects, time.time() + 500)]:
            self.lease(subjects, expiry)
            with self.assertRaises(ValueError): self.registry.load(candidate)
        (self.root / 'authority.json').unlink()
        with self.assertRaises(FileNotFoundError): self.registry.load(candidate)

    def test_changed_laravel_mapping_revokes_candidate_consumption(self):
        candidate = self.register(False)
        path = self.root / 'authority.json'
        value = json.loads(path.read_bytes()); value['mapping_sha256'] = 'f' * 64
        path.write_bytes(encoded(value))
        with self.assertRaises(ValueError): self.registry.load(candidate)

    def test_erasure_removes_models_pointers_shadow_and_defeats_inflight_registration(self):
        candidate = self.register(False); self.activate(candidate)
        self.registry.shadow(candidate, {KEY: 1}, {KEY: 0}, REQUESTER)
        self.registry.erase(self.subjects[0])
        self.assertIsNone(self.registry.pointer('active'))
        with self.assertRaises(ValueError): self.registry.load(candidate)
        with self.assertRaises(ValueError): self.register(False)
        with self.registry.connect(readonly=True) as db:
            self.assertEqual(db.execute('SELECT model,manifest FROM candidates WHERE id=?', (candidate,)).fetchone()[:], (b'', '{}'))
            self.assertEqual(db.execute('SELECT count(*) FROM shadow').fetchone()[0], 0)

    def test_shadow_logs_both_predictions_and_preserves_served_response(self):
        self.register(False)
        result = baseline(company())
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_ROOT': str(self.root),
                                    'LEARNING_ENGINE_SHADOW_ENABLED': 'true', 'LEARNING_ENGINE_SERVING_ENABLED': 'false'}):
            self.assertIs(apply_learning(CompanyData(**company()), result), result)
        with self.registry.connect(readonly=True) as db:
            row = db.execute('SELECT served,candidate FROM shadow').fetchone()
            self.assertEqual(json.loads(row['served']), result.esrs)
            self.assertEqual(set(json.loads(row['candidate'])), set(result.esrs))

    def test_runtime_uses_active_registry_and_falls_back_on_drift_or_erasure(self):
        candidate = self.register(False); self.activate(candidate)
        result = baseline(company())
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_ROOT': str(self.root),
                                    'LEARNING_ENGINE_SERVING_ENABLED': 'true', 'LEARNING_ENGINE_SHADOW_ENABLED': 'false'}):
            self.assertEqual(apply_learning(CompanyData(**company()), result).model_profile, 'learning:' + candidate)
            drifted = result.model_copy(deep=True)
            drifted.mapping_metadata['serving_identity']['serving_identity_sha256'] = 'f' * 64
            self.assertIs(apply_learning(CompanyData(**company()), drifted), drifted)
            self.registry.erase(self.subjects[0])
            self.assertIs(apply_learning(CompanyData(**company()), result), result)

    def test_shadow_requires_request_specific_laravel_consent(self):
        self.register(False)
        value = company(); value['learning_shadow_authorized'] = False
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_ROOT': str(self.root),
                                    'LEARNING_ENGINE_SHADOW_ENABLED': 'true', 'LEARNING_ENGINE_SERVING_ENABLED': 'false'}):
            apply_learning(CompanyData(**value), baseline(value))
        with self.registry.connect(readonly=True) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM shadow').fetchone()[0], 0)

    def test_fixed_bytes_and_local_root_are_pinned(self):
        path = Path(self.temp.name) / 'fixed.json'; path.write_bytes(encoded({'test': True}))
        sha = hashlib.sha256(path.read_bytes()).hexdigest()
        self.assertEqual(read_fixed(path, sha), {'test': True})
        path.write_text('{}')
        with self.assertRaises(ValueError): read_fixed(path, sha)
        alias = Path(self.temp.name) / 'alias'; alias.symlink_to(self.root)
        with self.assertRaises(ValueError): ProductionCandidateRegistry(alias)


    def test_gold_standard_axis_preserves_standard_recall(self):
        sets = fixed()
        gold = copy.deepcopy(sets['heldout'][0]); gold['unit'] = 'standards'
        gold['cases'][0]['truth'] = {'E1': 1, 'E2': 1}
        sets['gold_oos'] = (gold, 'e' * 64)
        self.assertFalse(evaluate(Constant(0), sets, lambda c: baseline(c, 1))['passed'])
        self.assertTrue(evaluate(Constant(1), sets, lambda c: baseline(c, 1))['passed'])

    def test_expired_candidates_are_erased_by_maintenance(self):
        candidate = self.register(False)
        with patch('learning_production_registry.time.time', return_value=time.time() + 31 * 86400):
            self.registry.erase()
        with self.assertRaises(ValueError): self.registry.load(candidate)

    def test_system_actor_cannot_promote_or_rollback(self):
        candidate = self.register(False); manifest, _ = self.registry.load(candidate)
        with self.assertRaises(ValueError): self.registry.activate(candidate, 'system', manifest['gate'], None)
        with self.assertRaises(ValueError): self.registry.rollback(None, 'system', IDENTITY)

    def test_cli_trains_evaluates_registers_promotes_and_rolls_back_with_pinned_files(self):
        from argparse import Namespace
        import learning_production_cli as cli
        # These simulate consented records; every datum remains a test fixture.
        data = dataset()
        for row in data['rows']: row['synthetic'] = False
        args = Namespace(operation='train', root=str(self.root), actor='admin:1', candidate=None,
                         subject=None, code_commit='d' * 40, gold=None, gold_sha256=None)
        for field, document in [('dataset', data), ('mapping', mapping()), ('heldout', fixed()['heldout'][0])]:
            path = Path(self.temp.name) / (field + '.json'); path.write_bytes(encoded(document))
            setattr(args, field, str(path)); setattr(args, field + '_sha256', hashlib.sha256(path.read_bytes()).hexdigest())
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_AUTHORITY': 'consent',
                                     'LEARNING_ENGINE_SYNTHETIC_ONLY': 'false'}), patch.object(cli, 'baseline', baseline):
            receipt = cli.execute(args)
            self.assertEqual(receipt['status'], 'registered')
            self.assertEqual(receipt['training']['training_rows'], 8)
            self.assertIsNone(self.registry.pointer('active'))
            args.candidate = receipt['candidate_id']; args.operation = 'promote'
            self.assertEqual(cli.execute(args)['status'], 'promoted')
            self.assertEqual(self.registry.pointer('active'), args.candidate)
            args.operation = 'rollback'; args.candidate = 'base'
            with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'false'}):
                self.assertEqual(cli.execute(args)['profile'], BASE_PROFILE)
            self.assertIsNone(self.registry.pointer('active'))

    def test_cli_rejects_heldout_training_group_before_fit(self):
        from argparse import Namespace
        import learning_production_cli as cli
        data = dataset(); document = fixed()['heldout'][0]
        document['cases'][0]['group'] = data['rows'][0]['group']
        args = Namespace(operation='train', root=str(self.root), actor='admin:1', candidate=None,
                         subject=None, code_commit='d' * 40, gold=None, gold_sha256=None)
        for field, value in [('dataset', data), ('mapping', mapping()), ('heldout', document)]:
            path = Path(self.temp.name) / (field + '.json'); path.write_bytes(encoded(value))
            setattr(args, field, str(path)); setattr(args, field + '_sha256', hashlib.sha256(path.read_bytes()).hexdigest())
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_AUTHORITY': 'consent'}), \
                patch.object(cli, 'train_production_feedback') as fit, patch.object(cli, 'baseline', baseline):
            with self.assertRaises(ValueError): cli.execute(args)
            fit.assert_not_called()

    def test_training_only_configuration_never_opens_registry_or_changes_serving(self):
        # Public runtime has a single base profile; serving OFF keeps the base output.
        import pandas as pd
        from services import service_predict as predictor
        from services.datatypes import FeatureMetadata
        from unittest.mock import Mock
        value = company(); value.pop('model_profile')
        classifier = Mock(); classifier.predict.return_value = [[1]]
        policies = predictor.ServingPolicies(.95, None, None, None, {}, None)
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true',
                'LEARNING_ENGINE_SERVING_ENABLED': 'false', 'LEARNING_ENGINE_SHADOW_ENABLED': 'false',
                'I4S_AI_MODEL_PROFILE': BASE_PROFILE}), \
                patch.object(predictor, 'load_profile_classifier', return_value=classifier) as load, \
                patch.object(predictor, 'load_serving_policies', return_value=policies), \
                patch.object(predictor, 'load_new_format_data', return_value=(pd.DataFrame([{}]), FeatureMetadata())), \
                patch.object(predictor, 'load_profile_esrs_columns', return_value=[KEY]), \
                patch.object(predictor, 'new_format_positive_scores', return_value=[.99]), \
                patch.object(predictor, 'model_serving_identity', return_value={}), \
                patch('learning_production_runtime.ProductionCandidateRegistry') as registry:
            result = predictor.predict_esrs(CompanyData(**value))
            self.assertEqual(result.model_profile, BASE_PROFILE)
            self.assertEqual(result.esrs, {KEY: 1})
            self.assertEqual(load.call_args.args[0].name, BASE_PROFILE)
            registry.assert_not_called()

    def test_requester_erasure_removes_shadow_independently_of_training_membership(self):
        candidate = self.register(False); self.activate(candidate)
        self.assertNotIn(REQUESTER, self.subjects)
        self.registry.shadow(candidate, {KEY: 1}, {KEY: 0}, REQUESTER)
        self.registry.shadow(candidate, {KEY: 1}, {KEY: 0}, self.subjects[0])
        self.registry.erase(REQUESTER)
        # Even an unexpired old lease cannot revive the withdrawn UUID.
        with self.assertRaises(ValueError):
            self.registry.shadow(candidate, {KEY: 1}, {KEY: 0}, REQUESTER)
        self.registry.load(candidate)
        self.assertEqual(self.registry.pointer('active'), candidate)
        with self.registry.connect(readonly=True) as db:
            self.assertEqual([r[0] for r in db.execute('SELECT subject FROM shadow')], [self.subjects[0]])

    def test_shadow_rechecks_requester_lease_at_write_time(self):
        candidate = self.register(False)
        self.lease(self.subjects)
        with self.assertRaises(ValueError):
            self.registry.shadow(candidate, {}, {}, REQUESTER)
        with self.registry.connect(readonly=True) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM shadow').fetchone()[0], 0)

    def test_old_shadow_schema_is_migrated_and_unowned_records_are_purged(self):
        with self.registry.connect() as db:
            db.execute('ALTER TABLE shadow RENAME TO legacy_shadow')
            db.execute('CREATE TABLE shadow (id INTEGER PRIMARY KEY, at REAL, candidate_id TEXT, served TEXT, candidate TEXT)')
            db.execute("INSERT INTO shadow VALUES(1,0,'candidate','{}','{}')")
        ProductionCandidateRegistry(self.root, create=True)
        with self.registry.connect(readonly=True) as db:
            self.assertIn('subject', [r['name'] for r in db.execute('PRAGMA table_info(shadow)')])
            self.assertEqual(db.execute('SELECT count(*) FROM shadow').fetchone()[0], 0)

    def test_revoked_or_reassigned_membership_blocks_registration_promotion_and_serving(self):
        candidate = self.register(False); self.activate(candidate)
        pending = self.register(False)
        manifest, _ = self.registry.load(pending)
        for binding in [None, 'f' * 64]:
            self.lease()
            path = self.root / 'authority.json'; lease = json.loads(path.read_bytes())
            if binding is None: lease['bindings'].pop(self.subjects[0])
            else: lease['bindings'][self.subjects[0]] = binding
            path.write_bytes(encoded(lease))
            with self.assertRaises(ValueError): self.register(False)
            with self.assertRaises(ValueError):
                self.registry.activate(pending, 'admin:1', manifest['gate'], candidate)
            with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_ROOT': str(self.root),
                    'LEARNING_ENGINE_SERVING_ENABLED': 'true', 'LEARNING_ENGINE_SHADOW_ENABLED': 'false'}):
                result = baseline(company())
                self.assertIs(apply_learning(CompanyData(**company()), result), result)

    def test_cli_upgrade_fallback_allows_training_and_promotion_without_changing_pointer_early(self):
        from argparse import Namespace
        import learning_production_cli as cli
        from learning_production_registry import code_hashes
        old = self.register(False); self.activate(old)
        data = dataset()
        for row in data['rows']: row['synthetic'] = False
        args = Namespace(operation='train', root=str(self.root), actor='admin:1', candidate=None,
                         subject=None, code_commit='d' * 40, gold=None, gold_sha256=None)
        for field, document in [('dataset', data), ('mapping', mapping()), ('heldout', fixed()['heldout'][0])]:
            path = Path(self.temp.name) / (field + '.json'); path.write_bytes(encoded(document))
            setattr(args, field, str(path)); setattr(args, field + '_sha256', hashlib.sha256(path.read_bytes()).hexdigest())
        upgraded = {**code_hashes(), 'upgrade_fixture': 'f' * 64}
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_AUTHORITY': 'consent',
                'LEARNING_ENGINE_SYNTHETIC_ONLY': 'false'}), patch.object(cli, 'baseline', baseline), \
                patch('learning_production_registry.code_hashes', return_value=upgraded):
            with self.assertRaises(ValueError): self.registry.load(old)
            receipt = cli.execute(args)
            self.assertEqual(receipt['status'], 'registered')
            self.assertEqual(self.registry.pointer('active'), old)
            args.candidate = receipt['candidate_id']; args.operation = 'promote'
            self.assertEqual(cli.execute(args)['status'], 'promoted')
            self.assertEqual(self.registry.pointer('active'), args.candidate)
        with self.registry.connect(readonly=True) as db:
            events = db.execute("SELECT candidate_id,detail FROM audit WHERE action='comparator_fallback'").fetchall()
            self.assertEqual(len(events), 2)
            self.assertTrue(all(r['candidate_id'] == old for r in events))
            self.assertTrue(all(json.loads(r['detail']) == {'profile': BASE_PROFILE} for r in events))

    def test_activation_previous_pointer_and_atomic_hash_audit(self):
        first = self.register(False)
        manifest, _ = self.registry.load(first)
        self.registry.activate(first, 'admin:1', manifest['gate'], None, 'synthetic reviewed gate')
        second = self.register(False)
        other, _ = self.registry.load(second)
        self.registry.activate(second, 'admin:1', other['gate'], first, 'synthetic second promotion')
        self.registry.activate(second, 'admin:1', other['gate'], second, 'synthetic repeated promotion')
        self.assertEqual(self.registry.pointer('previous'), first)
        self.registry.rollback(first, 'admin:1', IDENTITY, 'synthetic rollback')
        with self.registry.connect(readonly=True) as db:
            receipt = json.loads(db.execute("SELECT detail FROM audit WHERE action='rollback' ORDER BY id DESC LIMIT 1").fetchone()[0])
        self.assertEqual(receipt['reason'], 'synthetic rollback')
        self.assertEqual(receipt['before_sha256'], other['model_sha256'])
        self.assertEqual(receipt['after_sha256'], manifest['model_sha256'])

    def test_bandit_promotion_invalidates_model_status_and_effective_serving(self):
        from types import SimpleNamespace
        from feedback_bandit import FeedbackBandit
        from feedback_bandit_registry import BanditRegistry
        from learning_activation_cli import status
        from services import service_predict as sp
        bands = BanditRegistry(self.root)
        lease = json.loads((self.root / 'authority.json').read_bytes())
        lease.update(bandit_subjects=[], bandit_revisions={})
        (self.root / 'authority.json').write_bytes(encoded(lease))
        def make_bandit(algorithm):
            return FeedbackBandit('synthetic', [KEY], ['sector_C'], 'a' * 64, algorithm=algorithm, shadow=False)
        first = bands.sync(make_bandit('linucb'), [])['candidate']['id']
        bands.select('promote', first, 'admin:1', 'synthetic first')
        profile = SimpleNamespace(name=BASE_PROFILE)
        def policies(_):
            return SimpleNamespace(label_thresholds=None, crc_recall_floor=None, sector_guard=None,
                feedback_bandit=bands.active(), sha256={'feedback_bandit': bands.active().envelope()['sha256']}, score_threshold=.95)
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_SERVING_ENABLED': 'true',
                                     'LEARNING_ENGINE_ROOT': str(self.root), 'LEARNING_BANDIT_MODE': 'active'}), \
                patch.object(sp, 'resolve_model_profile', return_value=profile), \
                patch.object(sp, '_model_artifact_hashes', return_value={'esrs_classifier.pkl': 'a' * 64}), \
                patch.object(sp, 'load_serving_policies', side_effect=policies):
            identity = sp.model_serving_identity(profile)['serving_identity_sha256']
            with patch(__name__ + '.IDENTITY', identity):
                candidate = self.register(False)
            self.activate(candidate)
            before = status(self.root)['wp13']
            self.assertEqual(before['effective'], candidate)
            self.assertTrue(before['candidates'][0]['eligible'])
            second = bands.sync(make_bandit('epsilon_greedy'), [])['candidate']['id']
            bands.select('promote', second, 'admin:1', 'synthetic changed baseline')
            after = status(self.root)['wp13']
            self.assertEqual(after['selected'], candidate)
            self.assertIsNone(after['effective'])
            self.assertFalse(after['candidates'][0]['eligible'])
            self.assertEqual(after['fallback_reason'], 'selected_incompatible_or_unauthorized')
            self.assertEqual(after['candidates'][0]['comparator']['baseline_identity'], identity)
            current = after['serving']['baseline']['serving_identity_sha256']
            self.assertNotEqual(identity, current)
            result = baseline(company())
            result.mapping_metadata['serving_identity']['serving_identity_sha256'] = current
            self.assertIs(apply_learning(CompanyData(**company()), result), result)
            bands.select('rollback', None, 'admin:1', 'synthetic restore baseline')
            self.assertEqual(status(self.root)['wp13']['effective'], candidate)

    def test_cli_base_reset_works_with_engine_and_authority_off(self):
        from types import SimpleNamespace
        from learning_production_cli import execute
        first = self.register(False); self.activate(first)
        second = self.register(False); self.activate(second)
        self.lease(subjects=[])
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'false', 'LEARNING_ENGINE_AUTHORITY': 'deny'}):
            result = execute(SimpleNamespace(root=str(self.root), operation='rollback', candidate='base',
                                             actor='admin:1', reason='synthetic OFF reset'))
        self.assertEqual(result['status'], 'rolled_back')
        self.assertIsNone(self.registry.pointer('active'))

    def test_composed_model_preserves_frozen_rf_bandit_basis(self):
        candidate = self.register(False); self.activate(candidate)
        result = baseline(company())
        result.mapping_metadata['feedback_bandit'] = {'basis': {'model_sha256': 'a' * 64}, 'shadow': False}
        with patch.dict(os.environ, {'LEARNING_ENGINE_ENABLED': 'true', 'LEARNING_ENGINE_ROOT': str(self.root),
                                    'LEARNING_ENGINE_SERVING_ENABLED': 'true', 'LEARNING_ENGINE_SHADOW_ENABLED': 'false'}):
            served = apply_learning(CompanyData(**company()), result)
        self.assertEqual(served.mapping_metadata['feedback_bandit'], result.mapping_metadata['feedback_bandit'])
        self.assertEqual(served.model_profile, 'learning:' + candidate)


if __name__ == '__main__': unittest.main()


class GateMetricsProductionTests(unittest.TestCase):
    """Synthetic measurement only; a favourable fixture does not establish real quality."""
    def setup_gate(self):
        from learning_gate_metrics import policy
        cases = [dict(group='synthetic-'+str(i), p5_sha256=digest(['synthetic-heldout',i]), input=company(100+i),
                      truth={KEY: 1, OTHER: 0}) for i in range(30)]
        sets = {'heldout': ({'schema':'learning-heldout-v1','cases':cases}, 'c'*64)}
        p = policy(dict(replicates=100))
        return sets, p

    def test_cli_stochastic_bandit_replays_frozen_decisions_through_promotion(self):
        """Synthetic consent simulation; real predictor, bandit, fitting and registry."""
        import contextlib
        import io
        import pandas as pd
        from types import SimpleNamespace
        from feedback_bandit import FeedbackBandit, encode_context
        from services import service_predict as sp
        from services.datatypes import FeatureMetadata
        from learning_production_cli import execute
        from learning_gate_metrics import validate_contract
        fixture = LearningProductionTests(); fixture.setUp()
        try:
            sets, _ = self.setup_gate()
            data = dataset()
            for row in data['rows']:
                row['synthetic'] = False  # Test-only simulation of the consent lane.
                row['topics']['2'] = {'mask': 1, 'value': 1-row['topics']['1']['value']}
            def write(name, document):
                path = fixture.root/name; path.write_bytes(encoded(document))
                return str(path), hashlib.sha256(path.read_bytes()).hexdigest()
            args = SimpleNamespace(operation='train',root=str(fixture.root),actor='system',
                code_commit='a'*40,gold=None,gold_sha256=None)
            for name, document in [('dataset',data),('mapping',mapping()),('heldout',sets['heldout'][0])]:
                path, sha = write('synthetic-'+name+'.json',document)
                setattr(args,name,path);setattr(args,name+'_sha256',sha)
            profile = sp.ModelProfile(name=BASE_PROFILE,artifact_dir=Path('unused'),
                expected_key_count=2,required_artifacts=(),runtime_enabled=True,description='synthetic fixture')
            bandit = FeedbackBandit(BASE_PROFILE,[OTHER],['sector_C'],'a'*64,
                algorithm='epsilon_greedy',epsilon=1,min_events=1,shadow=False)
            bandit.update(sequence=1,topic=OTHER,context=encode_context({'sector_C':1}),
                base_score=.5,proposed=True,accepted=True)
            frozen_state = bandit.envelope()
            def policies(_):
                return sp.ServingPolicies(.5,None,None,None,
                    {'feedback_bandit':bandit.envelope()['sha256']},bandit)
            classifier = SimpleNamespace(predict=lambda df:[[1,1]])
            def random_decision():
                raise AssertionError('gate must not draw random decision IDs')
            with patch.dict(os.environ,LEARNING_GATE_METRICS_ENABLED='true',LEARNING_GATE_REPLICATES='100',
                            LEARNING_ENGINE_ENABLED='true',LEARNING_ENGINE_AUTHORITY='consent',
                            LEARNING_ENGINE_SYNTHETIC_ONLY='false',LEARNING_ENGINE_SERVING_ENABLED='false'), \
                 patch.object(sp,'resolve_model_profile',return_value=profile), \
                 patch.object(sp,'load_profile_classifier',return_value=classifier), \
                 patch.object(sp,'load_serving_policies',side_effect=policies), \
                 patch.object(sp,'load_new_format_data',return_value=(pd.DataFrame([{'sector_C':1}]),FeatureMetadata())), \
                 patch.object(sp,'load_profile_esrs_columns',return_value=[KEY,OTHER]), \
                 patch.object(sp,'new_format_positive_scores',return_value=[1.,.5]), \
                 patch.object(sp,'_model_artifact_hashes',return_value={'synthetic':'a'*64}), \
                 patch('feedback_bandit.uuid',SimpleNamespace(uuid4=random_decision)), \
                 contextlib.redirect_stdout(io.StringIO()):
                receipt = execute(args)
                self.assertTrue(receipt['gate']['passed'])
                fp = receipt['gate']['report']['heldout']['micro']['predictors']['comparator']['fp']
                self.assertGreater(fp,0);self.assertLess(fp,30)
                manifest, _ = fixture.registry.load(receipt['candidate_id'])
                validate_contract(manifest['gate_protocol'],'protocol')
                self.assertEqual(len(manifest['gate_protocol']['decision_ids']['heldout']),30)
                self.assertIsNone(fixture.registry.pointer('active'))
                args.operation='promote';args.candidate=receipt['candidate_id'];args.actor='admin:1'
                promoted = execute(args)
                self.assertEqual(promoted['status'],'promoted')
                self.assertEqual(promoted['gate'],receipt['gate'])
                self.assertEqual(fixture.registry.pointer('active'),args.candidate)
                self.assertEqual(bandit.envelope(),frozen_state)
        finally:
            fixture.doCleanups()

    def test_decision_context_resets_and_protocol_decision_drift_fails_closed(self):
        from feedback_bandit import FeedbackBandit, encode_context, gate_decision
        from learning_production_registry import gate_decision_ids
        bandit = FeedbackBandit(BASE_PROFILE,[OTHER],['sector_C'],'a'*64,shadow=False)
        def apply(**kwargs):
            return bandit.apply([], [OTHER], [.5], encode_context({'sector_C':1}),
                threshold=.5,candidate_predicate=lambda k:True,**kwargs)[1]['decision_id']
        with patch('feedback_bandit.uuid.uuid4',return_value=type('ID',(),{'hex':'synthetic-random'})()):
            with self.assertRaisesRegex(RuntimeError,'synthetic exception'):
                with gate_decision('c'*64):
                    self.assertEqual(apply(),'c'*64)
                    with self.assertRaises(ValueError): apply(decision_id='d'*64)
                    raise RuntimeError('synthetic exception')
            self.assertEqual(apply(),'synthetic-random')
        sets, p = self.setup_gate()
        ids = gate_decision_ids(sets);ids['heldout'][0]='f'*64
        with patch(__name__+'.baseline') as predict:
            with self.assertRaises(ValueError):
                evaluate(Constant(1),sets,predict,metrics_policy=p,decision_ids=ids)
            predict.assert_not_called()

    def test_tie_false_positives_and_favourable_measurement(self):
        sets,p = self.setup_gate()
        base = lambda c: baseline(c,1)
        class Better:
            def predict(self,c,b): return {KEY:1,OTHER:0}
        gate = evaluate(Better(),sets,base,metrics_policy=p,protocol_sha256='d'*64)
        self.assertTrue(gate['passed'])
        self.assertEqual(gate['report']['heldout']['micro']['predictors']['candidate']['precision']['value'],1)
        tie = evaluate(Constant(1),sets,base,metrics_policy=p)
        self.assertFalse(tie['passed'])
        self.assertEqual(tie['primary']['reason'],'improvement_not_demonstrated')
        clean = lambda c: Prediction(esrs={KEY:1,OTHER:0}, mapping_metadata={'serving_identity':{'serving_identity_sha256':IDENTITY}})
        self.assertFalse(evaluate(Constant(1),sets,clean,comparator=Better(),metrics_policy=p)['passed'])

    def test_one_positive_loss_overrides_precision_improvement(self):
        sets,p=self.setup_gate()
        class Loss:
            def predict(self,c,b): return {KEY:int(c.employees_total != 100),OTHER:0}
        gate=evaluate(Loss(),sets,lambda c:baseline(c,1),metrics_policy=p)
        self.assertTrue(gate['primary']['passed'])
        self.assertFalse(gate['passed'])
        self.assertEqual(gate['sets'][0]['lost_true_positives'],1)

    def test_standard_gold_keeps_explicit_truth_and_unknowns_separate(self):
        sets,p=self.setup_gate()
        sets['gold_oos']=({'schema':'learning-heldout-v1','unit':'standards','cases':[
            dict(group='synthetic-gold',p5_sha256='e'*64,input=company(999),truth={'E1':1,'E2':None})]},'f'*64)
        gate=evaluate(Constant(1),sets,lambda c:baseline(c,1),metrics_policy=p)
        gold=gate['report']['gold_oos']['micro']
        self.assertEqual(gold['unknown_support'],1)
        self.assertEqual(gold['negative_support'],0)

    def test_invalid_comparator_and_identity_drift_fail_closed(self):
        sets,p=self.setup_gate()
        class Invalid:
            def predict(self,c,b): return {KEY:True,OTHER:1}
        with self.assertRaises(ValueError): evaluate(Constant(1),sets,baseline,comparator=Invalid(),metrics_policy=p)
        with self.assertRaises(ValueError): evaluate(Constant(1),sets,baseline,metrics_policy=p,expected_identity={'serving_identity_sha256':'b'*64})

    def test_protocol_is_immutable_and_binds_policy_sets_and_comparator(self):
        from learning_production_cli import protocol_document
        sets,p=self.setup_gate()
        identity={'serving_identity_sha256':IDENTITY}
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ,LEARNING_GATE_METRICS_ENABLED='true'):
            registry=ProductionCandidateRegistry(Path(tmp)/'registry',create=True)
            protocol=protocol_document(sets,identity,None,None,created_at=1,dataset_sha256='d'*64)
            sha=registry.freeze_protocol(protocol)
            self.assertEqual(sha,registry.freeze_protocol(protocol))
            self.assertEqual(protocol,registry.protocol(sha))
            for change in [dict(selected='a'*32),dict(fixed_sha256={'heldout':'b'*64}),dict(policy={**protocol['policy'],'precision_delta':.02})]:
                self.assertNotEqual(sha,digest({**protocol,**change}))
            with registry.connect() as db:
                db.execute('UPDATE gate_protocols SET document=? WHERE protocol_sha256=?',(encoded({**protocol,'selected':'a'*32}).decode(),sha))
            with self.assertRaises(ValueError): registry.protocol(sha)

    def test_metrics_on_rejects_legacy_promotion(self):
        fixture=LearningProductionTests()
        fixture.setUp()
        try:
            candidate=fixture.register(synthetic=False)
            with patch.dict(os.environ,LEARNING_GATE_METRICS_ENABLED='true'):
                with self.assertRaises(ValueError): fixture.activate(candidate)
        finally: fixture.doCleanups()

    def test_receipt_and_safe_projection_meet_contract_and_hide_private_fields(self):
        from learning_gate_metrics import administrative_gate, validate_contract
        sets,p=self.setup_gate()
        gate=evaluate(Constant(1),sets,lambda c:baseline(c,1),metrics_policy=p,protocol_sha256='d'*64)
        validate_contract(gate,'receipt')
        gate['groups']=['PRIVATE']; gate['inputs']=['PRIVATE']; gate['truth']=['PRIVATE']
        summary=administrative_gate(gate)
        detail=administrative_gate(gate,detail=True)
        validate_contract(summary,'administrative_projection')
        self.assertNotIn('per_topic',summary['report']['heldout'])
        self.assertIn(KEY,detail['report']['heldout']['per_topic'])
        self.assertNotIn('PRIVATE',json.dumps(detail))

    def test_changed_policy_comparator_sets_and_synthetic_block_activation(self):
        from learning_gate_metrics import policy
        from learning_production_cli import protocol_document
        fixture=LearningProductionTests(); fixture.setUp()
        try:
            sets,p=self.setup_gate()
            with patch.dict(os.environ,LEARNING_GATE_METRICS_ENABLED='true'):
                registry=ProductionCandidateRegistry(fixture.root,create=True)
                protocol=protocol_document(sets,{'serving_identity_sha256':IDENTITY},None,None,
                    created_at=time.time(),dataset_sha256=digest(fixture.data))
                sha=registry.freeze_protocol(protocol)
                class Better:
                    def predict(self,c,b): return {KEY:1,OTHER:0}
                gate=evaluate(Better(),sets,lambda c:baseline(c,1),metrics_policy=policy(),protocol_sha256=sha)
                source,_=registry.load(fixture.register(synthetic=False))
                source.update(gate=gate,gate_protocol=protocol)
                candidate=registry.register(fixture.model,source)
                # Replayed gate cannot reinterpret its declared endpoint or selection.
                with patch.dict(os.environ,LEARNING_GATE_PRECISION_DELTA='.02'):
                    with self.assertRaises(ValueError): registry.activate(candidate,'admin:1',gate,None)
                with self.assertRaises(ValueError): registry.activate(candidate,'admin:1',gate,'a'*32)
                changed=copy.deepcopy(gate);changed['fixed_sha256']['heldout']='f'*64
                with self.assertRaises(ValueError): registry.activate(candidate,'admin:1',changed,None)
                changed=copy.deepcopy(gate);changed['protocol_sha256']='e'*64
                with self.assertRaises(ValueError): registry.activate(candidate,'admin:1',changed,None)
                source['synthetic']=True
                synthetic=registry.register(fixture.model,source)
                with self.assertRaises(ValueError): registry.activate(synthetic,'admin:1',gate,None)
        finally: fixture.doCleanups()

    def test_cli_freezes_before_fit_and_rejects_policy_drift_before_reevaluation(self):
        from learning_production_cli import execute
        from learning_gate_metrics import validate_contract
        from types import SimpleNamespace
        fixture=LearningProductionTests(); fixture.setUp()
        try:
            sets,p=self.setup_gate()
            identity={'profile':BASE_PROFILE,'artifact_sha256':{'synthetic':'a'*64},'policy_sha256':{},
                      'runtime_config':{'score_threshold':.95,'policy_active':{}},'serving_identity_sha256':IDENTITY}
            def predict(c):
                result=baseline(c,1);result.mapping_metadata={'serving_identity':identity};return result
            def write(name,doc):
                path=fixture.root/name;path.write_bytes(encoded(doc));return str(path),hashlib.sha256(path.read_bytes()).hexdigest()
            dataset_path,dataset_sha=write('synthetic-data.json',fixture.data)
            heldout_path,heldout_sha=write('synthetic-heldout.json',sets['heldout'][0])
            mapping_path,mapping_sha=write('synthetic-mapping.json',mapping())
            args=SimpleNamespace(operation='train',root=str(fixture.root),actor='system',code_commit='a'*40,
                dataset=dataset_path,dataset_sha256=dataset_sha,mapping=mapping_path,mapping_sha256=mapping_sha,
                heldout=heldout_path,heldout_sha256=heldout_sha,gold=None,gold_sha256=None)
            def fit(*a,**kw):
                with fixture.registry.connect(readonly=True) as db:
                    self.assertEqual(db.execute('SELECT COUNT(*) FROM gate_protocols').fetchone()[0],1)
                    saved = json.loads(db.execute('SELECT document FROM gate_protocols').fetchone()[0])
                    self.assertEqual(len(saved['decision_ids']['heldout']),len(sets['heldout'][0]['cases']))
                return train_production_feedback(*a,**kw)
            with patch.dict(os.environ,LEARNING_GATE_METRICS_ENABLED='true',LEARNING_GATE_REPLICATES='100',
                            LEARNING_ENGINE_ENABLED='true',LEARNING_ENGINE_AUTHORITY='consent',LEARNING_ENGINE_SERVING_ENABLED='false'), \
                 patch('learning_production_cli.baseline',side_effect=predict), \
                 patch('learning_serving_adapter.model_serving_identity',return_value=identity), \
                 patch('learning_serving_adapter.resolve_model_profile',return_value=BASE_PROFILE), \
                 patch('learning_production_cli.train_production_feedback',side_effect=fit):
                result=execute(args)
                validate_contract(result['gate'],'receipt')
                manifest,_=fixture.registry.load(result['candidate_id'])
                validate_contract(manifest['gate_protocol'],'protocol')
                self.assertIsNone(fixture.registry.pointer('active'))
                args.operation='promote';args.candidate=result['candidate_id'];args.actor='admin:1'
                with patch.dict(os.environ,LEARNING_GATE_PRECISION_DELTA='.02'), patch('learning_production_cli.evaluate') as rerun:
                    with self.assertRaises(ValueError): execute(args)
                    rerun.assert_not_called()
        finally: fixture.doCleanups()

    def test_status_detail_is_one_candidate_aggregate_only_and_erasure_hides_it(self):
        from learning_activation_cli import status
        fixture=LearningProductionTests();fixture.setUp()
        try:
            sets,p=self.setup_gate()
            source,_=fixture.registry.load(fixture.register())
            source['gate']=evaluate(Constant(1),sets,lambda c:baseline(c,1),metrics_policy=p,protocol_sha256='d'*64)
            source['gate']['subjects']=['PRIVATE'];source['gate']['input']={'secret':'PRIVATE'}
            candidate=fixture.registry.register(fixture.model,source)
            with patch.dict(os.environ,LEARNING_GATE_METRICS_ENABLED='true',LEARNING_ENGINE_SERVING_ENABLED='false'), \
                 patch('learning_serving_adapter.resolve_model_profile',return_value=BASE_PROFILE), \
                 patch('learning_serving_adapter.model_serving_identity',return_value={'serving_identity_sha256':IDENTITY}):
                summary=status(str(fixture.root))
                c=next(c for c in summary['wp13']['candidates'] if c['id']==candidate)
                self.assertNotIn('per_topic',c['gate']['report']['heldout'])
                # A detail lookup must survive falling outside the recent window.
                for _ in range(30):
                    fixture.registry.register(fixture.model, source)
                recent = status(str(fixture.root))['wp13']['candidates']
                self.assertEqual(len(recent), 30)
                self.assertNotIn(candidate, [entry['id'] for entry in recent])
                detail=status(str(fixture.root),candidate)
                self.assertEqual(len(detail['wp13']['candidates']),1)
                self.assertIn(KEY,detail['wp13']['candidates'][0]['gate']['report']['heldout']['per_topic'])
                self.assertNotIn('PRIVATE',json.dumps(detail))
                self.assertNotIn('group',json.dumps(detail))
                self.assertFalse(status(str(fixture.root),'f'*32)['candidate_found'])
                fixture.registry.erase(fixture.subjects[0])
                self.assertFalse(status(str(fixture.root),candidate)['candidate_found'])
                with self.assertRaises(ValueError): status(str(fixture.root),'../path')
        finally: fixture.doCleanups()
