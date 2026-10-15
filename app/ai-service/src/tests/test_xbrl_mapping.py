"""XBRL concept matcher: synthetic fixtures and reconciliation tests; no real taxonomy required."""
import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest

import numpy as np

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/xbrl_mapping/matcher.py"
SPEC = importlib.util.spec_from_file_location("xbrl_concept_matcher", SCRIPT)
LOCAL_REPORT = SCRIPT.parents[2] / "artifacts/xbrl-concept-mapping-eval.json"
matcher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(matcher)


def fixture():
    # Synthetic labels for testing only.
    return [{"id": f"T{i}_{j}", "name": name, "description": "test description",
             "dr": f"T{i}", "esrs": "TEST", "target": f"esrs:{concept}"}
            for i, (name, concept) in enumerate([
                ("gross carbon emissions", "GrossCarbonEmissions"),
                ("water consumption", "WaterConsumption"),
                ("energy production", "EnergyProduction"),
                ("worker safety incidents", "WorkerSafetyIncidents")]) for j in range(2)]


class XbrlMappingTests(unittest.TestCase):
    def test_camel_case_acronyms_and_query_fields(self):
        self.assertEqual(matcher.concept_text("esrs:GHGEmissionsScope1"), "ghg emissions scope1")
        self.assertEqual(matcher.datapoint_text({"name": "A", "description": "B", "dr": "C"}), "A B C")

    def test_no_heldout_text_or_association_enters_fit(self):
        rows = fixture()
        train = rows[2:]
        concepts = sorted({p["target"] for p in rows})
        model = matcher.Matcher(concepts, train)
        self.assertNotIn(model.index[rows[0]["target"]], model.dr.get("T0", set()))
        self.assertNotIn(model.index[rows[0]["target"]], model.standard["TEST"])
        unseen = {**rows[0], "name": "exclusiveheldouttoken"}
        model.features(unseen)
        self.assertNotIn("exclusiveheldouttoken", model.word.vocabulary_)

    def test_training_prior_excludes_positive_concept_group(self):
        rows = fixture()
        model = matcher.Matcher(sorted({p["target"] for p in rows}), rows)
        idx = model.index[rows[0]["target"]]
        self.assertEqual(model.features(rows[0])[idx, 2], 1)
        self.assertEqual(model.features(rows[0], excluded_target=rows[0]["target"])[idx, 2], 0)
        self.assertEqual(model.features(rows[0], excluded_target=rows[0]["target"])[idx, 3], 0)

    def test_grouped_evaluation_contains_every_row_once_and_no_concept_overlap(self):
        rows = fixture()
        concepts = sorted({p["target"] for p in rows})
        result = matcher.evaluate(rows, concepts, folds=2)
        by_id = {p["id"]: p for p in rows}
        groups = {}
        for entry in result["assignments"]:
            concept = by_id[entry["datapoint_id"]]["target"]
            groups.setdefault(concept, set()).add(entry["fold"])
        self.assertTrue(all(len(folds) == 1 for folds in groups.values()))
        self.assertEqual(sorted(by_id), [p["datapoint_id"] for p in result["assignments"]])
        for metrics in result["metrics"].values():
            self.assertEqual(metrics["count"], len(rows))
            self.assertLessEqual(metrics["top_1"], metrics["top_5"])
            self.assertTrue(0 < metrics["mrr"] <= 1)

    def test_reranker_learns_similarities_with_positive_fixed_priors(self):
        rows = fixture()
        model = matcher.Matcher(sorted({p["target"] for p in rows}), rows).fit(rows)
        self.assertEqual(model.model.coef_.shape[1], len(matcher.LEARNED_FEATURE_INDICES))
        features = model.features(rows[0])
        raw = model.model.predict_proba(features[:, matcher.LEARNED_FEATURE_INDICES])[:, 1]
        adjusted = model.scores(rows[0])["logistic_reranker"]
        self.assertTrue(np.all(adjusted >= raw - 1e-12))

    def test_dr_only_retrieves_concept_from_an_independent_training_mapping(self):
        rows = fixture()
        train = [rows[0], rows[2], rows[4], rows[6]]
        model = matcher.Matcher(sorted({p["target"] for p in rows}), train).fit(train)
        query = {**rows[1], "name": "unseen paraphrase", "description": ""}
        ranked = model.order(model.scores(query)["dr_prefix_baseline"])
        self.assertEqual(model.concepts[ranked[0]], query["target"])
        self.assertNotIn(query["id"], {p["id"] for p in train})

    def test_dr_reuse_protocol_groups_duplicate_text_and_reports_coverage(self):
        rows = [{**p, "description": f"measurement variant {p['id']}"} for p in fixture()]
        rows += [{**rows[0], "id": "duplicate"}]
        result = matcher.evaluate_dr_reuse(rows, sorted({p["target"] for p in rows}), folds=2)
        by_id = {p["datapoint_id"]: p for p in result["assignments"]}
        self.assertEqual(by_id[rows[0]["id"]]["fold"], by_id["duplicate"]["fold"])
        self.assertEqual(result["query_count"], len(rows))
        self.assertEqual(result["applicable_count"] + result["excluded_count"], len(rows))
        self.assertGreater(result["applicable_count"], 0)
        for name, metrics in result["metrics"].items():
            ranks = [p["ranks"][name] for p in result["assignments"] if p["applicable"]]
            self.assertEqual(metrics, matcher.rank_metrics(ranks))
        self.assertTrue(all(not p["ranks"] for p in result["assignments"] if not p["applicable"]))

    def test_rank_metrics_and_stable_ties(self):
        self.assertEqual(matcher.rank_metrics([1, 2, 6])["top_1"], 1 / 3)
        self.assertEqual(matcher.rank_metrics([1, 2, 6])["top_5"], 2 / 3)
        np.testing.assert_array_equal(matcher.Matcher.order(np.zeros(4)), [0, 1, 2, 3])

    def test_suggestions_remain_pending_with_finite_scores(self):
        rows = fixture()
        concepts = sorted({p["target"] for p in rows})
        model = matcher.Matcher(concepts, rows).fit(rows)
        suggestions = matcher.suggestions(model, [{"id": "unknown", "name": "water consumption", "dr": "T1", "esrs": "TEST"}])
        self.assertEqual(len(suggestions), len(concepts))
        self.assertEqual(len({p["id"] for p in suggestions}), len(suggestions))
        for p in suggestions:
            self.assertEqual(p["status"], "pending_review")
            self.assertTrue(0 <= p["confidence"] <= 1)
            self.assertIn(p["concept_id"], concepts)
        self.assertEqual(suggestions[0]["concept_id"], "esrs:WaterConsumption")

    def test_input_reconciliation_rejects_missing_and_duplicate_ids(self):
        with tempfile.TemporaryDirectory() as directory:
            ig3, concept_map = Path(directory) / "ig3.json", Path(directory) / "map.json"
            ig3.write_text(json.dumps({"datapoints": [{"id": "a"}]}))
            concept_map.write_text(json.dumps({"concepts": {}}))
            with self.assertRaises(ValueError):
                matcher.load_inputs(ig3, concept_map)
            ig3.write_text(json.dumps({"datapoints": [{"id": "a"}, {"id": "a"}]}))
            concept_map.write_text(json.dumps({"concepts": {"a": {"concept_id": None}}}))
            with self.assertRaises(ValueError):
                matcher.load_inputs(ig3, concept_map)

    def test_committed_artifacts_reconcile_to_current_inputs(self):
        root = SCRIPT.parents[3]
        known, unknown, concepts = matcher.load_inputs(root / "web/data/esrs_datapoints_ig3.json", root / "web/data/atomizer_xbrl_concepts_v1.json")
        payload = json.loads((root / "web/data/xbrl_concept_suggestions_v1.json").read_text())
        ids = {p["id"] for p in unknown}
        self.assertEqual({p["datapoint_id"] for p in payload["suggestions"]}, ids)
        self.assertEqual(len(payload["suggestions"]), len(ids) * 5)
        self.assertEqual(len({p["id"] for p in payload["suggestions"]}), len(payload["suggestions"]))
        for datapoint_id in ids:
            rows = [p for p in payload["suggestions"] if p["datapoint_id"] == datapoint_id]
            self.assertEqual([p["rank"] for p in rows], list(range(1, 6)))
            self.assertEqual([p["confidence"] for p in rows], sorted((p["confidence"] for p in rows), reverse=True))
        self.assertTrue(all(p["concept_id"] in concepts for p in payload["suggestions"]))
        self.assertTrue(all(p["status"] == "pending_review" for p in payload["suggestions"]))
        for key, relative in [("ig3_sha256", "web/data/esrs_datapoints_ig3.json"),
                              ("concept_map_sha256", "web/data/atomizer_xbrl_concepts_v1.json")]:
            actual = hashlib.sha256((root / relative).read_bytes()).hexdigest()
            self.assertEqual(payload["source"][key], actual)
        self.assertEqual(payload["model_version"], matcher.MODEL_VERSION)

    # The evaluation report is a local, git-ignored output of matcher.py; it is
    # checked only where an operator has regenerated it next to the payload.
    @unittest.skipUnless(LOCAL_REPORT.is_file(), "local evaluation report not generated")
    def test_local_evaluation_report_reconciles_to_payload(self):
        root = SCRIPT.parents[3]
        known, _unknown, _concepts = matcher.load_inputs(root / "web/data/esrs_datapoints_ig3.json", root / "web/data/atomizer_xbrl_concepts_v1.json")
        payload = json.loads((root / "web/data/xbrl_concept_suggestions_v1.json").read_text())
        report = json.loads(LOCAL_REPORT.read_text())
        for key, relative in [("ig3_sha256", "web/data/esrs_datapoints_ig3.json"),
                              ("concept_map_sha256", "web/data/atomizer_xbrl_concepts_v1.json")]:
            actual = hashlib.sha256((root / relative).read_bytes()).hexdigest()
            self.assertEqual(report["source"][key], actual)
        self.assertEqual(report["model_version"], matcher.MODEL_VERSION)
        self.assertEqual(report["counts"]["known_mappings"], len(known))
        self.assertEqual(report["evaluation"]["metrics"], payload["evaluation_metrics"])
        reuse = report["dr_reuse_evaluation"]
        self.assertEqual(len(reuse["assignments"]), len(known))
        self.assertEqual(reuse["applicable_count"] + reuse["excluded_count"], len(known))
        self.assertEqual(payload["dr_reuse_metrics"], reuse["metrics"])
        points = {p["id"]: p for p in known}
        for entry in reuse["assignments"]:
            query = points[entry["datapoint_id"]]
            training_ids = [r["datapoint_id"] for r in reuse["assignments"] if r["fold"] != entry["fold"]]
            expected = any(points[i]["target"] == query["target"] and points[i]["dr"] == query["dr"] for i in training_ids)
            self.assertEqual(entry["applicable"], expected)
        for name, metrics in reuse["metrics"].items():
            reproduced = matcher.rank_metrics([r["ranks"][name] for r in reuse["assignments"] if r["applicable"]])
            self.assertEqual(metrics["count"], reproduced["count"])
            for metric in ("top_1", "top_5", "mrr"):
                self.assertAlmostEqual(metrics[metric], reproduced[metric], places=12)
        by_id = {p["id"]: p["target"] for p in known}
        concept_folds = {}
        for row in report["evaluation"]["assignments"]:
            concept_folds.setdefault(by_id[row["datapoint_id"]], set()).add(row["fold"])
        self.assertTrue(all(len(folds) == 1 for folds in concept_folds.values()))
        for model_name, metrics in report["evaluation"]["metrics"].items():
            ranks = [p["ranks"][model_name] for p in report["evaluation"]["assignments"]]
            reproduced = matcher.rank_metrics(ranks)
            for metric in ("top_1", "top_5", "mrr"):
                self.assertAlmostEqual(metrics[metric], reproduced[metric], places=12)


if __name__ == "__main__":
    unittest.main()
