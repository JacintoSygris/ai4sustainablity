import json
import hashlib
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace

from materiality_training_export import build_materiality_training_release


class MaterialityTrainingExportTest(unittest.TestCase):
    def test_writes_release_with_only_human_approved_labels_that_pass_all_gates(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            result = build_materiality_training_release(
                labels=[
                    _label(label_id="good-1"),
                    _label(
                        label_id="machine-proposed",
                        review_status="machine_proposed",
                    ),
                ],
                registry_entries=[_registry_entry(10, "approved")],
                output_dir=Path(temp_dir),
                release_id="approved-20260609",
            )

            labels_path = Path(result["labels_path"])
            manifest_path = Path(result["manifest_path"])
            blocked_path = Path(result["blocked_path"])
            exported_rows = [json.loads(line) for line in labels_path.read_text(encoding="utf-8").splitlines()]
            blocked_rows = [json.loads(line) for line in blocked_path.read_text(encoding="utf-8").splitlines()]
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))

        self.assertEqual(result["exported_count"], 1)
        self.assertEqual(result["blocked_count"], 1)
        self.assertEqual(exported_rows[0]["label_id"], "good-1")
        self.assertEqual(exported_rows[0]["matched_topic_id"], 10)
        self.assertEqual(manifest["release_id"], "approved-20260609")
        self.assertEqual(manifest["exported_count"], 1)
        self.assertEqual(manifest["blocked_count"], 1)
        self.assertEqual(manifest["labels_sha256"], result["labels_sha256"])
        self.assertEqual(manifest["blocked_sha256"], result["blocked_sha256"])
        self.assertEqual(manifest["input_label_count"], 2)
        self.assertEqual(manifest["registry_entry_count"], 1)
        self.assertIn("registry_entries_sha256", manifest)
        self.assertEqual(blocked_rows[0]["label_id"], "machine-proposed")
        self.assertIn("machine_proposed", result["blocked"][0]["reason"])

    def test_blocks_open_blockers_non_approved_registry_rows_and_missing_evidence(self):
        labels = [
            _label(
                label_id="open-blocker",
                blocker_status="open",
                topic_blockers=["prompt_injection_detected"],
            ),
            _label(label_id="non-approved-registry"),
            _label(label_id="missing-evidence", evidence_items=[]),
        ]

        with tempfile.TemporaryDirectory() as temp_dir:
            result = build_materiality_training_release(
                labels=labels,
                registry_entries=[
                    _registry_entry(10, "needs_review"),
                ],
                output_dir=Path(temp_dir),
                release_id="approved-20260609",
            )

        self.assertEqual(result["exported_count"], 0)
        self.assertEqual(result["blocked_count"], 3)
        reasons = {row["label_id"]: row["reason"] for row in result["blocked"]}
        self.assertIn("open blocker", reasons["open-blocker"])
        self.assertIn("registry/topic gate", reasons["non-approved-registry"])
        self.assertIn("evidence_items", reasons["missing-evidence"])

    def test_blocks_gold_promoted_without_matching_gold_manifest_hash(self):
        label = _label(
            label_id="gold-row",
            review_status="gold_promoted",
            gold_rule_manifest_hash="wrong-hash",
        )
        gold_manifest = _gold_rule_manifest()

        with tempfile.TemporaryDirectory() as temp_dir:
            result = build_materiality_training_release(
                labels=[label],
                registry_entries=[_registry_entry(10, "approved")],
                output_dir=Path(temp_dir),
                release_id="approved-20260609",
                gold_rule_manifest=gold_manifest,
            )

        self.assertEqual(result["exported_count"], 0)
        self.assertEqual(result["blocked_count"], 1)
        self.assertIn("gold_rule_manifest_hash", result["blocked"][0]["reason"])

    def test_blocks_incomplete_gold_rule_manifest_even_when_hash_matches(self):
        gold_manifest = {
            "rule_id": "gold-v1",
            "precision_by_status": {"explicit_material": 0.97},
        }
        label = _label(
            label_id="gold-row",
            review_status="gold_promoted",
            gold_rule_manifest_hash=_stable_sha256_json(gold_manifest),
        )

        with tempfile.TemporaryDirectory() as temp_dir:
            result = build_materiality_training_release(
                labels=[label],
                registry_entries=[_registry_entry(10, "approved")],
                output_dir=Path(temp_dir),
                release_id="approved-20260609",
                gold_rule_manifest=gold_manifest,
            )

        self.assertEqual(result["exported_count"], 0)
        self.assertEqual(result["blocked_count"], 1)
        self.assertIn("gold_rule_manifest", result["blocked"][0]["reason"])

    def test_persists_approved_gold_rule_manifest_sidecar_and_export_provenance(self):
        gold_manifest = _gold_rule_manifest()
        label = _label(
            label_id="gold-row",
            review_status="gold_promoted",
            gold_rule_manifest_hash=_stable_sha256_json(gold_manifest),
        )

        with tempfile.TemporaryDirectory() as temp_dir:
            result = build_materiality_training_release(
                labels=[label],
                registry_entries=[_registry_entry(10, "approved")],
                output_dir=Path(temp_dir),
                release_id="approved-20260609",
                gold_rule_manifest=gold_manifest,
                run_id="train-run-1",
                created_at="2026-06-09T12:00:00+00:00",
            )
            manifest = json.loads(Path(result["manifest_path"]).read_text(encoding="utf-8"))
            gold_sidecar = json.loads(Path(result["gold_rule_manifest_path"]).read_text(encoding="utf-8"))

        self.assertEqual(result["exported_count"], 1)
        self.assertEqual(manifest["run_id"], "train-run-1")
        self.assertEqual(manifest["created_at"], "2026-06-09T12:00:00+00:00")
        self.assertEqual(manifest["gold_rule_manifest_sha256"], _stable_sha256_json(gold_manifest))
        self.assertEqual(gold_sidecar["rule_id"], "gold-v1")

    def test_export_strips_internal_pdf_paths_from_training_rows(self):
        label = _label(label_id="path-row")
        label["pdf_path"] = "D:\\private\\report.pdf"
        label["local_pdf_path"] = "D:\\private\\report.pdf"

        with tempfile.TemporaryDirectory() as temp_dir:
            result = build_materiality_training_release(
                labels=[label],
                registry_entries=[_registry_entry(10, "approved")],
                output_dir=Path(temp_dir),
                release_id="approved-20260609",
            )
            exported_row = json.loads(Path(result["labels_path"]).read_text(encoding="utf-8").splitlines()[0])

        self.assertEqual(result["exported_count"], 1)
        self.assertNotIn("pdf_path", exported_row)
        self.assertNotIn("local_pdf_path", exported_row)


