import builtins
import importlib
import sys
import unittest
from unittest.mock import patch

from fastapi.testclient import TestClient

from services.datatypes import CompanyDataAndEsrs, JobStatus, Prediction


COMPANY_PAYLOAD = {
    "company_name": "Sample",
    "sector_list": ["Manufacturing"],
    "headquarters_country": "Spain",
    "num_subsidiaries_countries": 1,
    "employees_total": 150,
    "annual_turnover_million_euro": 6.0,
    "stock_listed": False,
    "reporting_currency": "EUR",
}


def forget_endpoint_modules():
    for module_name in [
        "services.endpoints",
        "services.service_retrain",
        "train_model",
        "train",
        "train.classifiers",
    ]:
        sys.modules.pop(module_name, None)


def import_fresh_endpoints():
    forget_endpoint_modules()
    return importlib.import_module("services.endpoints")


class NoStartThread:
    def __init__(self, target, args=(), daemon=None):
        self.target = target
        self.args = args
        self.daemon = daemon
        self.started = False

    def start(self):
        self.started = True


class EndpointsTest(unittest.TestCase):
    def tearDown(self):
        forget_endpoint_modules()

    def test_importing_app_does_not_require_training_extras(self):
        original_import = builtins.__import__

        def guarded_import(name, globals=None, locals=None, fromlist=(), level=0):
            if name.split(".")[0] in {"lightgbm", "xgboost", "optuna"}:
                raise ModuleNotFoundError(f"No module named '{name}'")
            return original_import(name, globals, locals, fromlist, level)

        with patch("builtins.__import__", side_effect=guarded_import):
            endpoints = import_fresh_endpoints()

        self.assertEqual(endpoints.app.title, "FastAPI")

    def test_predict_route_returns_prediction_with_mocked_model(self):
        endpoints = import_fresh_endpoints()
        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            with patch.object(
                endpoints,
                "predict_esrs",
                return_value=Prediction(esrs={"esrs_e1_energy_use": 1}),
            ):
                response = client.post("/predict", json=COMPANY_PAYLOAD)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"esrs": {"esrs_e1_energy_use": 1}})

    def test_predict_route_returns_specific_file_error(self):
        endpoints = import_fresh_endpoints()
        missing_model = FileNotFoundError(2, "No such file", "../data/esrs_classifier.pkl")

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            with patch.object(endpoints, "predict_esrs", side_effect=missing_model):
                response = client.post("/predict", json=COMPANY_PAYLOAD)

        self.assertEqual(response.status_code, 500)
        self.assertEqual(response.json()["detail"], "The file ../data/esrs_classifier.pkl was not found.")

    def test_retrain_pre_registers_job_before_worker_runs(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()
        payload = {**COMPANY_PAYLOAD, "esrs": {"esrs_e1_energy_use": 1}}

        with patch.object(endpoints.threading, "Thread", NoStartThread):
            with patch.object(endpoints, "validate_model_artifacts", return_value=None):
                client = TestClient(endpoints.app)
                response = client.post("/retrain", json=payload)
                job_id = response.json()["job_id"]
                status_response = client.get(f"/retrain-status/{job_id}")

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["status"], "started")
        self.assertNotEqual(status_response.json()["status"], "not found")
        self.assertIsNone(status_response.json()["error"])
        self.assertEqual(endpoints.job_store[job_id].status, JobStatus.Status.started)

    def test_long_job_records_success_and_failure(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()

        endpoints.job_store["ok"] = JobStatus(job_id="ok", status=JobStatus.Status.started)
        endpoints.long_job("ok", lambda: None)

        self.assertEqual(endpoints.job_store["ok"].status, JobStatus.Status.finished)
        self.assertIsNone(endpoints.job_store["ok"].error)

        endpoints.job_store["bad"] = JobStatus(job_id="bad", status=JobStatus.Status.started)

        def fail():
            raise EOFError("truncated pickle")

        endpoints.long_job("bad", fail)

        self.assertEqual(endpoints.job_store["bad"].status, JobStatus.Status.failed)
        self.assertEqual(endpoints.job_store["bad"].error, "The model file is corrupted or incomplete.")


class JobStatusTest(unittest.TestCase):
    def test_error_default_is_null(self):
        status = JobStatus(job_id="job-1", status=JobStatus.Status.started)

        self.assertIsNone(status.error)


if __name__ == "__main__":
    unittest.main()
