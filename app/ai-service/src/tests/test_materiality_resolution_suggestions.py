import csv
import tempfile
import unittest
from pathlib import Path

from materiality_resolution_suggestions import build_review_resolution_suggestions


class MaterialityResolutionSuggestionsTest(unittest.TestCase):
    def test_suggests_single_child_when_evidence_contains_specific_candidate_label(self):
        result = build_review_resolution_suggestions(
            review_queue=[
                _queue_row(
                    excerpt=(
                        "The DMA table marks Working conditions as material, "
                        "including Secure employment for own workforce."
                    )
                )
            ],
            mapping=_mapping(),
        )

        self.assertEqual(len(result.suggestions), 1)
        suggestion = result.suggestions[0]
        self.assertEqual(suggestion["suggestion_status"], "unique_child_match")
        self.assertFalse(suggestion["requires_human_review"])
        self.assertEqual(
            suggestion["decision_template"]["approved_python_esrs_keys"],
            ["esrs_s1_own_working_conditions_own_safe_employment"],
        )
        self.assertGreaterEqual(suggestion["confidence"], 0.95)
        self.assertEqual(suggestion["candidate_matches"][0]["matched_terms"], ["Secure employment"])

    def test_keeps_parent_only_when_no_child_specific_term_is_present(self):
        result = build_review_resolution_suggestions(
            review_queue=[
                _queue_row(
                    excerpt="The DMA table marks Working conditions as material."
                )
            ],
            mapping=_mapping(),
        )

        suggestion = result.suggestions[0]
        self.assertEqual(suggestion["suggestion_status"], "parent_only_or_needs_review")
        self.assertTrue(suggestion["requires_human_review"])
        self.assertEqual(suggestion["decision_template"]["approved_python_esrs_keys"], [])

    def test_requires_review_when_multiple_child_terms_are_present(self):
        result = build_review_resolution_suggestions(
            review_queue=[
                _queue_row(
                    excerpt=(
                        "The DMA table marks Working conditions as material: "
                        "Secure employment and Working time."
                    )
                )
            ],
            mapping=_mapping(),
        )

        suggestion = result.suggestions[0]
        self.assertEqual(suggestion["suggestion_status"], "multiple_child_matches_needs_review")
        self.assertTrue(suggestion["requires_human_review"])
        self.assertEqual(
            suggestion["matched_python_esrs_keys"],
            [
                "esrs_s1_own_working_conditions_own_safe_employment",
                "esrs_s1_own_working_conditions_own_working_time",
            ],
        )

    def test_suggests_child_from_official_translated_candidate_label(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            official_path = Path(temp_dir) / "official.csv"
            _write_official_translation_csv(
                official_path,
                [
                    {
                        "ar16_index": "28",
                        "language": "es",
                        "official_theme": "Personal propio",
                        "official_subtheme": "Condiciones de trabajo",
                        "official_subtopic": "Empleo seguro",
                    },
                    {
                        "ar16_index": "29",
                        "language": "es",
                        "official_theme": "Personal propio",
                        "official_subtheme": "Condiciones de trabajo",
                        "official_subtopic": "Tiempo de trabajo",
                    },
                ],
            )

            result = build_review_resolution_suggestions(
                review_queue=[
                    _queue_row(
                        excerpt=(
                            "La tabla de doble materialidad marca Condiciones de trabajo "
                            "como material, incluyendo Empleo seguro."
                        )
                    )
                ],
                mapping=_mapping(),
                official_translations_path=official_path,
            )

        suggestion = result.suggestions[0]
        self.assertEqual(suggestion["suggestion_status"], "unique_child_match")
        self.assertEqual(
            suggestion["decision_template"]["approved_python_esrs_keys"],
            ["esrs_s1_own_working_conditions_own_safe_employment"],
        )
        self.assertEqual(suggestion["candidate_matches"][0]["matched_terms"], ["Empleo seguro"])

    def test_suggests_child_from_non_latin_official_translated_candidate_label(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            official_path = Path(temp_dir) / "official.csv"
            _write_official_translation_csv(
                official_path,
                [
                    {
                        "ar16_index": "28",
                        "language": "bg",
                        "official_theme": "Собствена работна сила",
                        "official_subtheme": "Условия на труд",
                        "official_subtopic": "Сигурна заетост",
                    },
                    {
                        "ar16_index": "29",
                        "language": "bg",
                        "official_theme": "Собствена работна сила",
                        "official_subtheme": "Условия на труд",
                        "official_subtopic": "Работно време",
                    },
                ],
            )

            result = build_review_resolution_suggestions(
                review_queue=[
                    _queue_row(
                        excerpt=(
                            "Оценката за двойна същественост посочва Условия на труд "
                            "като съществени, включително Сигурна заетост."
                        )
                    )
                ],
                mapping=_mapping(),
                official_translations_path=official_path,
            )

        suggestion = result.suggestions[0]
        self.assertEqual(suggestion["suggestion_status"], "unique_child_match")
        self.assertEqual(
            suggestion["decision_template"]["approved_python_esrs_keys"],
            ["esrs_s1_own_working_conditions_own_safe_employment"],
        )
        self.assertEqual(suggestion["candidate_matches"][0]["matched_terms"], ["Сигурна заетост"])


def _queue_row(*, excerpt: str) -> dict:
    return {
        "review_row_id": "review-1",
        "review_status": "needs_review",
        "review_reason": "parent_multi_child_review_required",
        "company_name": "Acme SA",
        "source_file": "Acme.pdf",
        "report_url": "https://example.com/acme.pdf",
        "report_year": 2024,
        "matched_term": "Working conditions",
        "matched_term_normalized": "working conditions",
        "candidate_python_esrs_keys": [
            "esrs_s1_own_working_conditions_own_safe_employment",
            "esrs_s1_own_working_conditions_own_working_time",
        ],
        "candidate_ar16_topic_ids": [28, 29],
        "evidence_items": [
            {
                "evidence_id": "evidence-1",
                "evidence_type": "dma_table",
                "evidence_strength": "direct",
                "page_number": 5,
                "bbox": {"x0": 1, "y0": 2, "x1": 3, "y1": 4},
                "structured_locator": "zone_id=page-5;match_term=Working conditions",
                "excerpt": excerpt,
                "scope": "group",
                "extractor_method": "deterministic",
                "source_text_trusted": False,
            }
        ],
    }


def _mapping() -> dict:
    return {
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
