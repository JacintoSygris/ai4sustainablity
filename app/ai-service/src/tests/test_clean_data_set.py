import importlib
import os
import sys
import tempfile
import unittest
from unittest.mock import patch


class CleanDataSetImportTest(unittest.TestCase):
    def tearDown(self):
        sys.modules.pop("clean_data_set", None)

    def test_importing_clean_data_set_does_not_parse_args_or_read_keys(self):
        sys.modules.pop("clean_data_set", None)
        exited = False

        with patch.object(sys, "argv", ["clean_data_set.py"]):
            with patch("builtins.open") as open_file:
                try:
                    importlib.import_module("clean_data_set")
                except SystemExit:
                    exited = True

        self.assertFalse(exited)
        self.assertEqual(open_file.call_count, 0)

    def test_load_api_key_prefers_environment(self):
        module = importlib.import_module("clean_data_set")

        with patch.dict(os.environ, {"OPENAI_API_KEY": "env-key"}, clear=False):
            self.assertEqual(module.load_api_key("missing.properties"), "env-key")

    def test_load_api_key_accepts_ini_keys_section(self):
        module = importlib.import_module("clean_data_set")

        with tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False) as handle:
            handle.write("[keys]\nOPENAI_API_KEY=ini-key\n")
            path = handle.name

        try:
            with patch.dict(os.environ, {}, clear=True):
                self.assertEqual(module.load_api_key(path), "ini-key")
        finally:
            os.unlink(path)

    def test_load_api_key_accepts_flat_properties_file(self):
        module = importlib.import_module("clean_data_set")

        with tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False) as handle:
            handle.write("OPENAI_API_KEY=flat-key\n")
            path = handle.name

        try:
            with patch.dict(os.environ, {}, clear=True):
                self.assertEqual(module.load_api_key(path), "flat-key")
        finally:
            os.unlink(path)


if __name__ == "__main__":
    unittest.main()
