import csv
import json
import tempfile
import unittest
from pathlib import Path

from materiality_reviewed_dataset import build_reviewed_materiality_dataset_from_evidence


class MaterialityReviewedDatasetTest(unittest.TestCase):
    def test_promotes_unique_single_token_child_term(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(zones_path, [_zone(page_number=5)])
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        page_number=5,
                        esrs_key="esrs_e5_waste_management",
                        match_term="Waste",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_e5_waste_management",
                                "status": "approved",
                                "ar16_topic_ids": [27],
                                "web_theme_en": "Circular economy",
                                "web_subtheme_en": "Waste",
                                "web_subtopic_en": None,
                                "web_label_en": "Waste",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_reviewed_materiality_dataset_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-v5",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(len(result.child_labels), 1)
        self.assertEqual(result.child_labels[0]["python_esrs_key"], "esrs_e5_waste_management")
        self.assertEqual(result.child_labels[0]["matched_topic_id"], 27)
        self.assertEqual(result.child_labels[0]["promotion_rule"], "child_exact")
        self.assertEqual(result.parent_labels, [])
        self.assertEqual(result.review_queue, [])
        self.assertEqual(result.blocked, [])

    def test_keeps_multi_child_parent_term_out_of_child_training_labels(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(zones_path, [_zone(page_number=5)])
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        page_number=5,
                        esrs_key="esrs_s1_own_working_conditions_own_safe_employment",
                        match_term="Working conditions",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_s1_own_working_conditions_own_safe_employment",
                                "status": "approved",
                                "ar16_topic_ids": [28],
                                "web_theme_en": "Own staff",
                                "web_subtheme_en": "Working conditions",
                                "web_subtopic_en": "Secure employment",
                                "web_label_en": "Secure employment",
                            },
                            {
                                "python_esrs_key": "esrs_s1_own_working_conditions_own_working_time",
                                "status": "approved",
                                "ar16_topic_ids": [29],
                                "web_theme_en": "Own staff",
                                "web_subtheme_en": "Working conditions",
                                "web_subtopic_en": "Working time",
                                "web_label_en": "Working time",
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_reviewed_materiality_dataset_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-v5",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.child_labels, [])
        self.assertEqual(len(result.parent_labels), 1)
        self.assertEqual(
            result.parent_labels[0]["candidate_python_esrs_keys"],
            [
                "esrs_s1_own_working_conditions_own_safe_employment",
                "esrs_s1_own_working_conditions_own_working_time",
            ],
        )
        self.assertEqual(result.parent_labels[0]["review_reason"], "parent_multi_child_review_required")
        self.assertEqual(len(result.review_queue), 1)
        self.assertEqual(result.review_queue[0]["required_action"], "resolve_to_child_topics_or_keep_parent_only")

    def test_routes_ambiguous_child_term_to_review_queue(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(zones_path, [_zone(page_number=5)])
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        page_number=5,
                        esrs_key="esrs_s1_own_other_rights_own_privacy",
                        match_term="Privacy",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_s1_own_other_rights_own_privacy",
                                "status": "approved",
                                "ar16_topic_ids": [42],
                                "web_theme_en": "Own staff",
                                "web_subtheme_en": "Other work-related rights",
                                "web_subtopic_en": "Privacy",
                                "web_label_en": "Privacy",
                            },
                            {
                                "python_esrs_key": "esrs_s4_consumer_information_privacy",
                                "status": "approved",
                                "ar16_topic_ids": [80],
                                "web_theme_en": "Consumers and end-users",
                                "web_subtheme_en": "Information-related impacts for consumers and/or end-users",
                                "web_subtopic_en": "Privacy",
                                "web_label_en": "Privacy",
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_reviewed_materiality_dataset_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-v5",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.child_labels, [])
        self.assertEqual(len(result.review_queue), 1)
        self.assertEqual(
            result.review_queue[0]["review_reason"],
            "ambiguous_child_term_review_required",
        )

    def test_normalizes_pdf_layout_hyphen_in_parent_term_before_resolution(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(zones_path, [_zone(page_number=5)])
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        page_number=5,
                        esrs_key="esrs_s4_consumer_information_freedom_expression",
                        match_term="Consumers and end- users",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_s4_consumer_information_freedom_expression",
                                "status": "approved",
                                "ar16_topic_ids": [79],
                                "web_theme_en": "Consumers and end-users",
                                "web_subtheme_en": "Information-related impacts for consumers and/or end-users",
                                "web_subtopic_en": "Freedom of expression",
                                "web_label_en": "Freedom of expression",
                            },
                            {
                                "python_esrs_key": "esrs_s4_consumer_information_privacy",
                                "status": "approved",
                                "ar16_topic_ids": [80],
                                "web_theme_en": "Consumers and end-users",
                                "web_subtheme_en": "Information-related impacts for consumers and/or end-users",
                                "web_subtopic_en": "Privacy",
                                "web_label_en": "Privacy",
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_reviewed_materiality_dataset_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-v5",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.blocked, [])
        self.assertEqual(result.child_labels, [])
        self.assertEqual(len(result.review_queue), 1)
        self.assertEqual(
            result.review_queue[0]["matched_term_normalized"],
            "consumers and end-users",
        )

    def test_blocks_negative_materiality_context(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(zones_path, [_zone(page_number=5)])
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        page_number=5,
                        esrs_key="esrs_e5_waste_management",
                        match_term="Waste",
                        excerpt="Waste was not material in the double materiality assessment.",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_e5_waste_management",
                                "status": "approved",
                                "ar16_topic_ids": [27],
                                "web_theme_en": "Circular economy",
                                "web_subtheme_en": "Waste",
                                "web_subtopic_en": None,
                                "web_label_en": "Waste",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_reviewed_materiality_dataset_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-v5",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.child_labels, [])
        self.assertEqual(result.parent_labels, [])
        self.assertEqual(result.review_queue, [])
        self.assertEqual(result.blocked[0]["reason"], "negative_materiality_context")

    def test_promotes_official_translated_child_term(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"
            official_path = root / "official.csv"

            _write_jsonl(zones_path, [_zone(page_number=5)])
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        page_number=5,
                        esrs_key="esrs_e1_climate_change_mitigation",
                        match_term="Mitigación del cambio climático",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_e1_climate_change_mitigation",
                                "status": "approved",
                                "ar16_topic_ids": [2],
                                "web_theme_en": "Climate change",
                                "web_subtheme_en": "Climate change mitigation",
                                "web_subtopic_en": None,
                                "web_label_en": "Climate change mitigation",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            _write_official_translation_csv(
                official_path,
                [
                    {
                        "ar16_index": "2",
                        "language": "es",
                        "official_theme": "Cambio climático",
                        "official_subtheme": "Mitigación del cambio climático",
                        "official_subtopic": "",
                    }
                ],
            )

            result = build_reviewed_materiality_dataset_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-v5",
                reviewed_at="2026-06-09T12:00:00+00:00",
                official_translations_path=official_path,
            )

        self.assertEqual(len(result.child_labels), 1)
        self.assertEqual(result.child_labels[0]["python_esrs_key"], "esrs_e1_climate_change_mitigation")
        self.assertEqual(result.child_labels[0]["matched_topic_id"], 2)
        self.assertEqual(result.parent_labels, [])
        self.assertEqual(result.review_queue, [])
        self.assertEqual(result.blocked, [])


def _write_jsonl(path: Path, rows: list[dict]):
    path.write_text(
        "".join(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in rows),
        encoding="utf-8",
    )


def _zone(*, page_number: int) -> dict:
    return {
        "review_row_id": f"zone-{page_number}",
        "review_status": "needs_review",
        "company_name": "Acme SA",
        "source_file": "Acme.pdf",
        "report_url": "https://example.com/report.pdf",
        "report_year": 2024,
        "pdf_sha256": "hash-a",
        "page_number": page_number,
        "zone_id": f"page-{page_number}",
        "zone_type": "dma_table_or_section",
        "zone_confidence": 0.9,
        "zone_detection_reason": "matched phrase: double materiality assessment",
        "blockers": [],
        "excerpt": "The double materiality assessment identifies the term as material.",
    }


def _evidence(
    *,
    page_number: int,
    esrs_key: str,
    match_term: str,
    excerpt: str | None = None,
) -> dict:
    return {
        "evidence_id": f"evidence-{match_term}",
        "cohort_report_year": 2024,
        "company_name": "Acme SA",
        "source_file": "Acme.pdf",
        "report_url": "https://example.com/report.pdf",
        "local_pdf_path": "D:\\private\\report.pdf",
        "pdf_sha256": "hash-a",
        "esrs_key": esrs_key,
        "match_term": match_term,
        "page_number": page_number,
        "bbox": {"x0": 1, "y0": 2, "x1": 3, "y1": 4},
        "excerpt": excerpt
        or f"The double materiality assessment identifies {match_term} as material.",
        "extraction_method": "pymupdf.search_for",
        "review_status": "pending",
    }


def _write_official_translation_csv(path: Path, rows: list[dict[str, str]]) -> None:
    fieldnames = [
        "ar16_index",
        "canonical_esrs",
        "language",
        "official_esrs",
        "official_theme",
        "official_subtheme",
        "official_subtopic",
        "source_publication_id",
        "source_url",
    ]
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            output = {field: "" for field in fieldnames}
            output.update(row)
            writer.writerow(output)


if __name__ == "__main__":
    unittest.main()
