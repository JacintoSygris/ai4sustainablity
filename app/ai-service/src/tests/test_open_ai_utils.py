import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch
import tempfile
from pathlib import Path

from utils.open_ai_utils import OpenAIHandler
from utils import utils as legacy_openai_utils


class AccountFiles:
    def __init__(self):
        self.deleted = []

    def list(self):
        return [SimpleNamespace(id="account-file-1", filename="other-project.pdf")]

    def delete(self, file_id):
        self.deleted.append(file_id)


class VectorStoreFiles:
    def __init__(self):
        self.deleted = []
        self._files = [SimpleNamespace(id="vector-file-1")]

    def list(self, vector_store_id):
        return list(self._files)

    def delete(self, file_id, vector_store_id):
        self.deleted.append((file_id, vector_store_id))
        self._files = [file for file in self._files if file.id != file_id]


class FakeClient:
    def __init__(self):
        self.files = AccountFiles()
        self.file_batches = UploadFileBatches()
        self.vector_stores = SimpleNamespace(
            files=VectorStoreFiles(),
            file_batches=self.file_batches,
        )


class UploadFileBatches:
    def __init__(self):
        self.uploaded_files = []

    def upload_and_poll(self, vector_store_id, files):
        self.uploaded_files = list(files)
        return SimpleNamespace()


class LegacyFiles(AccountFiles):
    def __init__(self):
        super().__init__()
        self.created_file = None

    def create(self, file, purpose):
        self.created_file = file
        return SimpleNamespace(id="uploaded-file-1")

    def retrieve(self, file_id):
        return SimpleNamespace(id=file_id)


class LegacyVectorStoreFiles(VectorStoreFiles):
    def create(self, vector_store_id, file_id):
        return SimpleNamespace(vector_store_id=vector_store_id, file_id=file_id)


class LegacyFakeClient:
    def __init__(self):
        self.files = LegacyFiles()
        self.vector_stores = SimpleNamespace(files=LegacyVectorStoreFiles())


class OpenAICleanupTest(unittest.TestCase):
    def test_handler_empty_store_does_not_delete_account_files(self):
        client = FakeClient()
        handler = OpenAIHandler.__new__(OpenAIHandler)
        handler.client = client
        handler.vector_store_id = "vs-1"

        handler._OpenAIHandler__empty_store()

        self.assertEqual(client.vector_stores.files.deleted, [("vector-file-1", "vs-1")])
        self.assertEqual(client.files.deleted, [])

    def test_legacy_empty_store_does_not_delete_account_files(self):
        client = FakeClient()

        with patch.object(legacy_openai_utils, "OpenAI", return_value=client):
            legacy_openai_utils.empty_store("test-key")

        self.assertEqual(client.files.deleted, [])

    def test_handler_upload_file_closes_file_handle(self):
        client = FakeClient()
        handler = OpenAIHandler.__new__(OpenAIHandler)
        handler.client = client
        handler.vector_store_id = "vs-1"

        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "report.pdf"
            path.write_bytes(b"pdf")

            handler.upload_file(str(path))

        self.assertTrue(client.file_batches.uploaded_files[0].closed)

    def test_legacy_upload_file_closes_file_handle(self):
        client = LegacyFakeClient()

        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "report.pdf"
            path.write_bytes(b"pdf")

            with patch.object(legacy_openai_utils, "OpenAI", return_value=client):
                legacy_openai_utils.upload_file("test-key", str(path), "vs-1")

        self.assertTrue(client.files.created_file.closed)

    def test_check_keys_prefers_environment_when_flat_keys_file_exists(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "keys.properties"
            path.write_text("OPENAI_API_KEY=flat-key\n", encoding="utf-8")

            with _temporary_cwd(temp_dir):
                with patch.dict(os.environ, {"OPENAI_API_KEY": "env-key"}, clear=True):
                    legacy_openai_utils.check_keys(["OPENAI_API_KEY"])
                    self.assertEqual(os.environ["OPENAI_API_KEY"], "env-key")

    def test_check_keys_loads_flat_keys_properties(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "keys.properties"
            path.write_text("OPENAI_API_KEY=flat-key\n", encoding="utf-8")

            with _temporary_cwd(temp_dir):
                with patch.dict(os.environ, {}, clear=True):
                    legacy_openai_utils.check_keys(["OPENAI_API_KEY"])
                    self.assertEqual(os.environ["OPENAI_API_KEY"], "flat-key")


class _temporary_cwd:
    def __init__(self, path):
        self.path = path
        self.previous = None

    def __enter__(self):
        self.previous = os.getcwd()
        os.chdir(self.path)

    def __exit__(self, exc_type, exc, tb):
        os.chdir(self.previous)


if __name__ == "__main__":
    unittest.main()
