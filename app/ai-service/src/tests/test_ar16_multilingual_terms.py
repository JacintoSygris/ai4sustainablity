import csv
import tempfile
import unittest
from pathlib import Path

from ar16_multilingual_terms import (
    load_official_terms_by_ar16_id,
    official_terms_for_mapping_row,
    term_variants,
)


class Ar16MultilingualTermsTest(unittest.TestCase):
    def test_loads_official_terms_with_dehyphenated_variants(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "official.csv"
            _write_official_csv(
                path,
                [
                    {
                        "ar16_index": "1",
                        "language": "de",
                        "official_theme": "Klima-wandel",
                        "official_subtheme": "Anpassung an den Klimawandel",
                        "official_subtopic": "",
                    }
                ],
            )

            by_id = load_official_terms_by_ar16_id(path)

        displays = {term["display"] for term in by_id[1]}
        normalized = {term["normalized"] for term in by_id[1]}
        self.assertIn("Klima-wandel", displays)
        self.assertIn("klimawandel", normalized)
        self.assertIn("anpassung an den klimawandel", normalized)

    def test_mapping_row_marks_subtheme_as_child_when_no_subtopic_exists(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "official.csv"
            _write_official_csv(
                path,
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
            by_id = load_official_terms_by_ar16_id(path)

            terms = official_terms_for_mapping_row(
                {
                    "python_esrs_key": "esrs_e1_climate_change_mitigation",
                    "ar16_topic_ids": [2],
                    "web_subtopic_en": None,
                },
                official_terms_by_ar16_id=by_id,
                include_parent=True,
                include_child=True,
            )

        roles_by_normalized = {term["normalized"]: term["role"] for term in terms}
        self.assertEqual(
            roles_by_normalized["mitigación del cambio climático"],
            "official_subtheme_child",
        )
        self.assertEqual(
            roles_by_normalized["cambio climático"],
            "official_theme_parent",
        )

    def test_mapping_row_marks_subtopic_as_child_when_subtopic_exists(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "official.csv"
            _write_official_csv(
                path,
                [
                    {
                        "ar16_index": "28",
                        "language": "es",
                        "official_theme": "Personal propio",
                        "official_subtheme": "Condiciones de trabajo",
                        "official_subtopic": "Empleo seguro",
                    }
                ],
            )
            by_id = load_official_terms_by_ar16_id(path)

            terms = official_terms_for_mapping_row(
                {
                    "python_esrs_key": "esrs_s1_own_working_conditions_own_safe_employment",
                    "ar16_topic_ids": [28],
                    "web_subtopic_en": "Secure employment",
                },
                official_terms_by_ar16_id=by_id,
                include_parent=True,
                include_child=True,
            )

        roles_by_normalized = {term["normalized"]: term["role"] for term in terms}
        self.assertEqual(roles_by_normalized["empleo seguro"], "official_subtopic_child")
        self.assertEqual(
            roles_by_normalized["condiciones de trabajo"],
            "official_subtheme_parent",
        )

    def test_term_variants_keep_literal_and_dehyphenated_forms(self):
        self.assertIn("klima-wandel", term_variants("Klima-wandel"))
        self.assertIn("klimawandel", term_variants("Klima-wandel"))
        self.assertIn("podnebne spremembe", term_variants("Pod-nebne spre-membe"))

    def test_term_variants_include_known_official_romanian_layout_repairs(self):
        self.assertIn("incidente", term_variants("ncidente"))
        self.assertIn(
            "prevenirea și depistarea, inclusiv formarea",
            term_variants("și depistarea, inclusiv formarea Prevenirea"),
        )


def _write_official_csv(path: Path, rows: list[dict[str, str]]) -> None:
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
