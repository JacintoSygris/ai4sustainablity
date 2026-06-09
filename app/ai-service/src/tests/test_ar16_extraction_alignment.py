import csv
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

from ar16_align_extraction import main as align_cli_main
from registry.extraction_alignment import align_extraction_csv


class Ar16ExtractionAlignmentTest(unittest.TestCase):
    def test_aligns_yaml_extraction_values_to_ar16_keys_and_keeps_trace_separate(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            paths = _write_alignment_fixture(root)
            output_path = root / "aligned.csv"
            trace_path = root / "trace.csv"

            result = align_extraction_csv(
                input_path=paths["input"],
                output_path=output_path,
                trace_output_path=trace_path,
                mapping_path=paths["mapping"],
                key_equivalences_path=paths["equivalences"],
            )

            aligned_rows = _read_csv(output_path)
            trace_rows = _read_csv(trace_path)

        self.assertEqual(result["rows"], 1)
        self.assertEqual(result["review_required"], 1)
        self.assertEqual(
            aligned_rows,
            [
                {
                    "file": "report.pdf",
                    "esrs_e1_adaptation_to_climate_change": "Adaptation text",
                    "esrs_e1_energy_use": "Energy text",
                }
            ],
        )
        self.assertEqual(trace_rows[0]["status"], "ignored_summary")
        equivalent_row = next(row for row in trace_rows if row["status"] == "equivalent")
        self.assertEqual(equivalent_row["target_key"], "esrs_e1_adaptation_to_climate_change")
        self.assertEqual(equivalent_row["source_context_value"], "(page 10)")
        unknown_row = next(row for row in trace_rows if row["status"] == "unknown_esrs_key")
        self.assertEqual(unknown_row["review_required"], "true")
        self.assertEqual(unknown_row["source_key"], "esrs_e1_unknown_topic")

    def test_cli_writes_aligned_values_and_trace_with_json_summary(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            paths = _write_alignment_fixture(root)
            output_path = root / "aligned.csv"
            trace_path = root / "trace.csv"
            stdout = StringIO()

            with redirect_stdout(stdout):
                exit_code = align_cli_main(
                    [
                        "--input",
                        str(paths["input"]),
                        "--output",
                        str(output_path),
                        "--trace-output",
                        str(trace_path),
                        "--mapping",
                        str(paths["mapping"]),
                        "--equivalences",
                        str(paths["equivalences"]),
                    ]
                )

            summary = json.loads(stdout.getvalue())
            aligned_rows = _read_csv(output_path)
            trace_rows = _read_csv(trace_path)

        self.assertEqual(exit_code, 0)
        self.assertEqual(summary["rows"], 1)
        self.assertEqual(summary["review_required"], 1)
        self.assertEqual(aligned_rows[0]["esrs_e1_energy_use"], "Energy text")
        self.assertEqual(
            next(row for row in trace_rows if row["status"] == "unknown_esrs_key")["review_required"],
            "true",
        )

    def test_does_not_silently_overwrite_duplicate_target_values(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            paths = _write_alignment_fixture(root)
            input_path = root / "duplicate-target.csv"
            output_path = root / "aligned.csv"
            trace_path = root / "trace.csv"
            input_path.write_text(
                (
                    "file;esrs_e1_adaptation_to_climate_change;"
                    "esrs_e1_climate_change_adaptation\n"
                    "report.pdf;Direct value;Alias value\n"
                ),
                encoding="utf-8",
            )

            result = align_extraction_csv(
                input_path=input_path,
                output_path=output_path,
                trace_output_path=trace_path,
                mapping_path=paths["mapping"],
                key_equivalences_path=paths["equivalences"],
            )
            aligned_rows = _read_csv(output_path)
            trace_rows = _read_csv(trace_path)

        self.assertEqual(result["review_required"], 1)
        self.assertEqual(
            aligned_rows[0]["esrs_e1_adaptation_to_climate_change"],
            "Direct value",
        )
        duplicate_row = next(row for row in trace_rows if row["status"] == "duplicate_target")
        self.assertEqual(duplicate_row["review_required"], "true")
        self.assertEqual(duplicate_row["source_value"], "Alias value")

    def test_flags_invalid_equivalence_target_as_review_required(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            paths = _write_alignment_fixture(root)
            paths["equivalences"].write_text(
                json.dumps(
                    {
                        "equivalences": [
                            {
                                "source_surface": "yaml",
                                "source_key": "esrs_e1_climate_change_adaptation",
                                "target_key": "esrs_e1_not_approved",
                                "status": "equivalent",
                                "basis": "bad fixture",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            output_path = root / "aligned.csv"
            trace_path = root / "trace.csv"

            result = align_extraction_csv(
                input_path=paths["input"],
                output_path=output_path,
                trace_output_path=trace_path,
                mapping_path=paths["mapping"],
                key_equivalences_path=paths["equivalences"],
            )
            aligned_rows = _read_csv(output_path)
            trace_rows = _read_csv(trace_path)

        self.assertEqual(result["review_required"], 2)
        invalid_row = next(row for row in trace_rows if row["source_key"] == "esrs_e1_climate_change_adaptation")
        self.assertEqual(invalid_row["status"], "invalid_equivalence_target:not_in_mapping")
        self.assertEqual(invalid_row["review_required"], "true")
        self.assertNotIn("esrs_e1_not_approved", aligned_rows[0])

    def test_flags_duplicate_yaml_source_equivalences_as_review_required(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            paths = _write_alignment_fixture(root)
            paths["equivalences"].write_text(
                json.dumps(
                    {
                        "equivalences": [
                            {
                                "source_surface": "yaml",
                                "source_key": "esrs_e1_climate_change_adaptation",
                                "target_key": "esrs_e1_adaptation_to_climate_change",
                                "status": "equivalent",
                                "basis": "first",
                            },
                            {
                                "source_surface": "yaml",
                                "source_key": "esrs_e1_climate_change_adaptation",
                                "target_key": "esrs_e1_energy_use",
                                "status": "equivalent",
                                "basis": "second",
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )
            output_path = root / "aligned.csv"
            trace_path = root / "trace.csv"

            result = align_extraction_csv(
                input_path=paths["input"],
                output_path=output_path,
                trace_output_path=trace_path,
                mapping_path=paths["mapping"],
                key_equivalences_path=paths["equivalences"],
            )
            aligned_rows = _read_csv(output_path)
            trace_rows = _read_csv(trace_path)

        self.assertEqual(result["review_required"], 2)
        duplicate_row = next(row for row in trace_rows if row["source_key"] == "esrs_e1_climate_change_adaptation")
        self.assertEqual(duplicate_row["status"], "duplicate_source_equivalence")
        self.assertEqual(duplicate_row["review_required"], "true")
        self.assertNotIn("esrs_e1_adaptation_to_climate_change", aligned_rows[0])


def _write_alignment_fixture(root: Path) -> dict[str, Path]:
    mapping_path = root / "ar16_mapping.json"
    equivalences_path = root / "equivalences.json"
    input_path = root / "extracted.csv"

    mapping_path.write_text(
        json.dumps(
            {
                "candidate_topics": [
                    {
                        "ar16_topic_id": 1,
                        "web_esrs": "E1",
                        "web_label_en": "Adaptation to climate change",
                        "web_theme_en": "Climate change",
                        "python_esrs_keys": ["esrs_e1_adaptation_to_climate_change"],
                        "mapping_status": "approved",
                    },
                    {
                        "ar16_topic_id": 3,
                        "web_esrs": "E1",
                        "web_label_en": "Energy",
                        "web_theme_en": "Climate change",
                        "python_esrs_keys": ["esrs_e1_energy_use"],
                        "mapping_status": "approved",
                    },
                ]
            }
        ),
        encoding="utf-8",
    )
    equivalences_path.write_text(
        json.dumps(
            {
                "equivalences": [
                    {
                        "source_surface": "yaml",
                        "source_key": "esrs_e1_climate_change_adaptation",
                        "target_key": "esrs_e1_adaptation_to_climate_change",
                        "status": "equivalent",
                        "basis": "Same topic with YAML naming.",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    input_path.write_text(
        (
            "file;esrs_e1_summary;esrs_e1_climate_change_adaptation;"
            "esrs_e1_climate_change_adaptation_context;esrs_e1_energy_use;"
            "esrs_e1_unknown_topic\n"
            "report.pdf;True;Adaptation text;(page 10);Energy text;Unknown text\n"
        ),
        encoding="utf-8",
    )

    return {
        "mapping": mapping_path,
        "equivalences": equivalences_path,
        "input": input_path,
    }


def _read_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle, delimiter=";"))


if __name__ == "__main__":
    unittest.main()
