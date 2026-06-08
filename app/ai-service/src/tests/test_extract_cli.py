import tempfile
import textwrap
import unittest
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

from extract import Extractor, ensure_ar16_reconciled, parse_tasks


class ExtractCliTest(unittest.TestCase):
    def test_parse_tasks_returns_task_names_for_builtin_tasks(self):
        tasks = parse_tasks(["company_data", "esrse1"])

        self.assertEqual(tasks, ["company_data", "esrse1"])
        self.assertEqual(Extractor.task_map["company_data"][0].task_name(), "company_data")

    def test_parse_tasks_registers_active_yaml_task_by_task_name(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            yaml_path = Path(temp_dir) / "ESRS_TEST.yaml"
            yaml_path.write_text(
                textwrap.dedent(
                    """
                    task:
                      active: true
                      name: ESRS TEST
                      description: Test task
                      themes:
                        - name: Test Field
                          description: Test field description
                    """
                ),
                encoding="utf-8",
            )

            try:
                with redirect_stdout(StringIO()):
                    tasks = parse_tasks([str(yaml_path)])
            finally:
                Extractor.task_map.pop("ESRS TEST", None)

        self.assertEqual(tasks, ["ESRS TEST"])

    def test_ar16_preflight_passes_for_current_project_mapping(self):
        report = ensure_ar16_reconciled()

        self.assertFalse(report.has_unmatched())
        self.assertEqual(report.invalid_equivalences, [])


if __name__ == "__main__":
    unittest.main()
