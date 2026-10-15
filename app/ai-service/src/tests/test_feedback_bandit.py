"""Synthetic unit fixtures, never evaluation evidence."""
import copy
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import numpy as np

from feedback_bandit import (ALGORITHMS, DIM, FeedbackBandit, PATH_ENV, digest,
                             encode_context, load_feedback_bandit, vector)

TOPICS = ["esrs_e1_energy", "esrs_e1_climate", "esrs_s1_workers"]
CONTEXT = encode_context({"sector_C": 1, "company_size": "SMALL"})


def learner(algorithm="linucb", **kwargs):
    return FeedbackBandit("fixture", TOPICS, ["sector_C", "company_size"], "a" * 64,
                          algorithm=algorithm, min_events=1, **kwargs)


def update(model, topic=TOPICS[0], proposed=True, accepted=True):
    model.update(sequence=model.sequence + 1, topic=topic, context=CONTEXT,
                 base_score=.95, proposed=proposed, accepted=accepted)


def synthetic_python_response(shadow):
    """Real prediction/identity serializer with synthetic model inputs only."""
    import pandas as pd
    from types import SimpleNamespace
    from services import service_predict as sp
    from services.datatypes import CompanyData, FeatureMetadata
    profile = sp.ModelProfile(name="new_format_732_v1_fixture", artifact_dir=Path("unused"),
                              expected_key_count=3, required_artifacts=(), runtime_enabled=True, description="synthetic fixture")
    company = CompanyData(company_name="Synthetic contract fixture", sector_list=["C"], headquarters_country="Spain",
                          num_subsidiaries_countries=1, employees_total=150,
                          annual_turnover_million_euro=1, stock_listed=False, reporting_currency="EUR")
    bandit = learner(shadow=shadow)
    bandit.config["profile"] = profile.name
    policies = sp.ServingPolicies(.95, None, None, None, {"feedback_bandit": bandit.envelope()["sha256"]}, bandit)
    class Classifier:
        def predict(self, df):
            return [[0, 0, 0]]
    with patch.object(sp, "resolve_model_profile", return_value=profile), \
         patch.object(sp, "load_profile_classifier", return_value=Classifier()), \
         patch.object(sp, "load_serving_policies", return_value=policies), \
         patch.object(sp, "load_new_format_data", return_value=(pd.DataFrame([{"sector_C": 1, "company_size": "SMALL"}]), FeatureMetadata())), \
         patch.object(sp, "load_profile_esrs_columns", return_value=TOPICS), \
         patch.object(sp, "new_format_positive_scores", return_value=[.91, .01, .01]), \
         patch.object(sp, "_model_artifact_hashes", return_value={"esrs_classifier.pkl": "a"*64}), \
         patch("feedback_bandit.uuid.uuid4", return_value=SimpleNamespace(hex="synthetic-contract-decision")), \
         patch.object(bandit, "choose", return_value=1):
        return sp.predict_esrs(company).model_dump()


