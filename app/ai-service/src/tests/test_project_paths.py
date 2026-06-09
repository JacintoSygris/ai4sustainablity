import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from project_paths import resolve_web_data_file


class ProjectPathsTest(unittest.TestCase):
    def test_resolves_vps_sibling_app_current_data_from_ai_release(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            base = Path(temp_dir)
            ai_root = base / "ai-service" / "releases" / "20260608-000000"
            web_data = base / "app" / "current" / "data"
            web_data.mkdir(parents=True)
            expected = web_data / "mapping.json"
            expected.write_text("{}", encoding="utf-8")

            with patch.dict(os.environ, {}, clear=True):
                self.assertEqual(
                    resolve_web_data_file("mapping.json", ai_service_root=ai_root),
                    expected,
                )

    def test_env_web_data_dir_wins(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            web_data = Path(temp_dir) / "data"
            web_data.mkdir()
            expected = web_data / "mapping.json"
            expected.write_text("{}", encoding="utf-8")

            with patch.dict(os.environ, {"I4S_WEB_DATA_DIR": str(web_data)}, clear=True):
                self.assertEqual(resolve_web_data_file("mapping.json"), expected)


if __name__ == "__main__":
    unittest.main()
