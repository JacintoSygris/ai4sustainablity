import builtins
import csv
import importlib
import sys
import unittest
from pathlib import Path
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


def company_esrs_keys() -> list[str]:
    company_esrs_path = Path(__file__).resolve().parents[2] / "data" / "company_esrs.csv"
    with company_esrs_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter=";")
        header = next(reader)
    return [column for column in header if column != "file"]


def complete_esrs_payload(value: int = 0) -> dict[str, int]:
    return {key: value for key in company_esrs_keys()}


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
                return_value=Prediction(
                    esrs={"esrs_e1_energy_use": 1},
                    model_profile="legacy_v0",
                    model_key_count=1,
                ),
            ):
                response = client.post("/predict", json=COMPANY_PAYLOAD)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["esrs"], {"esrs_e1_energy_use": 1})
        self.assertEqual(response.json()["model_profile"], "legacy_v0")
        self.assertEqual(response.json()["model_key_count"], 1)

    def test_predict_route_returns_422_for_unknown_model_profile(self):
        endpoints = import_fresh_endpoints()
        payload = {**COMPANY_PAYLOAD, "model_profile": "unknown_profile"}

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/predict", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertIn("Unknown model profile 'unknown_profile'", response.json()["detail"])

    def test_predict_route_returns_422_for_inventoried_profile_that_is_not_runtime_enabled(self):
        endpoints = import_fresh_endpoints()
        payload = {**COMPANY_PAYLOAD, "model_profile": "new_format_732_v1_gemini"}

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/predict", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertIn("not runtime-enabled", response.json()["detail"])

    def test_model_profiles_route_exposes_active_and_inventoried_profiles(self):
        endpoints = import_fresh_endpoints()

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.get("/model-profiles")

        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertEqual(body["active_model_profile"], "legacy_v0")
        self.assertEqual(
            body["runtime_enabled_profiles"],
            [
                "legacy_v0",
                "new_format_732_v1_gpt41",
                "new_format_732_v1_gpt41_materiality_gold_v4",
            ],
        )
        self.assertEqual(body["profiles"]["legacy_v0"]["expected_key_count"], 96)
        self.assertTrue(body["profiles"]["legacy_v0"]["runtime_enabled"])
        self.assertEqual(body["profiles"]["new_format_732_v1_gpt41"]["expected_key_count"], 102)
        self.assertTrue(body["profiles"]["new_format_732_v1_gpt41"]["runtime_enabled"])
        materiality_profile = body["profiles"]["new_format_732_v1_gpt41_materiality_gold_v4"]
        self.assertEqual(materiality_profile["expected_key_count"], 102)
        self.assertTrue(materiality_profile["runtime_enabled"])
        self.assertFalse(body["profiles"]["new_format_732_v1_gemini"]["runtime_enabled"])

    def test_predict_route_returns_specific_file_error(self):
        endpoints = import_fresh_endpoints()
        missing_model = FileNotFoundError(2, "No such file", "../data/esrs_classifier.pkl")

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            with patch.object(endpoints, "predict_esrs", side_effect=missing_model):
                response = client.post("/predict", json=COMPANY_PAYLOAD)

        self.assertEqual(response.status_code, 500)
        self.assertEqual(response.json()["detail"], "The file ../data/esrs_classifier.pkl was not found.")

    def test_retrain_rejects_archived_legacy_trainer_before_job_creation(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()
        payload = {**COMPANY_PAYLOAD, "esrs": complete_esrs_payload()}

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/retrain", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertIn("legacy retrain trainer was archived", response.json()["detail"])
        self.assertNotIn("job_id", response.json())
        self.assertEqual(endpoints.job_store, {})

    def test_retrain_rejects_unknown_esrs_key_before_job_creation(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()
        payload = {**COMPANY_PAYLOAD, "esrs": {**complete_esrs_payload(), "esrs_unknown_topic": 1}}

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/retrain", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertEqual(endpoints.job_store, {})

    def test_retrain_rejects_missing_esrs_key_before_job_creation(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()
        esrs = complete_esrs_payload()
        esrs.pop(company_esrs_keys()[0])
        payload = {**COMPANY_PAYLOAD, "esrs": esrs}

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/retrain", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertEqual(endpoints.job_store, {})

    def test_retrain_rejects_non_binary_esrs_value_before_job_creation(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()
        esrs = complete_esrs_payload()
        esrs[company_esrs_keys()[0]] = 2
        payload = {**COMPANY_PAYLOAD, "esrs": esrs}

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/retrain", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertEqual(endpoints.job_store, {})

    def test_retrain_rejects_non_legacy_model_profile_before_job_creation(self):
        endpoints = import_fresh_endpoints()
        endpoints.job_store.clear()
        payload = {
            **COMPANY_PAYLOAD,
            "model_profile": "new_format_732_v1_gpt41",
            "esrs": complete_esrs_payload(),
        }

        with patch.object(endpoints, "validate_model_artifacts", return_value=None):
            client = TestClient(endpoints.app)
            response = client.post("/retrain", json=payload)

        self.assertEqual(response.status_code, 422)
        self.assertIn("/retrain only supports legacy_v0", response.json()["detail"])
        self.assertEqual(endpoints.job_store, {})

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
