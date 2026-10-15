"""The evidence runner must count actual severities, not turn diagnostics into success."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/ixbrl/validate_with_arelle.py'
spec = importlib.util.spec_from_file_location('wp32_validation', SCRIPT)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ValidationEvidenceTest(unittest.TestCase):
    def test_preserves_full_validation_failure_and_severities(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'log.json'
            path.write_text(json.dumps({'log': [
                {'code': 'info', 'level': 'info', 'message': {'text': 'validated in 1.2 secs'}},
                {'code': 'message:missing', 'level': 'error', 'message': {'text': 'Missing synthetic disclosure'}},
                {'code': 'warning:sample', 'level': 'warning', 'message': {'text': 'Synthetic warning'}},
            ]}))
            result = runner.summarize(path, 3)
            self.assertEqual((result['exit_code'], result['error_count'], result['warning_count']), (3, 1, 1))
            self.assertTrue(result['completed'])
            self.assertEqual(result['first_errors'][0]['code'], 'message:missing')

    def test_empty_log_does_not_claim_completed_validation(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'log.json'
            path.write_text('{"log": []}')
            self.assertFalse(runner.summarize(path, 0)['completed'])

    def test_malformed_log_is_not_a_success(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'log.json'
            path.write_text('{')
            with self.assertRaises(json.JSONDecodeError):
                runner.summarize(path, 0)


if __name__ == '__main__':
    unittest.main()
