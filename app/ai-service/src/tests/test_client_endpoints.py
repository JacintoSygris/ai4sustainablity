import io
import importlib
import sys
import unittest
from unittest.mock import patch
import requests


class ClientEndpointsImportTest(unittest.TestCase):
    def tearDown(self):
        sys.modules.pop("client_endpoints", None)

    def test_importing_client_endpoints_does_not_post_predict_request(self):
        sys.modules.pop("client_endpoints", None)

        with patch("requests.post") as post:
            post.return_value.status_code = 200
            post.return_value.json.return_value = {}
            post.return_value.raise_for_status.return_value = None
            importlib.import_module("client_endpoints")

        self.assertEqual(post.call_count, 0)

    def test_sample_predict_uses_documented_local_service_port_by_default(self):
        client_endpoints = importlib.import_module("client_endpoints")

        url, payload = client_endpoints.sample_predict()

        self.assertEqual(url, "http://127.0.0.1:8001/predict")
        self.assertEqual(payload["company_name"], "ACCIONA, S.A.")

    def test_sample_predict_accepts_base_url_override(self):
        client_endpoints = importlib.import_module("client_endpoints")

        url, _ = client_endpoints.sample_predict(base_url="http://ai-service.test/")

        self.assertEqual(url, "http://ai-service.test/predict")

    def test_run_sample_predict_returns_failure_code_on_request_error(self):
        client_endpoints = importlib.import_module("client_endpoints")

        class StopEvent:
            def set(self):
                pass

        class SpinnerThread:
            def join(self, timeout=None):
                pass

        with patch.object(client_endpoints, "spinner", return_value=(StopEvent(), SpinnerThread())), \
            patch("requests.post", side_effect=requests.exceptions.ConnectionError("service down")), \
            patch("sys.stdout", new_callable=io.StringIO):
            result = client_endpoints.run_sample_predict()

        self.assertEqual(result, 1)

    def test_main_returns_failure_code_on_request_error(self):
        client_endpoints = importlib.import_module("client_endpoints")

        with patch.object(client_endpoints, "run_sample_predict", return_value=1) as run_sample_predict:
            result = client_endpoints.main(["--base-url", "http://ai-service.test"])

        self.assertEqual(result, 1)
        run_sample_predict.assert_called_once_with(base_url="http://ai-service.test")


if __name__ == "__main__":
    unittest.main()
