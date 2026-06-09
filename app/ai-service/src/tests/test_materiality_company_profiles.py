import csv
import json
import tempfile
import unittest
from pathlib import Path

from materiality_company_profiles import (
    augment_company_profiles_for_missing_reports,
    augment_company_profiles_for_missing_reports_from_files,
)


class MaterialityCompanyProfilesTest(unittest.TestCase):
    def test_copies_existing_company_profile_when_company_name_matches(self):
        result = augment_company_profiles_for_missing_reports(
            source_companies=[
                {
                    "file": "Acme_2024.pdf",
                    "company_data_company_name": "Acme SA",
                    "company_data_sector": "C",
                    "company_data_company_size": "LARGE",
                }
            ],
            missing_reports=[
                {
                    "source_file": "Acme_2025.pdf",
                    "company_name": "Acme SA",
                    "reason": "company_profile_missing",
                }
            ],
        )

        self.assertEqual(result.added_count, 1)
        self.assertEqual(result.rows[-1]["file"], "Acme_2025.pdf")
        self.assertEqual(result.rows[-1]["company_data_sector"], "C")
        self.assertEqual(result.rows[-1]["company_data_profile_quality"], "copied_from_existing_company_profile")

    def test_creates_traceable_placeholder_when_profile_is_unknown(self):
        result = augment_company_profiles_for_missing_reports(
            source_companies=[
                {
                    "file": "Acme_2024.pdf",
                    "company_data_company_name": "Acme SA",
                    "company_data_sector": "C",
                    "company_data_company_size": "LARGE",
                }
            ],
            missing_reports=[
                {
                    "source_file": "NewCo_2025.pdf",
                    "company_name": "NewCo BV",
                    "reason": "company_profile_missing",
                }
            ],
        )

        self.assertEqual(result.rows[-1]["file"], "NewCo_2025.pdf")
        self.assertEqual(result.rows[-1]["company_data_company_name"], "NewCo BV")
        self.assertEqual(result.rows[-1]["company_data_company_size"], "UNKNOWN")
        self.assertEqual(result.rows[-1]["company_data_profile_quality"], "placeholder_missing_profile")

    def test_writes_augmented_csv_and_summary(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "companies.csv"
            missing = root / "blocked.jsonl"
            output = root / "augmented.csv"
            summary = root / "summary.json"
            source.write_text(
                "file;company_data_company_name;company_data_sector;company_data_company_size\n"
                "Acme_2024.pdf;Acme SA;C;LARGE\n",
                encoding="utf-8",
            )
            missing.write_text(
                json.dumps(
                    {
                        "source_file": "NewCo_2025.pdf",
                        "company_name": "NewCo BV",
                        "reason": "company_profile_missing",
                    }
                )
                + "\n",
                encoding="utf-8",
            )

            result = augment_company_profiles_for_missing_reports_from_files(
                source_companies_path=source,
                missing_reports_path=missing,
                output_companies_path=output,
                summary_path=summary,
            )

            with output.open(encoding="utf-8", newline="") as handle:
                rows = list(csv.DictReader(handle, delimiter=";"))
            payload = json.loads(summary.read_text(encoding="utf-8"))

        self.assertEqual(result["added_count"], 1)
        self.assertEqual(rows[-1]["company_data_profile_quality"], "placeholder_missing_profile")
        self.assertEqual(payload["added_count"], 1)


if __name__ == "__main__":
    unittest.main()