def _registry_entry(ar16_topic_id: int, mapping_status: str):
    return SimpleNamespace(ar16_topic_id=ar16_topic_id, mapping_status=mapping_status)


def _label(
    *,
    label_id: str,
    primary_status: str = "explicit_material",
    review_status: str = "human_approved",
    blocker_status: str = "not_applicable",
    topic_blockers: list[str] | None = None,
    evidence_items: list[dict] | None = None,
    gold_rule_manifest_hash: str | None = None,
) -> dict:
    label = {
        "label_id": label_id,
        "report_id": "report-a",
        "company_id": "company-a",
        "matched_topic_id": 10,
        "primary_status": primary_status,
        "review_status": review_status,
        "topic_resolution_method": "registry_exact_match",
        "topic_resolution_state": "resolved_to_registry_topic",
        "blocker_status": blocker_status,
        "topic_blockers": topic_blockers or [],
        "evidence_items": evidence_items if evidence_items is not None else [_evidence_item()],
    }
    if gold_rule_manifest_hash is not None:
        label["gold_rule_manifest_hash"] = gold_rule_manifest_hash
    return label


def _evidence_item() -> dict:
    return {
        "evidence_id": "evidence-1",
        "evidence_type": "materiality_table",
        "evidence_strength": "direct",
        "page_number": 12,
        "bbox": [0, 1, 2, 3],
        "structured_locator": "",
        "excerpt": "The topic is identified as material in the DMA table.",
        "scope": "group",
        "extractor_method": "deterministic",
        "source_text_trusted": False,
    }


def _gold_rule_manifest() -> dict:
    return {
        "rule_id": "gold-v1",
        "schema_version": "gold-rule-v1",
        "status": "approved",
        "approved_by": "lead-reviewer",
        "approved_at": "2026-06-09T12:00:00+00:00",
        "quality": {
            "minimum_precision": 0.97,
            "reviewed_label_count": 50,
        },
    }


def _stable_sha256_json(payload: dict) -> str:
    text = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(text.encode("utf-8")).hexdigest()
