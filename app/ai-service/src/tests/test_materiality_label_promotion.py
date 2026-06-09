import csv
import json
import tempfile
import unittest
from pathlib import Path

from materiality_label_promotion import (
    build_materiality_labels_from_evidence,
    write_materiality_training_csvs,
)


class MaterialityLabelPromotionTest(unittest.TestCase):
    def test_promotes_specific_topic_evidence_on_materiality_zone(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(
                zones_path,
                [
                    _zone(
                        pdf_sha256="hash-a",
                        source_file="Acme.pdf",
                        page_number=5,
                    )
                ],
            )
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        pdf_sha256="hash-a",
                        source_file="Acme.pdf",
                        page_number=5,
                        esrs_key="esrs_s1_own_working_conditions_own_safe_employment",
                        match_term="Secure employment",
                    )
                ],
            )
            mapping_path.write_text(json.dumps(_mapping()), encoding="utf-8")

            result = build_materiality_labels_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-gold-rule",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(len(result.labels), 1)
        self.assertEqual(result.blocked_count, 0)
        label = result.labels[0]
        self.assertEqual(label["primary_status"], "explicit_material")
        self.assertEqual(label["review_status"], "gold_promoted")
        self.assertEqual(label["matched_topic_id"], 28)
        self.assertEqual(label["python_esrs_key"], "esrs_s1_own_working_conditions_own_safe_employment")
        self.assertEqual(label["evidence_items"][0]["page_number"], 5)
        self.assertEqual(label["evidence_items"][0]["source_text_trusted"], False)

    def test_blocks_generic_parent_term_even_on_materiality_zone(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(
                zones_path,
                [_zone(pdf_sha256="hash-a", source_file="Acme.pdf", page_number=5)],
            )
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        pdf_sha256="hash-a",
                        source_file="Acme.pdf",
                        page_number=5,
                        esrs_key="esrs_s1_own_working_conditions_own_safe_employment",
                        match_term="Working conditions",
                    )
                ],
            )
            mapping_path.write_text(json.dumps(_mapping()), encoding="utf-8")

            result = build_materiality_labels_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-gold-rule",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.labels, [])
        self.assertEqual(result.blocked_count, 1)
        self.assertEqual(result.blocked[0]["reason"], "not_specific_mapping_term")

    def test_blocks_ambiguous_specific_term_that_maps_to_multiple_topics(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(
                zones_path,
                [_zone(pdf_sha256="hash-a", source_file="Acme.pdf", page_number=5)],
            )
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        pdf_sha256="hash-a",
                        source_file="Acme.pdf",
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
                                "web_label_en": "Privacy",
                                "web_subtopic_en": "Privacy",
                            },
                            {
                                "python_esrs_key": "esrs_s4_consumer_information_privacy",
                                "status": "approved",
                                "ar16_topic_ids": [80],
                                "web_label_en": "Privacy",
                                "web_subtopic_en": "Privacy",
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_materiality_labels_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-gold-rule",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.labels, [])
        self.assertEqual(result.blocked_count, 1)
        self.assertEqual(result.blocked[0]["reason"], "ambiguous_mapping_term")

    def test_blocks_single_token_term_for_automatic_gold_promotion(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(
                zones_path,
                [_zone(pdf_sha256="hash-a", source_file="Acme.pdf", page_number=5)],
            )
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        pdf_sha256="hash-a",
                        source_file="Acme.pdf",
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
                                "web_label_en": "Waste",
                                "web_subtheme_en": "Waste",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_materiality_labels_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-gold-rule",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.labels, [])
        self.assertEqual(result.blocked_count, 1)
        self.assertEqual(result.blocked[0]["reason"], "single_token_mapping_term")

    def test_blocks_known_broad_mapping_term_for_automatic_gold_promotion(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            zones_path = root / "zones.jsonl"
            evidence_path = root / "evidence.jsonl"
            mapping_path = root / "mapping.json"

            _write_jsonl(
                zones_path,
                [_zone(pdf_sha256="hash-a", source_file="Acme.pdf", page_number=5)],
            )
            _write_jsonl(
                evidence_path,
                [
                    _evidence(
                        pdf_sha256="hash-a",
                        source_file="Acme.pdf",
                        page_number=5,
                        esrs_key="esrs_e4_direct_impact_on_biodiversity_loss_climate_change",
                        match_term="Climate change",
                    )
                ],
            )
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_e4_direct_impact_on_biodiversity_loss_climate_change",
                                "status": "approved",
                                "ar16_topic_ids": [13],
                                "web_label_en": "Climate change",
                                "web_subtopic_en": "Climate change",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            result = build_materiality_labels_from_evidence(
                zones_path=zones_path,
                evidence_path=evidence_path,
                mapping_path=mapping_path,
                reviewer_id="deterministic-gold-rule",
                reviewed_at="2026-06-09T12:00:00+00:00",
            )

        self.assertEqual(result.labels, [])
        self.assertEqual(result.blocked_count, 1)
        self.assertEqual(result.blocked[0]["reason"], "broad_mapping_term")

    def test_training_csvs_use_unique_report_ids_and_block_missing_profiles(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            companies_path = root / "companies.csv"
            targets_path = root / "targets.json"
            labels_path = root / "labels.jsonl"
            output_companies = root / "out-companies.csv"
            output_esrs = root / "out-esrs.csv"
            blocked_path = root / "blocked.jsonl"

            companies_path.write_text(
                "file;company_data_company_name;company_data_sector;company_data_products_services;"
                "company_data_headquarters_country;company_data_subsidiaries_countries;"
                "company_data_company_size;company_data_juridic_form;company_data_annual_turnover;"
                "company_data_stock_listed;company_data_reporting_currency\n"
                "Acme.pdf;Acme SA;C;A;Spain;EU;LARGE;PLC;100.0;True;EUR\n",
                encoding="utf-8",
            )
            targets_path.write_text(
                json.dumps(
                    [
                        _target(2024, "base_2024", "Acme.pdf", "Acme SA"),
                        _target(2025, "base_2025", "Acme.pdf", "Acme SA"),
                        _target(2025, "new_company_2025", "NewCo.pdf", "NewCo SA"),
                    ]
                ),
                encoding="utf-8",
            )
            _write_jsonl(
                labels_path,
                [
                    _label(2024, "Acme.pdf", "esrs_s1_own_working_conditions_own_safe_employment"),
                    _label(2025, "Acme.pdf", "esrs_s1_own_working_conditions_own_safe_employment"),
                    _label(2025, "NewCo.pdf", "esrs_s1_own_working_conditions_own_safe_employment"),
                ],
            )

            result = write_materiality_training_csvs(
                labels_path=labels_path,
                targets_path=targets_path,
                source_companies_path=companies_path,
                esrs_columns=["esrs_s1_own_working_conditions_own_safe_employment"],
                output_companies_path=output_companies,
                output_esrs_path=output_esrs,
                blocked_path=blocked_path,
            )

            with output_companies.open(encoding="utf-8", newline="") as handle:
                company_rows = list(csv.DictReader(handle, delimiter=";"))
            with output_esrs.open(encoding="utf-8", newline="") as handle:
                esrs_rows = list(csv.DictReader(handle, delimiter=";"))
            blocked = [json.loads(line) for line in blocked_path.read_text(encoding="utf-8").splitlines()]

        self.assertEqual(result["training_row_count"], 2)
        self.assertEqual(result["blocked_count"], 1)
        self.assertEqual([row["file"] for row in company_rows], ["2024::Acme.pdf", "2025::Acme.pdf"])
        self.assertEqual([row["file"] for row in esrs_rows], ["2024::Acme.pdf", "2025::Acme.pdf"])
        self.assertEqual(esrs_rows[0]["esrs_s1_own_working_conditions_own_safe_employment"], "1")
        self.assertEqual(esrs_rows[1]["esrs_s1_own_working_conditions_own_safe_employment"], "1")
        self.assertEqual(blocked[0]["reason"], "company_profile_missing")


def _write_jsonl(path: Path, rows: list[dict]):
    path.write_text(
        "".join(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in rows),
        encoding="utf-8",
    )


def _zone(*, pdf_sha256: str, source_file: str, page_number: int) -> dict:
    return {
        "review_row_id": f"zone-{source_file}-{page_number}",
        "review_status": "needs_review",
        "company_name": "Acme SA",
        "source_file": source_file,
        "report_url": "https://example.com/report.pdf",
        "report_year": 2024,
        "pdf_sha256": pdf_sha256,
        "page_number": page_number,
        "zone_id": f"page-{page_number}",
        "zone_type": "dma_table_or_section",
        "zone_confidence": 0.9,
        "zone_detection_reason": "matched phrase: double materiality assessment",
        "blockers": [],
        "excerpt": "The double materiality assessment identifies Secure employment as material.",
    }


def _evidence(
    *,
    pdf_sha256: str,
    source_file: str,
    page_number: int,
    esrs_key: str,
    match_term: str,
) -> dict:
    return {
        "evidence_id": f"evidence-{match_term}",
        "cohort_report_year": 2024,
        "company_name": "Acme SA",
        "source_file": source_file,
        "report_url": "https://example.com/report.pdf",
        "local_pdf_path": "D:\\private\\report.pdf",
        "pdf_sha256": pdf_sha256,
        "esrs_key": esrs_key,
        "match_term": match_term,
        "page_number": page_number,
        "bbox": {"x0": 1, "y0": 2, "x1": 3, "y1": 4},
        "excerpt": f"The double materiality assessment identifies {match_term} as material.",
        "extraction_method": "pymupdf.search_for",
        "review_status": "pending",
    }


def _mapping() -> dict:
    return {
        "keys": [
            {
                "python_esrs_key": "esrs_s1_own_working_conditions_own_safe_employment",
                "status": "approved",
                "ar16_topic_ids": [28],
                "web_label_en": "Secure employment",
                "web_theme_en": "Own staff",
                "web_subtheme_en": "Working conditions",
                "web_subtopic_en": "Secure employment",
            }
        ]
    }


def _target(report_year: int, cohort: str, source_file: str, company_name: str) -> dict:
    return {
        "report_year": report_year,
        "company_name": company_name,
        "source_file": source_file,
        "pdf_path": f"D:\\reports\\{source_file}",
        "report_url": "https://example.com/report.pdf",
        "positive_esrs_keys": [],
        "cohort": cohort,
    }


def _label(report_year: int, source_file: str, esrs_key: str) -> dict:
    return {
        "label_id": f"{report_year}-{source_file}-{esrs_key}",
        "report_year": report_year,
        "source_file": source_file,
        "report_url": "https://example.com/report.pdf",
        "python_esrs_key": esrs_key,
        "matched_topic_id": 28,
        "primary_status": "explicit_material",
        "review_status": "gold_promoted",
        "evidence_items": [],
    }


if __name__ == "__main__":
    unittest.main()
