import tempfile
import unittest
from pathlib import Path

import fitz

from materiality_extraction import detect_materiality_zones
from structured_pdf import build_structured_pdf_artifact, extract_structured_pdf_pages


class StructuredPdfTest(unittest.TestCase):
    def test_extracts_pages_with_text_blocks_and_pdf_hash(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = Path(temp_dir) / "report.pdf"
            _write_pdf(
                pdf_path,
                [
                    "Double materiality assessment\nClimate change is material.",
                    "Regular sustainability disclosures.",
                ],
            )

            artifact = build_structured_pdf_artifact(
                pdf_path=pdf_path,
                source_file="report.pdf",
                report_url="https://example.com/report.pdf",
                report_year=2025,
                max_pages=1,
            )

        self.assertEqual(artifact["source_file"], "report.pdf")
        self.assertEqual(artifact["report_url"], "https://example.com/report.pdf")
        self.assertEqual(artifact["report_year"], 2025)
        self.assertEqual(artifact["page_count"], 2)
        self.assertEqual(len(artifact["pages"]), 1)
        self.assertEqual(artifact["pages"][0]["page_number"], 1)
        self.assertIn("Double materiality assessment", artifact["pages"][0]["text"])
        self.assertGreater(artifact["pages"][0]["text_yield_chars"], 10)
        self.assertGreater(len(artifact["pdf_sha256"]), 30)
        self.assertGreater(len(artifact["pages"][0]["blocks"]), 0)
        self.assertIn("bbox", artifact["pages"][0]["blocks"][0])

    def test_extracted_pages_feed_materiality_zone_detector(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            pdf_path = Path(temp_dir) / "report.pdf"
            _write_pdf(pdf_path, ["Materiality matrix\nWater and workforce are material."])

            pages = extract_structured_pdf_pages(pdf_path=pdf_path)
            zones = detect_materiality_zones(pages)

        self.assertEqual(len(zones), 1)
        self.assertEqual(zones[0]["zone_type"], "materiality_matrix")
        self.assertEqual(zones[0]["page_number"], 1)


def _write_pdf(pdf_path: Path, page_texts: list[str]) -> None:
    document = fitz.open()
    for text in page_texts:
        page = document.new_page()
        page.insert_text((72, 72), text)
    document.save(pdf_path)
    document.close()