class FeedbackBanditTest(unittest.TestCase):
    def test_exploration_survives_request_reloads_and_matches_retained_instance(self):
        for algorithm in ("epsilon_greedy", "thompson", "thompson_bucket"):
            with self.subTest(algorithm=algorithm):
                model = learner(algorithm, seed=7)
                update(model)
                saved = model.envelope()
                retained, reloaded = [], []
                for i in range(100):
                    decision_id = f"synthetic-request-{i}"
                    retained.append(model.choose(TOPICS[0], CONTEXT, .95, 1, decision_id=decision_id))
                    copy = FeedbackBandit.from_envelope(saved)
                    reloaded.append(copy.choose(TOPICS[0], CONTEXT, .95, 1, decision_id=decision_id))
                self.assertEqual(retained, reloaded)
                self.assertEqual(set(reloaded), {0, 1})
                self.assertEqual(model.envelope(), saved)

    def test_serving_allocates_new_decision_ids_and_can_reproduce_each_request(self):
        model = learner("thompson", shadow=False)
        update(model)
        saved = model.envelope()
        ids, results = set(), set()
        args = ([TOPICS[0]], TOPICS, [.95, .01, .01], CONTEXT)
        for _ in range(100):
            loaded = FeedbackBandit.from_envelope(saved)
            result, metadata = loaded.apply(*args, threshold=.95, candidate_predicate=lambda k: True)
            ids.add(metadata["decision_id"])
            results.add(tuple(result))
            resumed = FeedbackBandit.from_envelope(saved)
            again = resumed.apply(*args, threshold=.95, candidate_predicate=lambda k: True,
                                  decision_id=metadata["decision_id"])
            self.assertEqual((result, metadata), again)
        self.assertEqual(len(ids), 100)
        self.assertEqual(results, {(), (TOPICS[0],)})

    def test_default_off_and_shadow_default(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertIsNone(load_feedback_bandit("fixture"))
        self.assertTrue(learner().config["shadow"])

    def test_each_algorithm_updates_and_resume_preserves_next_updates_and_decisions(self):
        for algorithm in ALGORITHMS:
            with self.subTest(algorithm=algorithm):
                original = learner(algorithm)
                for accepted in (True, False, True):
                    update(original, accepted=accepted)
                clone = FeedbackBandit.from_envelope(json.loads(json.dumps(original.envelope())))
                self.assertEqual(original.choose(TOPICS[0], CONTEXT, .95, 1, decision_id="fixture-decision"), clone.choose(TOPICS[0], CONTEXT, .95, 1, decision_id="fixture-decision"))
                update(original, proposed=False)
                update(clone, proposed=False)
                self.assertEqual(original.envelope(), clone.envelope())

    def test_linucb_rank_one_inverse_matches_batch_reference(self):
        model = learner()
        for _ in range(7):
            update(model)
        x = vector(CONTEXT, .95)
        np.testing.assert_allclose(model.states[TOPICS[0]]["inverse"][1],
                                   np.linalg.inv(np.eye(DIM) + 7 * np.outer(x, x)))
        np.testing.assert_allclose(model.states[TOPICS[0]]["b"][1], 7 * x)

    def test_thompson_reward_success_failure_and_context_isolation(self):
        model = learner("thompson")
        update(model)
        update(model, accepted=False)
        update(model, proposed=False)
        np.testing.assert_equal(model.states[TOPICS[0]]["rewards"][0], [[0, 1], [1, 1]])
        self.assertEqual(model._state(TOPICS[1])["n"], 0)
        contextual = learner("thompson_bucket")
        update(contextual)
        self.assertEqual(np.count_nonzero(contextual.states[TOPICS[0]]["rewards"].sum(axis=(1, 2))), 1)

    def test_sgd_learns_positive_and_negative_decisions(self):
        model = learner("sgd")
        for _ in range(30):
            update(model)
            update(model, topic=TOPICS[1], accepted=False)
        self.assertEqual(model.choose(TOPICS[0], CONTEXT, .95, 0, decision_id="fixture-decision"), 1)
        self.assertEqual(model.choose(TOPICS[1], CONTEXT, .95, 1, decision_id="fixture-decision"), 0)

    def test_duplicate_out_of_order_unobserved_and_invalid_events_do_not_mutate(self):
        model = learner()
        update(model)
        before = model.envelope()
        base = dict(sequence=2, topic=TOPICS[0], context=CONTEXT, base_score=.9, proposed=True, accepted=True)
        for replacement in ({"sequence": 1}, {"sequence": 3}, {"topic": "other"},
                            {"context": [0]}, {"base_score": float("nan")},
                            {"proposed": False, "accepted": False}, {"accepted": 1}):
            with self.assertRaises(ValueError):
                model.update(**(base | replacement))
            self.assertEqual(before, model.envelope())

    def test_bounded_margin_cap_and_crc_last_representative(self):
        model = learner(shadow=False, max_changes=1)
        with patch.object(model, "choose", return_value=0):
            result, metadata = model.apply(TOPICS, TOPICS, [.96, .97, .4], CONTEXT,
                                           threshold=.95, candidate_predicate=lambda k: True,
                                           protected_standards={"esrs_e1", "esrs_s1"})
        self.assertEqual(len(metadata["changes"]), 1)
        self.assertEqual(len(result), 2)
        with patch.object(model, "choose", return_value=0):
            result2, _ = model.apply(result, TOPICS, [.96, .97, .4], CONTEXT,
                                    threshold=.95, candidate_predicate=lambda k: True,
                                    protected_standards={"esrs_e1", "esrs_s1"})
        self.assertEqual(result, result2)

    def test_addition_margin_per_label_and_non_candidate_exclusion(self):
        model = learner(shadow=False)
        with patch.object(model, "choose", return_value=1):
            result, _ = model.apply([], TOPICS, [.9, .2, .7], CONTEXT, threshold=.95,
                                   label_thresholds={TOPICS[2]: .75},
                                   candidate_predicate=lambda k: k != TOPICS[0])
        self.assertEqual(result, [TOPICS[2]])

    def test_shadow_logs_counts_only_and_returns_original(self):
        model = learner()
        with patch.object(model, "choose", return_value=1), self.assertLogs("feedback_bandit", level="INFO") as logs:
            result, metadata = model.apply([], TOPICS, [.9, .1, .1], CONTEXT,
                                           threshold=.95, candidate_predicate=lambda k: True)
        self.assertEqual(result, [])
        self.assertEqual(len(metadata["changes"]), 1)
        self.assertNotIn("sector", str(logs.output))
        self.assertNotIn(TOPICS[0], str(logs.output))

    def test_checksum_profile_model_feature_and_topic_bindings_fail_closed(self):
        model = learner()
        update(model)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            model.save(path)
            with patch.dict(os.environ, {PATH_ENV: str(path)}):
                self.assertIsNotNone(load_feedback_bandit("fixture"))
                for kwargs in ({"feature_columns": ["wrong"]}, {"model_sha256": "b"*64}, {"topics": TOPICS[::-1]}):
                    self.assertIsNone(load_feedback_bandit("fixture", **kwargs))
                self.assertIsNone(load_feedback_bandit("other"))
                state = model.envelope()
                state["payload"]["sequence"] += 1
                path.write_text(json.dumps(state))
                self.assertIsNone(load_feedback_bandit("fixture"))

    def test_rechecks_structural_validation_even_with_valid_checksum(self):
        model = learner()
        update(model)
        for field, value in (("version", "v2"), ("sequence", True), ("encoder", "wrong")):
            envelope = copy.deepcopy(model.envelope())
            envelope["payload"][field] = value
            envelope["sha256"] = digest(envelope["payload"])
            with self.assertRaises(ValueError):
                FeedbackBandit.from_envelope(envelope)

    def test_bound_event_contract_checks_identity_before_update(self):
        model = learner()
        event = dict(version="p8-bandit-event-v1", profile="fixture", model_sha256="a"*64,
                     feature_schema_sha256=digest(model.config["feature_columns"]), encoder="p5-hash-tanh-v1",
                     sequence=1, topic=TOPICS[0], context=CONTEXT, base_score=.95,
                     proposed=True, accepted=False)
        for replacement in ({"profile": "other"}, {"model_sha256": "b"*64},
                            {"feature_schema_sha256": "c"*64}, {"unexpected": True}):
            with self.assertRaises(ValueError):
                model.update_event(event | replacement)
            self.assertEqual(model.sequence, 0)
        model.update_event(event)
        self.assertEqual(model.sequence, 1)

    def test_epsilon_exploration_and_unseen_context_fallback(self):
        model = learner("epsilon_greedy", epsilon=1.)
        update(model)
        choices = {model.choose(TOPICS[0], CONTEXT, .95, 1, decision_id=str(i)) for i in range(100)}
        self.assertEqual(choices, {0, 1})
        self.assertEqual(model.choose(TOPICS[2], CONTEXT, .95, 1, decision_id="fixture-decision"), 1)

    def test_nonfinite_or_inconsistent_statistics_rejected(self):
        model = learner("thompson")
        update(model)
        envelope = model.envelope()
        envelope["payload"]["states"][TOPICS[0]]["rewards"][0][0][0] = 7
        envelope["sha256"] = digest(envelope["payload"])
        with self.assertRaises(ValueError):
            FeedbackBandit.from_envelope(envelope)

    def test_encoder_stable_finite_and_fixed_dimension(self):
        self.assertEqual(CONTEXT, encode_context({"company_size": "SMALL", "sector_C": 1}))
        self.assertEqual(len(CONTEXT), DIM-2)
        with self.assertRaises(ValueError):
            encode_context({"bad": float("inf")})


class ServingIntegrationTest(unittest.TestCase):
    def test_laravel_full_response_fixture_matches_python_prediction_serializer(self):
        path = Path(__file__).resolve().parents[3] / "web/tests/Fixtures/feedback-bandit-python-response.json"
        fixture = json.loads(path.read_text())
        for mode in ("shadow", "active"):
            self.assertEqual(fixture[mode], json.loads(json.dumps(synthetic_python_response(mode == "shadow"))))

    def test_serving_policy_loader_binds_snapshot_and_keeps_off_identity_unchanged(self):
        from services import service_predict as sp
        bandit = learner()
        profile = sp.ModelProfile(name="new_format_732_v1_fixture", artifact_dir=Path("unused"),
                                  expected_key_count=3, required_artifacts=(), runtime_enabled=True, description="fixture")
        bandit.config["profile"] = profile.name
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            bandit.save(path)
            with patch.dict(os.environ, {PATH_ENV: str(path)}, clear=True), \
                 patch.object(sp, "load_profile_feature_columns", return_value=bandit.config["feature_columns"]), \
                 patch.object(sp, "load_profile_esrs_columns", return_value=TOPICS), \
                 patch.object(sp, "_model_artifact_hashes", return_value={"esrs_classifier.pkl": "a"*64}):
                policies = sp.load_serving_policies(profile)
                self.assertIsNotNone(policies.feedback_bandit)
                self.assertEqual(policies.sha256["feedback_bandit"], bandit.envelope()["sha256"])
                self.assertTrue(sp.model_serving_identity(profile, policies)["runtime_config"]["policy_active"]["feedback_bandit"])
                os.environ.pop(PATH_ENV)
                policies = sp.load_serving_policies(profile)
                self.assertIsNone(policies.feedback_bandit)
                self.assertNotIn("feedback_bandit", sp.model_serving_identity(profile, policies)["runtime_config"]["policy_active"])

    def test_predict_applies_bandit_after_threshold_filter(self):
        # Public runtime: no CRC recall floor, so no protected standards.
        import pandas as pd
        from services import service_predict as sp
        from services.datatypes import CompanyData, FeatureMetadata
        profile = sp.ModelProfile(name="new_format_732_v1_fixture", artifact_dir=Path("unused"),
                                  expected_key_count=3, required_artifacts=(), runtime_enabled=True, description="fixture")
        company = CompanyData(company_name="Synthetic", sector_list=["C"], headquarters_country="Spain",
                              num_subsidiaries_countries=1, employees_total=150,
                              annual_turnover_million_euro=1, stock_listed=False, reporting_currency="EUR")
        bandit = learner(shadow=False)
        policies = sp.ServingPolicies(.95, None, None, None, {}, bandit)
        class Classifier:
            def predict(self, df):
                return [[1, 0, 0]]
        with patch.object(sp, "resolve_model_profile", return_value=profile), \
             patch.object(sp, "load_profile_classifier", return_value=Classifier()), \
             patch.object(sp, "load_serving_policies", return_value=policies), \
             patch.object(sp, "load_new_format_data", return_value=(pd.DataFrame([{"sector_C": 1, "company_size": "SMALL"}]), FeatureMetadata())), \
             patch.object(sp, "load_profile_esrs_columns", return_value=TOPICS), \
             patch.object(sp, "new_format_positive_scores", return_value=[.97, .91, .2]), \
             patch.object(sp, "model_serving_identity", return_value={}), \
             patch.object(bandit, "choose", return_value=1):
            prediction = sp.predict_esrs(company)
        self.assertEqual(prediction.esrs, {TOPICS[0]: 1, TOPICS[1]: 1, TOPICS[2]: 0})
        self.assertEqual(prediction.mapping_metadata["crc_added_keys"], [])
        basis = prediction.mapping_metadata["feedback_bandit"]["basis"]
        self.assertEqual(basis["context"], CONTEXT)
        self.assertEqual(basis["base_scores"][TOPICS[2]], .2)
        self.assertEqual(prediction.mapping_metadata["feedback_bandit"]["changes"], [{"topic": TOPICS[1], "action": 1}])
        self.assertEqual(prediction.mapping_metadata["emitted_positive_key_count"], 2)
