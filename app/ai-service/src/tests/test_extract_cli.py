import argparse
import tempfile
import textwrap
import unittest
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path
from unittest.mock import patch

from extract import Extractor, ensure_ar16_reconciled, main, parse_tasks
from processors.processor import Processor


class FakeTask:
    def prompt(self):
        return "extract test field"

    def data_format(self):
        return object

    def task_name(self):
        return "fake_task"


class FakeResponse:
    def to_header_row(self, include_file=True):
        return "file;field" if include_file else "field"

    def to_csv_row(self, file_name=None):
        return f"{file_name};value" if file_name else "value"


class FakeProcessor:
    def extract(self, path):
        return "document text"


class FakeHandler:
    def __init__(self, response):
        self.response = response

    def call_llm(self, prompt, text, data_format):
        return self.response


class VectorFallbackHandler(FakeHandler):
    def __init__(self):
        super().__init__(FakeResponse())
        self.uploaded = False

    def upload_file(self, file_path):
        self.uploaded = True

    def call_with_prompt(self, file_path, prompt, data_format):
        return None


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

    def test_parse_tasks_rejects_empty_yaml_directory(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            with self.assertRaisesRegex(argparse.ArgumentTypeError, "No active extraction tasks"):
                parse_tasks([temp_dir])

    def test_process_file_accepts_bare_results_filename(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            report_path = root / "report.txt"
            report_path.write_text("test report", encoding="utf-8")
            extractor = _fake_extractor(FakeResponse())

            with _temporary_cwd(root):
                with patch.object(Processor, "get_processor", return_value=FakeProcessor()):
                    extractor.process_file(str(report_path))

                output = root / "results.csv"
                self.assertTrue(output.exists())
                self.assertIn("report.txt;value", output.read_text(encoding="utf-8"))

    def test_process_file_marks_no_output_as_failed_without_empty_success_row(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            report_path = root / "report.txt"
            report_path.write_text("test report", encoding="utf-8")
            output = root / "results.csv"
            extractor = _fake_extractor(None)
            extractor.results_file = str(output)

            with patch.object(Processor, "get_processor", return_value=FakeProcessor()):
                extractor.process_file(str(report_path))

            self.assertEqual(extractor.failed_files, [str(report_path)])
            self.assertFalse(output.exists())

    def test_process_file_falls_back_to_manual_when_vectorised_task_has_no_response(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            report_path = root / "report.txt"
            report_path.write_text("test report", encoding="utf-8")
            output = root / "results.csv"
            extractor = _fake_extractor(FakeResponse())
            extractor.vectorise = True
            extractor.results_file = str(output)
            extractor.llm_handler = VectorFallbackHandler()

            with patch.object(Processor, "get_processor", return_value=FakeProcessor()):
                extractor.process_file(str(report_path))

            self.assertTrue(extractor.llm_handler.uploaded)
            self.assertEqual(extractor.failed_files, [])
            self.assertIn("report.txt;value", output.read_text(encoding="utf-8"))

    def test_cli_returns_nonzero_when_extraction_has_failed_files(self):
        class FailingExtractor:
            task_map = Extractor.task_map

            def __init__(self, vectorise, model):
                self.failed_files = []

            def process(self, path, results_file, tasks):
                self.failed_files.append(path)

        with patch("extract.Extractor", FailingExtractor):
            exit_code = main(["config", "--tasks", "company_data", "--path", "report.txt"])

        self.assertEqual(exit_code, 1)

    def test_help_command_does_not_require_config_tasks(self):
        stdout = StringIO()

        with redirect_stdout(stdout):
            exit_code = main(["help"])

        self.assertEqual(exit_code, 0)
        self.assertIn("General help", stdout.getvalue())

    def test_ar16_preflight_passes_for_current_project_mapping(self):
        report = ensure_ar16_reconciled()

        self.assertFalse(report.has_unmatched())
        self.assertEqual(report.invalid_equivalences, [])


def _fake_extractor(response):
    extractor = Extractor.__new__(Extractor)
    extractor.all_values = None
    extractor.all_headers = None
    extractor.results_file = "results.csv"
    extractor.tasks = [FakeTask()]
    extractor.vectorise = False
    extractor.model = "fake-model"
    extractor.failed_files = []
    extractor.llm_handler = FakeHandler(response)
    return extractor


class _temporary_cwd:
    def __init__(self, path):
        self.path = path
        self.previous = None

    def __enter__(self):
        import os

        self.previous = os.getcwd()
        os.chdir(self.path)

    def __exit__(self, exc_type, exc, tb):
        import os

        os.chdir(self.previous)


if __name__ == "__main__":
    unittest.main()
