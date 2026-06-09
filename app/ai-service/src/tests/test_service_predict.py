import unittest
import warnings
import tempfile
import math
import pandas as pd
from pathlib import Path
from unittest.mock import patch

from services import service_predict
from services.datatypes import CompanyData


class ServicePredictTest(unittest.TestCase):
    def test_load_data_ignores_unknown_sector_labels_without_warning(self):
        company = CompanyData(
            company_name="Sample",
            sector_list=["Manufacturing", "Agriculture, forestry and fishing"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=150,
            annual_turnover_million_euro=6.0,
            stock_listed=False,
            reporting_currency="EUR",
        )

        with patch.object(service_predict.joblib, "load", return_value=["Manufacturing"]):
            with warnings.catch_warnings(record=True) as caught:
                warnings.simplefilter("always")
                dataframe = service_predict.load_data(company)

        warning_messages = [str(warning.message) for warning in caught]

        self.assertNotIn(
            "unknown class(es) ['Agriculture, forestry and fishing'] will be ignored",
            warning_messages,
        )
        self.assertEqual(dataframe.loc[0, "Manufacturing"], 1)

    def test_validate_model_artifacts_reports_missing_file(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            data_dir = Path(temp_dir)
            (data_dir / "sector_columns.pkl").write_text("sectors", encoding="utf-8")
            (data_dir / "esrs_columns.pkl").write_text("columns", encoding="utf-8")

            with self.assertRaises(FileNotFoundError) as raised:
                service_predict.validate_model_artifacts(data_dir=data_dir)

        self.assertEqual(raised.exception.filename, str(data_dir / "esrs_classifier.pkl"))

    def test_validate_model_artifacts_passes_when_all_files_exist(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            data_dir = Path(temp_dir)
            for artifact in ["sector_columns.pkl", "esrs_classifier.pkl", "esrs_columns.pkl"]:
                (data_dir / artifact).write_text("ok", encoding="utf-8")

            service_predict.validate_model_artifacts(data_dir=data_dir)

    def test_predict_includes_legacy_model_metadata(self):
        company = CompanyData(
            company_name="Sample",
            sector_list=["Manufacturing"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=150,
            annual_turnover_million_euro=6.0,
            stock_listed=False,
            reporting_currency="EUR",
        )

        class StubClassifier:
            def predict(self, dataframe):
                return [[1, 0]]

        def fake_joblib_load(path):
            if str(path).endswith("esrs_classifier.pkl"):
                return StubClassifier()
            if str(path).endswith("esrs_columns.pkl"):
                return ["esrs_e1_energy_use", "esrs_e2_pollution"]
            raise AssertionError(f"Unexpected joblib load path: {path}")

        with patch.object(service_predict, "load_data", return_value=pd.DataFrame([{}])):
            with patch.object(service_predict.joblib, "load", side_effect=fake_joblib_load):
                prediction = service_predict.predict_esrs(company)

        self.assertEqual(prediction.esrs, {"esrs_e1_energy_use": 1, "esrs_e2_pollution": 0})
        self.assertEqual(prediction.model_profile, "legacy_v0")
        self.assertEqual(prediction.model_key_count, 2)
        self.assertEqual(prediction.feature_metadata.missing_required_fields, [])
        self.assertEqual(prediction.evidence_refs, [])

    def test_resolve_model_profile_rejects_unknown_profile(self):
        with self.assertRaises(ValueError) as raised:
            service_predict.resolve_model_profile("unknown_profile")

        self.assertIn("Unknown model profile 'unknown_profile'", str(raised.exception))

    def test_new_format_gpt41_profile_is_runtime_enabled_and_gemini_stays_blocked(self):
        profile = service_predict.resolve_model_profile("new_format_732_v1_gpt41")

        self.assertEqual(profile.name, "new_format_732_v1_gpt41")
        self.assertEqual(profile.expected_key_count, 102)
        service_predict.ensure_profile_runtime_enabled(profile)

        materiality_profile = service_predict.resolve_model_profile(
            "new_format_732_v1_gpt41_materiality_gold_v4"
        )
        self.assertEqual(
            materiality_profile.name,
            "new_format_732_v1_gpt41_materiality_gold_v4",
        )
        self.assertEqual(materiality_profile.expected_key_count, 102)
        service_predict.ensure_profile_runtime_enabled(materiality_profile)

        profile = service_predict.resolve_model_profile("new_format_732_v1_gemini")
        with self.assertRaises(ValueError) as raised:
            service_predict.ensure_profile_runtime_enabled(profile)

        self.assertIn("not runtime-enabled", str(raised.exception))

    def test_new_format_runtime_filter_does_not_cap_candidate_count_by_default(self):
        esrs_columns = [
            "esrs_e1_summary",
            "esrs_e3_other_issues_related_to_esrs_e3",
            *[f"esrs_test_candidate_{index}" for index in range(20)],
        ]

        filtered_keys, metadata = service_predict.filter_new_format_runtime_predictions(
            esrs_columns=esrs_columns,
            raw_predictions=[1] * len(esrs_columns),
            scores=[0.99, 0.98, *[0.97] * 20],
            score_threshold=0.95,
        )

        self.assertEqual(len(filtered_keys), 20)
        self.assertEqual(set(filtered_keys), {f"esrs_test_candidate_{index}" for index in range(20)})
        self.assertEqual(metadata["raw_positive_key_count"], 22)
        self.assertEqual(metadata["threshold_positive_key_count"], 22)
        self.assertEqual(metadata["excluded_non_candidate_key_count"], 2)
        self.assertEqual(metadata["emitted_positive_key_count"], 20)
        self.assertEqual(metadata["new_format_score_threshold"], 0.95)

    def test_build_new_format_data_maps_small_sme_profile_to_pipeline_features(self):
        company = CompanyData(
            company_name="Small Agri SME",
            sector_list=["A"],
            subsidiaries_regions=["eu", "latin_america"],
            products_services=["A", "C"],
            headquarters_country="Spain",
            num_subsidiaries_countries=0,
            employees_total=10,
            annual_turnover_million_euro=1.0,
            stock_listed=False,
            reporting_currency="EUR",
        )
        profile = service_predict.resolve_model_profile("new_format_732_v1_gpt41")

        dataframe, metadata = service_predict.load_new_format_data(company, profile)

        self.assertEqual(dataframe.loc[0, "sector_A"], 1)
        self.assertEqual(dataframe.loc[0, "region_EU"], 1)
        self.assertEqual(dataframe.loc[0, "region_LATAM"], 1)
        self.assertEqual(dataframe.loc[0, "product_A"], 1)
        self.assertEqual(dataframe.loc[0, "product_C"], 1)
        self.assertEqual(dataframe.loc[0, "company_size"], "SMALL")
        self.assertEqual(dataframe.loc[0, "juridic_form"], "UNKNOWN")
        self.assertAlmostEqual(dataframe.loc[0, "annual_turnover_log"], math.log1p(1.0))
        self.assertEqual(metadata.derived_fields["company_size"], "SMALL")
        self.assertEqual(metadata.defaulted_fields["juridic_form"], "UNKNOWN")
        self.assertEqual(metadata.missing_required_fields, [])

    def test_build_new_format_data_uses_medium_size_for_400_employee_profile(self):
        company = CompanyData(
            company_name="Medium Manufacturer",
            sector_list=["C"],
            subsidiaries_regions=["EU"],
            products_services=["C"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=400,
            annual_turnover_million_euro=45.0,
            stock_listed=False,
            reporting_currency="EUR",
        )
        profile = service_predict.resolve_model_profile("new_format_732_v1_gpt41")

        dataframe, metadata = service_predict.load_new_format_data(company, profile)

        self.assertEqual(dataframe.loc[0, "company_size"], "MEDIUM")
        self.assertEqual(metadata.derived_fields["company_size"], "MEDIUM")

    def test_prediction_can_run_new_format_profile_with_runtime_candidate_filter(self):
        company = CompanyData(
            company_name="Shadow SME",
            model_profile="new_format_732_v1_gpt41",
            sector_list=["C"],
            subsidiaries_regions=["EU"],
            products_services=["C"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=150,
            annual_turnover_million_euro=6.0,
            stock_listed=False,
            reporting_currency="EUR",
        )

        prediction = service_predict.predict_esrs(company)

        self.assertEqual(prediction.model_profile, "new_format_732_v1_gpt41")
        self.assertEqual(prediction.model_key_count, 102)
        self.assertEqual(len(prediction.esrs), 102)
        self.assertIn("runtime_activation", prediction.mapping_metadata)
        self.assertEqual(
            prediction.mapping_metadata["runtime_activation"],
            "runtime_enabled",
        )
        self.assertGreaterEqual(
            prediction.mapping_metadata["emitted_positive_key_count"],
            1,
        )
        self.assertNotIn("new_format_max_positive_keys", prediction.mapping_metadata)
        self.assertNotIn("esrs_e1_summary", [
            key
            for key, value in prediction.esrs.items()
            if int(value) == 1
        ])


if __name__ == "__main__":
    unittest.main()
