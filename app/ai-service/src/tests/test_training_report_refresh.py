import csv
import json
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace


class TrainingReportRefreshTest(unittest.TestCase):
    def test_candidate_urls_include_report_year_and_publication_year_variants(self):
        from training_report_refresh import derive_candidate_urls

        url = "https://example.com/reports/2025/company-annual-report-2024.pdf"

        candidates = derive_candidate_urls(url, source_year=2024, target_year=2025)

        self.assertEqual(
            candidates,
            [
                "https://example.com/reports/2025/company-annual-report-2025.pdf",
                "https://example.com/reports/2026/company-annual-report-2025.pdf",
                "https://example.com/reports/2026/company-annual-report-2024.pdf",
            ],
        )

    def test_build_manifest_targets_uses_metadata_url_by_downloaded_filename(self):
        from training_report_refresh import (
            build_manifest_targets,
            load_companies,
            load_metadata_urls_by_filename,
        )

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=["file", "company_data_company_name"],
                    delimiter=";",
                )
                writer.writeheader()
                writer.writerow(
                    {
                        "file": "Acme_2024.pdf",
                        "company_data_company_name": "Acme, S.A.",
                    }
                )

            metadata = root / "metadata.json"
            metadata.write_text(
                json.dumps(
                    {
                        "urls": {
                            "https://example.com/2025/acme-report-2024.pdf": {
                                "path": str(root / "Acme_2024.pdf")
                            }
                        }
                    }
                ),
                encoding="utf-8",
            )

            companies = load_companies(dataset)
            urls_by_filename = load_metadata_urls_by_filename(metadata)
            targets = build_manifest_targets(
                companies,
                urls_by_filename,
                source_year=2024,
                target_year=2025,
            )

        self.assertEqual(len(targets), 3)
        self.assertEqual(targets[0]["company_name"], "Acme, S.A.")
        self.assertEqual(targets[0]["source_file"], "Acme_2024.pdf")
        self.assertEqual(targets[0]["expected_filename"], "Acme_SA_FY2025_01.pdf")
        self.assertEqual(targets[0]["url"], "https://example.com/2025/acme-report-2025.pdf")
        self.assertFalse(targets[0]["allow_variant"])

    def test_build_manifest_targets_can_match_efrag_catalog_by_file_stem(self):
        from training_report_refresh import (
            CompanyRecord,
            build_manifest_targets,
            catalog_urls_by_company_key,
        )

        companies = [
            CompanyRecord(
                file="Ab_Inbev.pdf",
                company_name="Anheuser-Busch InBev NV/SA",
            )
        ]
        catalog = [
            {
                "name": "Ab Inbev",
                "report_url": "https://example.com/2025/ab-inbev-annual-report-2024.pdf",
            }
        ]

        targets = build_manifest_targets(
            companies,
            urls_by_filename={},
            urls_by_company_key=catalog_urls_by_company_key(catalog),
            source_year=2024,
            target_year=2025,
        )

        self.assertEqual(len(targets), 3)
        self.assertEqual(targets[0]["source_url"], "https://example.com/2025/ab-inbev-annual-report-2024.pdf")
        self.assertEqual(targets[0]["url"], "https://example.com/2025/ab-inbev-annual-report-2025.pdf")

    def test_validate_report_text_requires_target_year_and_csrd_or_esrs_marker(self):
        from training_report_refresh import validate_report_text

        ok = validate_report_text(
            "Annual Report 2025. Sustainability Statement prepared under CSRD and ESRS.",
            target_year=2025,
        )
        missing_year = validate_report_text(
            "Annual Report 2024. Sustainability Statement prepared under CSRD and ESRS.",
            target_year=2025,
        )
        missing_marker = validate_report_text(
            "Annual Report 2025. Governance and financial statements.",
            target_year=2025,
        )

        self.assertTrue(ok.is_valid)
        self.assertFalse(missing_year.is_valid)
        self.assertIn("target_year_not_found", missing_year.reasons)
        self.assertFalse(missing_marker.is_valid)
        self.assertIn("csrd_esrs_marker_not_found", missing_marker.reasons)

    def test_download_targets_stops_after_first_success_per_source_file(self):
        from training_report_refresh import download_targets_direct

        targets = [
            {
                "source_file": "Acme_2024.pdf",
                "expected_filename": "Acme_FY2025_01.pdf",
                "url": "https://example.com/first.pdf",
            },
            {
                "source_file": "Acme_2024.pdf",
                "expected_filename": "Acme_FY2025_02.pdf",
                "url": "https://example.com/second.pdf",
            },
        ]
        calls = []

        def fetcher(url):
            calls.append(url)
            return SimpleNamespace(
                body=b"%PDF-1.7\ncontent",
                status_code=200,
                content_type="application/pdf",
                final_url=url,
                error=None,
            )

        with tempfile.TemporaryDirectory() as temp_dir:
            out_dir = Path(temp_dir)
            summary = download_targets_direct(targets, out_dir, fetcher=fetcher)

            self.assertTrue((out_dir / "Acme_FY2025_01.pdf").exists())
            self.assertFalse((out_dir / "Acme_FY2025_02.pdf").exists())

        self.assertEqual(calls, ["https://example.com/first.pdf"])
        self.assertEqual(summary["installed"], 1)
        self.assertEqual(summary["skipped_after_success"], 1)

    def test_download_targets_accepts_manual_urls_manifest_shape(self):
        from training_report_refresh import download_targets_direct

        targets = [
            {
                "source_file": "NEW_Acme_FY2025.pdf",
                "company_name": "Acme",
                "urls": ["https://example.com/acme-annual-report-2025.pdf"],
            },
        ]
        calls = []

        def fetcher(url):
            calls.append(url)
            return SimpleNamespace(
                body=b"%PDF-1.7\ncontent",
                status_code=200,
                content_type="application/pdf",
                final_url=url,
                error=None,
            )

        with tempfile.TemporaryDirectory() as temp_dir:
            out_dir = Path(temp_dir)
            summary = download_targets_direct(targets, out_dir, fetcher=fetcher)

            self.assertTrue((out_dir / "NEW_Acme_FY2025.pdf").exists())

        self.assertEqual(calls, ["https://example.com/acme-annual-report-2025.pdf"])
        self.assertEqual(summary["installed"], 1)

    def test_decodes_duckduckgo_redirect_and_extracts_pdf_links(self):
        from training_report_refresh import decode_redirect_href, extract_candidate_links

        duck_href = (
            "//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fannual-report-2025.pdf"
            "&rut=abc"
        )
        bing_href = (
            "https://www.bing.com/ck/a?!&&u="
            "a1aHR0cHM6Ly9leGFtcGxlLmNvbS9yZXBvcnQucGRm&ntb=1"
        )
        html = '<a href="/files/company-sustainability-statement-2025.pdf">PDF</a>'

        self.assertEqual(
            decode_redirect_href(duck_href),
            "https://example.com/annual-report-2025.pdf",
        )
        self.assertEqual(decode_redirect_href(bing_href), "https://example.com/report.pdf")
        self.assertEqual(
            extract_candidate_links(html, "https://example.com/investors"),
            ["https://example.com/files/company-sustainability-statement-2025.pdf"],
        )


if __name__ == "__main__":
    unittest.main()
