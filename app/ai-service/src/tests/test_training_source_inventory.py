import csv
import tempfile
import unittest
from pathlib import Path


class TrainingSourceInventoryTest(unittest.TestCase):
    def test_scans_expected_reports_across_source_roots(self):
        from training_source_inventory import (
            load_expected_report_files,
            scan_report_sources,
        )

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            dataset = root / "companies.csv"
            with dataset.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=["file", "company"], delimiter=";")
                writer.writeheader()
                writer.writerow({"file": "A.pdf", "company": "A"})
                writer.writerow({"file": "B.pdf", "company": "B"})
                writer.writerow({"file": "C.pdf", "company": "C"})

            source_one = root / "source-one"
            source_two = root / "source-two"
            source_one.mkdir()
            source_two.mkdir()
            (source_one / "A.pdf").write_bytes(b"%PDF-1.4")
            (source_one / "B.pdf").write_bytes(b"%PDF-1.4")
            (source_two / "B.pdf").write_bytes(b"%PDF-1.4")

            expected = load_expected_report_files(dataset)
            inventory = scan_report_sources(expected, [source_one, source_two])

        self.assertEqual(expected, ["A.pdf", "B.pdf", "C.pdf"])
        self.assertEqual(inventory.expected_count, 3)
        self.assertEqual(inventory.found_unique_count, 2)
        self.assertEqual(inventory.missing_files, ["C.pdf"])
        self.assertEqual(inventory.duplicate_files, ["B.pdf"])
        self.assertEqual(len(inventory.matches["B.pdf"]), 2)

    def test_default_source_roots_skips_refresh_roots_by_default(self):
        from training_source_inventory import default_source_roots

        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            first = base / "efrag_2024"
            second = base / "sygris_2024_reports"
            refresh = base / "fy2025_direct"
            first.mkdir()
            second.mkdir()
            refresh.mkdir()
            (base / "source-roots.json").write_text("{}", encoding="utf-8")

            roots = default_source_roots(base)

        self.assertEqual(roots, [first, second])

    def test_default_source_roots_can_include_refresh_roots(self):
        from training_source_inventory import default_source_roots

        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            first = base / "efrag_2024"
            refresh = base / "fy2025_direct"
            first.mkdir()
            refresh.mkdir()

            roots = default_source_roots(base, include_refresh_roots=True)

        self.assertEqual(roots, [first, refresh])


if __name__ == "__main__":
    unittest.main()
