import csv
import tempfile
import unittest
import zipfile
from pathlib import Path

from ar16_official_translations import (
    OFFICIAL_EU_LANGUAGE_CODES,
    build_download_url,
    flatten_ar16_table_rows,
    parse_ar16_rows_from_fmx4,
    write_official_translation_csv,
    _extract_esrs_code,
)


class Ar16OfficialTranslationsTest(unittest.TestCase):
    def test_download_url_uses_publications_office_handler(self):
        url = build_download_url("es", "fmx4")

        self.assertIn("op.europa.eu/o/opportal-service/download-handler", url)
        self.assertIn("format=fmx4", url)
        self.assertIn("language=es", url)

    def test_official_language_list_contains_eu_languages(self):
        self.assertEqual(len(OFFICIAL_EU_LANGUAGE_CODES), 24)
        self.assertIn("es", OFFICIAL_EU_LANGUAGE_CODES)
        self.assertIn("de", OFFICIAL_EU_LANGUAGE_CODES)
        self.assertIn("fr", OFFICIAL_EU_LANGUAGE_CODES)

    def test_extract_esrs_code_accepts_cyrillic_e_from_official_bulgarian_text(self):
        self.assertEqual(_extract_esrs_code(["ЕСОУ Е1"]), "E1")

    def test_flatten_ar16_table_ignores_e4_example_items(self):
        rows = [
            {1: ["Topical ESRS"], 2: ["Sustainability matters covered in topical ESRS"]},
            {1: [], 2: ["Topic"], 3: ["Sub-topic"], 4: ["Sub-sub-topics"]},
            {
                1: ["ESRS E4"],
                2: ["Biodiversity and ecosystems"],
                3: ["Direct impact drivers of biodiversity loss"],
                4: ["Climate Change", "Pollution"],
            },
            {
                3: ["Impacts on the state of species"],
                4: ["Species population size", "Species global extinction risk"],
            },
        ]

        flattened = flatten_ar16_table_rows(rows)

        self.assertEqual(
            flattened,
            [
                {
                    "esrs": "E4",
                    "theme": "Biodiversity and ecosystems",
                    "subtheme": "Direct impact drivers of biodiversity loss",
                    "subtopic": "Climate Change",
                },
                {
                    "esrs": "E4",
                    "theme": "Biodiversity and ecosystems",
                    "subtheme": "Direct impact drivers of biodiversity loss",
                    "subtopic": "Pollution",
                },
                {
                    "esrs": "E4",
                    "theme": "Biodiversity and ecosystems",
                    "subtheme": "Impacts on the state of species",
                    "subtopic": "",
                },
            ],
        )

    def test_parse_ar16_rows_from_fmx4_zip(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            fmx_path = Path(temp_dir) / "sample.fmx4"
            xml = """<?xml version="1.0" encoding="UTF-8"?>
<ROOT><TBL><CORPUS>
<ROW><CELL COL="1">Topical ESRS</CELL><CELL COL="2">Sustainability matters covered in topical ESRS</CELL></ROW>
<ROW><CELL COL="1"><IE/></CELL><CELL COL="2">Topic</CELL><CELL COL="3">Sub-topic</CELL><CELL COL="4">Sub-sub-topics</CELL></ROW>
<ROW><CELL COL="1">ESRS E1</CELL><CELL COL="2">Climate change</CELL><CELL COL="3"><LIST><ITEM><P>Climate change adaptation</P></ITEM><ITEM><P>Energy</P></ITEM></LIST></CELL><CELL COL="4"><IE/></CELL></ROW>
<ROW><CELL COL="1">ESRS E2</CELL><CELL COL="2">Pollution</CELL><CELL COL="3"><LIST><ITEM><P>Pollution of air</P></ITEM></LIST></CELL><CELL COL="4"><IE/></CELL></ROW>
<ROW><CELL COL="1">ESRS E3</CELL><CELL COL="2">Water and marine resources</CELL><CELL COL="3"><LIST><ITEM><P>Water</P></ITEM><ITEM><P>Marine resources</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Water consumption</P></ITEM><ITEM><P>Water withdrawals</P></ITEM><ITEM><P>Water discharges</P></ITEM><ITEM><P>Water discharges in the oceans</P></ITEM><ITEM><P>Extraction and use of marine resources</P></ITEM></LIST></CELL></ROW>
<ROW><CELL COL="1">ESRS E4</CELL><CELL COL="2">Biodiversity and ecosystems</CELL><CELL COL="3"><LIST><ITEM><P>Direct impact drivers of biodiversity loss</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Climate Change</P></ITEM></LIST></CELL></ROW>
<ROW><CELL COL="1">ESRS E5</CELL><CELL COL="2">Circular economy</CELL><CELL COL="3"><LIST><ITEM><P>Waste</P></ITEM></LIST></CELL><CELL COL="4"><IE/></CELL></ROW>
<ROW><CELL COL="1">ESRS S1</CELL><CELL COL="2">Own workforce</CELL><CELL COL="3"><LIST><ITEM><P>Working conditions</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Secure employment</P></ITEM></LIST></CELL></ROW>
<ROW><CELL COL="1">ESRS S2</CELL><CELL COL="2">Workers in the value chain</CELL><CELL COL="3"><LIST><ITEM><P>Working conditions</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Secure employment</P></ITEM></LIST></CELL></ROW>
<ROW><CELL COL="1">ESRS S3</CELL><CELL COL="2">Affected communities</CELL><CELL COL="3"><LIST><ITEM><P>Communities' rights</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Adequate housing</P></ITEM></LIST></CELL></ROW>
<ROW><CELL COL="1">ESRS S4</CELL><CELL COL="2">Consumers and end-users</CELL><CELL COL="3"><LIST><ITEM><P>Information-related impacts</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Privacy</P></ITEM></LIST></CELL></ROW>
<ROW><CELL COL="1">ESRS G1</CELL><CELL COL="2">Business conduct</CELL><CELL COL="3"><LIST><ITEM><P>Corruption and bribery</P></ITEM></LIST></CELL><CELL COL="4"><LIST><ITEM><P>Incidents</P></ITEM></LIST></CELL></ROW>
</CORPUS></TBL></ROOT>"""
            with zipfile.ZipFile(fmx_path, "w") as archive:
                archive.writestr("sample.xml", xml)

            rows = parse_ar16_rows_from_fmx4(fmx_path)

        self.assertGreaterEqual(len(rows), 10)
        self.assertEqual(rows[0]["esrs"], "E1")
        self.assertEqual(rows[0]["subtheme"], "Climate change adaptation")
        self.assertEqual(rows[-1]["esrs"], "G1")
        self.assertEqual(rows[-1]["subtopic"], "Incidents")

    def test_write_official_translation_csv_aligns_to_canonical_inventory(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            output = root / "translations.csv"
            canonical = [
                {"ar16_index": "1", "esrs": "E1"},
                {"ar16_index": "2", "esrs": "E1"},
            ]
            translations = {
                "es": [
                    {
                        "esrs": "E1",
                        "theme": "Cambio climatico",
                        "subtheme": "Adaptacion",
                        "subtopic": "",
                    },
                    {
                        "esrs": "E1",
                        "theme": "Cambio climatico",
                        "subtheme": "Energia",
                        "subtopic": "",
                    },
                ]
            }

            summary = write_official_translation_csv(
                translations_by_language=translations,
                canonical_rows=canonical,
                output_path=output,
            )
            with output.open(encoding="utf-8") as handle:
                rows = list(csv.DictReader(handle))

        self.assertEqual(summary["row_count"], 2)
        self.assertEqual(rows[0]["ar16_index"], "1")
        self.assertEqual(rows[0]["language"], "es")
        self.assertEqual(rows[0]["official_subtheme"], "Adaptacion")


if __name__ == "__main__":
    unittest.main()
