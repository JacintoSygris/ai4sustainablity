import csv
import json
import tempfile
import unittest
from pathlib import Path

import fitz


class EvidenceExtractionTest(unittest.TestCase):
    def test_extracts_evidence_with_public_report_url_page_and_bbox(self):
        from evidence_extraction import extract_pdf_evidence

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = Path(temp_dir) / "report.pdf"
            document = fitz.open()
            page = document.new_page()
            page.insert_text((72, 72), "The sustainability statement covers climate change mitigation.")
            document.save(pdf_path)
            document.close()

            rows = extract_pdf_evidence(
                pdf_path=pdf_path,
                report_url="https://example.com/report-2025.pdf",
                report_year=2025,
                company_name="Example SA",
                source_file="Example.pdf",
                esrs_keys=["esrs_e1_climate_change_mitigation"],
                keyword_catalog={
                    "esrs_e1_climate_change_mitigation": ["climate change mitigation"]
                },
                max_pages=5,
                max_hits_per_key=3,
            )

        self.assertEqual(len(rows), 1)
        row = rows[0]
        self.assertEqual(row["report_url"], "https://example.com/report-2025.pdf")
        self.assertEqual(row["page_number"], 1)
        self.assertEqual(row["esrs_key"], "esrs_e1_climate_change_mitigation")
        self.assertIn("climate change mitigation", row["excerpt"].lower())
        self.assertGreater(row["bbox"]["x1"], row["bbox"]["x0"])
        self.assertGreater(row["bbox"]["y1"], row["bbox"]["y0"])
        self.assertEqual(row["review_status"], "pending")

    def test_extracts_evidence_with_company_reference_when_public_url_is_missing(self):
        from evidence_extraction import extract_pdf_evidence

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = Path(temp_dir) / "report.pdf"
            document = fitz.open()
            page = document.new_page()
            page.insert_text((72, 72), "The sustainability statement covers climate change mitigation.")
            document.save(pdf_path)
            document.close()

            rows = extract_pdf_evidence(
                pdf_path=pdf_path,
                report_url="company:Example SA",
                report_year=2024,
                company_name="Example SA",
                source_file="Example.pdf",
                esrs_keys=["esrs_e1_climate_change_mitigation"],
                keyword_catalog={
                    "esrs_e1_climate_change_mitigation": ["climate change mitigation"]
                },
                max_pages=5,
                max_hits_per_key=3,
            )

        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["report_url"], "company:Example SA")

    def test_evidence_id_is_unique_per_source_file_for_reused_report_pdf(self):
        from evidence_extraction import extract_pdf_evidence

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = Path(temp_dir) / "report.pdf"
            document = fitz.open()
            page = document.new_page()
            page.insert_text((72, 72), "The sustainability statement covers climate change mitigation.")
            document.save(pdf_path)
            document.close()

            first = extract_pdf_evidence(
                pdf_path=pdf_path,
                report_url="https://example.com/report-2024.pdf",
                report_year=2024,
                company_name="Example SA",
                source_file="Example_A.pdf",
                esrs_keys=["esrs_e1_climate_change_mitigation"],
                keyword_catalog={"esrs_e1_climate_change_mitigation": ["climate change mitigation"]},
                max_pages=5,
                max_hits_per_key=3,
            )
            second = extract_pdf_evidence(
                pdf_path=pdf_path,
                report_url="https://example.com/report-2024.pdf",
                report_year=2024,
                company_name="Example SA",
                source_file="Example_B.pdf",
                esrs_keys=["esrs_e1_climate_change_mitigation"],
                keyword_catalog={"esrs_e1_climate_change_mitigation": ["climate change mitigation"]},
                max_pages=5,
                max_hits_per_key=3,
            )

        self.assertEqual(len(first), 1)
        self.assertEqual(len(second), 1)
        self.assertNotEqual(first[0]["evidence_id"], second[0]["evidence_id"])

    def test_evidence_id_is_unique_per_report_url_for_same_source_file(self):
        from evidence_extraction import extract_pdf_evidence

        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = Path(temp_dir) / "report.pdf"
            document = fitz.open()
            page = document.new_page()
            page.insert_text((72, 72), "The sustainability statement covers climate change mitigation.")
            document.save(pdf_path)
            document.close()

            first = extract_pdf_evidence(
                pdf_path=pdf_path,
                report_url="https://example.com/report-2024.pdf",
                report_year=2024,
                company_name="Example SA",
                source_file="Example.pdf",
                esrs_keys=["esrs_e1_climate_change_mitigation"],
                keyword_catalog={"esrs_e1_climate_change_mitigation": ["climate change mitigation"]},
                max_pages=5,
                max_hits_per_key=3,
            )
            second = extract_pdf_evidence(
                pdf_path=pdf_path,
                report_url="https://example.com/report-2025.pdf",
                report_year=2025,
                company_name="Example SA",
                source_file="Example.pdf",
                esrs_keys=["esrs_e1_climate_change_mitigation"],
                keyword_catalog={"esrs_e1_climate_change_mitigation": ["climate change mitigation"]},
                max_pages=5,
                max_hits_per_key=3,
            )

        self.assertEqual(len(first), 1)
        self.assertEqual(len(second), 1)
        self.assertNotEqual(first[0]["evidence_id"], second[0]["evidence_id"])

    def test_build_2024_targets_uses_metadata_url_not_local_path(self):
        from evidence_extraction import build_targets_2024

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            pdf_path = root / "Acme.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "company_data_company_name"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow({"file": "Acme.pdf", "company_data_company_name": "Acme SA"})

            labels = root / "esrs.csv"
            with labels.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "esrs_e1_climate_change_mitigation"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow({"file": "Acme.pdf", "esrs_e1_climate_change_mitigation": "1"})

            metadata = root / "metadata.json"
            metadata.write_text(
                json.dumps(
                    {
                        "urls": {
                            "https://example.com/acme-2024.pdf": {
                                "path": str(pdf_path)
                            }
                        }
                    }
                ),
                encoding="utf-8",
            )

            targets = build_targets_2024(
                companies_path=dataset,
                esrs_path=labels,
                source_roots=[root],
                metadata_paths=[metadata],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "https://example.com/acme-2024.pdf")
        self.assertEqual(targets[0].positive_esrs_keys, ["esrs_e1_climate_change_mitigation"])

    def test_build_2024_targets_falls_back_to_efrag_catalog_url_by_company_name(self):
        from evidence_extraction import build_targets_2024

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            pdf_path = root / "Acme_Group.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "company_data_company_name"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow({"file": "Acme_Group.pdf", "company_data_company_name": "Acme Group SA"})

            labels = root / "esrs.csv"
            with labels.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "esrs_e1_climate_change_mitigation"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow({"file": "Acme_Group.pdf", "esrs_e1_climate_change_mitigation": "1"})

            metadata = root / "metadata.json"
            metadata.write_text(json.dumps({"urls": {}}), encoding="utf-8")

            catalog = root / "efrag-2024-api-companies.json"
            catalog.write_text(
                json.dumps(
                    {
                        "companies": [
                            {
                                "name": "Acme",
                                "report_url": "https://example.com/acme-catalog-2024.pdf",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            targets = build_targets_2024(
                companies_path=dataset,
                esrs_path=labels,
                source_roots=[root],
                metadata_paths=[metadata],
                catalog_paths=[catalog],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "https://example.com/acme-catalog-2024.pdf")

    def test_build_2024_targets_uses_reliable_targeted_download_log_by_expected_filename(self):
        from evidence_extraction import build_targets_2024

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            pdf_path = root / "Acme_Annual_Report_2024.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "company_data_company_name"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {"file": "Acme_Annual_Report_2024.pdf", "company_data_company_name": "Unmatched SA"}
                )

            labels = root / "esrs.csv"
            with labels.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "esrs_e1_climate_change_mitigation"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {"file": "Acme_Annual_Report_2024.pdf", "esrs_e1_climate_change_mitigation": "1"}
                )

            metadata = root / "metadata.json"
            metadata.write_text(json.dumps({"urls": {}}), encoding="utf-8")

            targeted_log = root / "targeted.json"
            targeted_log.write_text(
                json.dumps(
                    {
                        "results": [
                            {
                                "expected": "Acme_Annual_Report_2024.pdf",
                                "status": "downloaded_pdf",
                                "content_type": "application/pdf",
                                "final_url": "https://example.com/acme-targeted-2024.pdf",
                                "path": str(pdf_path),
                            },
                            {
                                "expected": "Acme_Annual_Report_2024.pdf",
                                "status": "downloaded",
                                "final_url": "https://example.com/acme-html-page",
                                "path": str(root / "Acme.txt"),
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )

            targets = build_targets_2024(
                companies_path=dataset,
                esrs_path=labels,
                source_roots=[root],
                metadata_paths=[metadata],
                catalog_paths=[],
                download_log_paths=[targeted_log],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "https://example.com/acme-targeted-2024.pdf")

    def test_build_2024_targets_uses_validated_url_override_by_source_file(self):
        from evidence_extraction import build_targets_2024

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            pdf_path = root / "Acme_Annual_Report_2024.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "company_data_company_name"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {"file": "Acme_Annual_Report_2024.pdf", "company_data_company_name": "Unmatched SA"}
                )

            labels = root / "esrs.csv"
            with labels.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "esrs_e1_climate_change_mitigation"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {"file": "Acme_Annual_Report_2024.pdf", "esrs_e1_climate_change_mitigation": "1"}
                )

            metadata = root / "metadata.json"
            metadata.write_text(json.dumps({"urls": {}}), encoding="utf-8")
            overrides = root / "validated-source-urls-2024.json"
            overrides.write_text(
                json.dumps(
                    {
                        "urls": [
                            {
                                "source_file": "Acme_Annual_Report_2024.pdf",
                                "report_url": "https://example.com/acme-validated-2024.pdf",
                                "validation": "sha256_match",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            targets = build_targets_2024(
                companies_path=dataset,
                esrs_path=labels,
                source_roots=[root],
                metadata_paths=[metadata],
                catalog_paths=[],
                download_log_paths=[],
                url_override_paths=[overrides],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "https://example.com/acme-validated-2024.pdf")

    def test_build_2024_targets_accepts_text_fingerprint_validated_url_override(self):
        from evidence_extraction import build_targets_2024

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            pdf_path = root / "Acme_Annual_Report_2024.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "company_data_company_name"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {"file": "Acme_Annual_Report_2024.pdf", "company_data_company_name": "Unmatched SA"}
                )

            labels = root / "esrs.csv"
            with labels.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "esrs_e1_climate_change_mitigation"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {"file": "Acme_Annual_Report_2024.pdf", "esrs_e1_climate_change_mitigation": "1"}
                )

            metadata = root / "metadata.json"
            metadata.write_text(json.dumps({"urls": {}}), encoding="utf-8")
            overrides = root / "validated-source-urls-2024.json"
            overrides.write_text(
                json.dumps(
                    {
                        "urls": [
                            {
                                "source_file": "Acme_Annual_Report_2024.pdf",
                                "report_url": "https://example.com/acme-text-match-2024.pdf",
                                "validation": "text_fingerprint_match",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            targets = build_targets_2024(
                companies_path=dataset,
                esrs_path=labels,
                source_roots=[root],
                metadata_paths=[metadata],
                catalog_paths=[],
                download_log_paths=[],
                url_override_paths=[overrides],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "https://example.com/acme-text-match-2024.pdf")

    def test_build_2024_targets_falls_back_to_company_reference_when_url_is_missing(self):
        from evidence_extraction import build_targets_2024

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            reports = root / "reports"
            reports.mkdir()
            pdf_path = reports / "Acme.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            companies = root / "companies.csv"
            esrs = root / "esrs.csv"
            companies.write_text(
                "file;company_data_company_name\n"
                "Acme.pdf;Acme SA\n",
                encoding="utf-8",
            )
            esrs.write_text(
                "file;esrs_e1_climate_change_mitigation\n"
                "Acme.pdf;1\n",
                encoding="utf-8",
            )

            targets = build_targets_2024(
                companies_path=companies,
                esrs_path=esrs,
                source_roots=[reports],
                metadata_paths=[],
                catalog_paths=[],
                download_log_paths=[],
                url_override_paths=[],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "company:Acme SA")

    def test_build_2025_targets_uses_installed_attempt_final_url(self):
        from evidence_extraction import build_targets_2025

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            reports_dir = root / "reports"
            logs_dir = root / "logs"
            reports_dir.mkdir()
            logs_dir.mkdir()
            pdf_path = reports_dir / "Acme_FY2025.pdf"
            pdf_path.write_bytes(b"%PDF-1.7")
            (logs_dir / "attempts.jsonl").write_text(
                json.dumps(
                    {
                        "result": "installed",
                        "company_name": "Acme SA",
                        "source_file": "Acme.pdf",
                        "path": str(pdf_path),
                        "url": "https://example.com/acme-2025-candidate.pdf",
                        "final_url": "https://cdn.example.com/acme-2025.pdf",
                    }
                )
                + "\n",
                encoding="utf-8",
            )

            targets = build_targets_2025(
                reports_dirs=[reports_dir],
                logs_dir=logs_dir,
                label_keys=["esrs_e1_climate_change_mitigation"],
            )

        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0].report_url, "https://cdn.example.com/acme-2025.pdf")
        self.assertEqual(targets[0].positive_esrs_keys, ["esrs_e1_climate_change_mitigation"])

    def test_keyword_catalog_includes_official_ar16_translations(self):
        from evidence_extraction import build_keyword_catalog

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            mapping_path = root / "mapping.json"
            official_path = root / "official.csv"
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_e1_climate_change_mitigation",
                                "status": "approved",
                                "ar16_topic_ids": [2],
                                "web_label_en": "Climate change mitigation",
                                "web_theme_en": "Climate change",
                                "web_subtheme_en": "Climate change mitigation",
                                "web_subtopic_en": None,
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

            catalog = build_keyword_catalog(
                ["esrs_e1_climate_change_mitigation"],
                mapping_path=mapping_path,
                official_translations_path=official_path,
            )

        self.assertIn(
            "Mitigación del cambio climático",
            catalog["esrs_e1_climate_change_mitigation"],
        )
        self.assertIn("Cambio climático", catalog["esrs_e1_climate_change_mitigation"])


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
