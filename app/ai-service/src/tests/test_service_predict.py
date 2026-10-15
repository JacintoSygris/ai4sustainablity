import unittest
import warnings
import tempfile
import math
import hashlib
import os
import pandas as pd
from pathlib import Path
from unittest.mock import patch

from services import service_predict
from services.datatypes import CompanyData


class ServicePredictTest(unittest.TestCase):
    """Public runtime: single new_format_732_v1_gpt41 profile, no CRC/sector guard."""
    def setUp(self):
        clear_cache = getattr(service_predict, "clear_model_artifact_cache", None)
        if clear_cache is not None:
            clear_cache()

    def tearDown(self):
        clear_cache = getattr(service_predict, "clear_model_artifact_cache", None)
        if clear_cache is not None:
            clear_cache()

    def test_profile_artifacts_are_cached_once_per_process(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            artifact_dir = Path(temp_dir)
            (artifact_dir / "esrs_classifier.pkl").write_bytes(b"classifier")
            (artifact_dir / "esrs_columns.pkl").write_bytes(b"columns")
            profile = service_predict.ModelProfile(
                name="cache-test",
                artifact_dir=artifact_dir,
                expected_key_count=1,
                required_artifacts=("esrs_classifier.pkl", "esrs_columns.pkl"),
                runtime_enabled=True,
                description="cache test",
            )
            classifier = object()

            service_predict.clear_model_artifact_cache()
            with patch.object(
                service_predict.joblib,
                "load",
                side_effect=[classifier, ["esrs_e1_energy_use"]],
            ) as load:
                self.assertIs(service_predict.load_profile_classifier(profile), classifier)
                self.assertIs(service_predict.load_profile_classifier(profile), classifier)
                self.assertEqual(
                    service_predict.load_profile_esrs_columns(profile),
                    ["esrs_e1_energy_use"],
                )
                self.assertEqual(
                    service_predict.load_profile_esrs_columns(profile),
                    ["esrs_e1_energy_use"],
                )

        self.assertEqual(load.call_count, 2)

    def test_serving_identity_hashes_every_model_artifact_and_reports_inactive_policies(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            artifact_dir = Path(temp_dir)
            required = ("sector_columns.pkl", "region_columns.pkl", "esrs_classifier.pkl", "esrs_columns.pkl")
            for filename in required:
                (artifact_dir / filename).write_bytes(("bytes:" + filename).encode())
            profile = service_predict.ModelProfile(
                name="new_format_732_v1_identity_test", artifact_dir=artifact_dir, expected_key_count=1,
                required_artifacts=required, runtime_enabled=True, description="fixture")
            # Recall-floor and sector-guard variables are not applied by the public runtime.
            with patch.dict(os.environ, {"ESRS_CRC_RECALL_FLOOR_PATH": str(artifact_dir / "crc.json"),
                                         "I4S_AI_NEW_FORMAT_SCORE_THRESHOLD": "0.91"}, clear=True):
                service_predict.clear_model_artifact_cache()
                identity = service_predict.model_serving_identity(profile)
                self.assertEqual(set(identity["artifact_sha256"]), set(required))
                self.assertEqual(identity["policy_sha256"], {})
                self.assertEqual(identity["runtime_config"], {"score_threshold": 0.91, "policy_active": {
                    "label_thresholds": False, "crc_recall_floor": False, "sector_guard": False}})
                self.assertEqual(len(identity["serving_identity_sha256"]), 64)
                self.assertNotIn(str(artifact_dir), str(identity))
                original = identity["serving_identity_sha256"]
                (artifact_dir / "esrs_classifier.pkl").write_bytes(b"changed-model")
                service_predict.clear_model_artifact_cache()
                self.assertNotEqual(service_predict.model_serving_identity(profile)["serving_identity_sha256"], original)

    def test_validate_model_artifacts_reports_missing_public_artifact(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            with patch.dict(os.environ, {"I4S_AI_ARTIFACT_DIR": temp_dir}):
                profile = service_predict.ModelProfile(
                    name="new_format_732_v1_gpt41", artifact_dir=service_predict.resolve_artifact_dir(),
                    expected_key_count=102, required_artifacts=("sector_columns.pkl",), runtime_enabled=True,
                    description="fixture")
                with patch.object(service_predict, "resolve_model_profile", return_value=profile):
                    with self.assertRaises(FileNotFoundError) as raised:
                        service_predict.validate_model_artifacts("new_format_732_v1_gpt41")
        self.assertTrue(raised.exception.filename.endswith("sector_columns.pkl"))

    def test_predict_resolves_score_threshold_once_for_identity_and_filter(self):
        profile = service_predict.ModelProfile(
            name="new_format_732_v1_threshold_test",
            artifact_dir=Path("unused"),
            expected_key_count=1,
            required_artifacts=(),
            runtime_enabled=True,
            description="fixture",
        )
        company = CompanyData(
            model_profile=profile.name,
            company_name="Sample",
            sector_list=["C"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=150,
            annual_turnover_million_euro=6.0,
            stock_listed=False,
            reporting_currency="EUR",
        )

        class StubClassifier:
            def predict(self, _dataframe):
                return [[1]]

        with patch.dict(os.environ, {}, clear=True), \
                patch.object(service_predict, "resolve_model_profile", return_value=profile), \
                patch.object(service_predict, "load_profile_classifier", return_value=StubClassifier()), \
                patch.object(
                    service_predict,
                    "load_new_format_data",
                    return_value=(pd.DataFrame([{}]), service_predict.FeatureMetadata()),
                ), \
                patch.object(service_predict, "load_profile_esrs_columns", return_value=["esrs_e1_energy"]), \
                patch.object(service_predict, "new_format_positive_scores", return_value=[0.90]), \
                patch.object(
                    service_predict,
                    "new_format_score_threshold",
                    side_effect=[0.81, 0.22],
                ) as threshold:
            prediction = service_predict.predict_esrs(company)

        serving_threshold = prediction.mapping_metadata["serving_identity"]["runtime_config"]["score_threshold"]
        self.assertEqual(threshold.call_count, 1)
        self.assertEqual(serving_threshold, 0.81)
        self.assertEqual(prediction.mapping_metadata["new_format_score_threshold"], serving_threshold)

    def test_prediction_identity_uses_bytes_deserialized_into_runtime_object(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            artifact_dir = Path(temp_dir)
            classifier_path = artifact_dir / "esrs_classifier.pkl"
            original_bytes = b"classifier-A"
            classifier_path.write_bytes(original_bytes)
            profile = service_predict.ModelProfile(
                name="identity-race-test",
                artifact_dir=artifact_dir,
                expected_key_count=0,
                required_artifacts=("esrs_classifier.pkl",),
                runtime_enabled=True,
                description="fixture",
            )

            def deserialize_then_replace(_source):
                classifier_path.write_bytes(b"classifier-B")
                return object()

            service_predict.clear_model_artifact_cache()
            with patch.object(service_predict.joblib, "load", side_effect=deserialize_then_replace):
                service_predict.load_profile_classifier(profile)
                identity = service_predict.model_serving_identity(profile)

            self.assertEqual(
                identity["artifact_sha256"]["esrs_classifier.pkl"],
                hashlib.sha256(original_bytes).hexdigest(),
            )

    def test_resolve_model_profile_rejects_unknown_profile(self):
        with self.assertRaises(ValueError) as raised:
            service_predict.resolve_model_profile("unknown_profile")

        self.assertIn("Unsupported model profile 'unknown_profile'", str(raised.exception))

    def test_normalize_sector_codes_accepts_nace_divisions_and_legacy_sections(self):
        normalized = service_predict.normalize_sector_codes(
            ["64", " 65 ", "K", "financial services", "064", "6A", "Real Estate"]
        )

        self.assertEqual(normalized, ["64", "65", "K", "L", "M"])

    def test_normalize_sector_codes_accepts_nace_groups_and_decimal_groups(self):
        normalized = service_predict.normalize_sector_codes(
            ["64.1 central banking", "641", " 62.0 ", "620 software", "K"]
        )

        self.assertEqual(normalized, ["641", "620", "K"])

    def test_new_format_runtime_filter_applies_per_label_thresholds(self):
        esrs_columns = ["esrs_rare_topic", "esrs_common_topic"]

        filtered_keys, metadata = service_predict.filter_new_format_runtime_predictions(
            esrs_columns=esrs_columns,
            raw_predictions=[1, 1],
            scores=[0.40, 0.40],
            score_threshold=0.95,
            label_thresholds={"esrs_rare_topic": 0.30},
        )

        # rare topic passes its per-label threshold; common topic stays gated
        # by the global default because it has no per-label entry
        self.assertEqual(filtered_keys, ["esrs_rare_topic"])
        self.assertEqual(metadata["per_label_threshold_count"], 1)
        self.assertEqual(metadata["threshold_positive_key_count"], 1)

    def test_new_format_positive_scores_handles_single_class_chain_estimators(self):
        import numpy as np
        from sklearn.ensemble import RandomForestClassifier
        from sklearn.multioutput import ClassifierChain
        from sklearn.pipeline import Pipeline
        from sklearn.preprocessing import FunctionTransformer

        dataframe = pd.DataFrame({"feature": [0, 1, 2, 3]})
        labels = np.array([
            [0, 0],
            [1, 0],
            [0, 0],
            [1, 0],
        ])
        classifier = Pipeline([
            ("identity", FunctionTransformer(validate=False)),
            ("classifier", ClassifierChain(RandomForestClassifier(n_estimators=5, random_state=0))),
        ])
        classifier.fit(dataframe, labels)

        with self.assertRaises(ValueError):
            classifier.predict_proba(dataframe.iloc[[0]])

        scores = service_predict.new_format_positive_scores(classifier, dataframe.iloc[[0]], 2)

        self.assertEqual(len(scores), 2)
        self.assertEqual(scores[1], 0.0)

    def test_sector_guard_none_leaves_emitted_set_unchanged(self):
        # Default OFF (sector_guard=None) must not change behavior or drop any over-reach key.
        esrs_columns = ["esrs_e2_pollution_air", "esrs_e1_climate_mitigation"]
        filtered_keys, metadata = service_predict.filter_new_format_runtime_predictions(
            esrs_columns=esrs_columns,
            raw_predictions=[1, 1],
            scores=[0.99, 0.99],
            score_threshold=0.95,
            sector_guard=None,
            company_sectors=["L"],
        )
        self.assertEqual(set(filtered_keys), {"esrs_e2_pollution_air", "esrs_e1_climate_mitigation"})
        self.assertFalse(metadata["sector_guard_active"])
        self.assertEqual(metadata["sector_guard_suppressed_count"], 0)

    def test_crc_floor_none_leaves_emitted_set_and_metadata_off(self):
        # Default OFF (crc_floor=None) must not change behavior.
        esrs_columns = ["esrs_e1_climate_mitigation", "esrs_e3_water_consumption"]
        filtered_keys, metadata = service_predict.filter_new_format_runtime_predictions(
            esrs_columns=esrs_columns,
            raw_predictions=[1, 1],
            scores=[0.99, 0.30],
            score_threshold=0.95,
            crc_floor=None,
        )
        self.assertEqual(filtered_keys, ["esrs_e1_climate_mitigation"])
        self.assertFalse(metadata["crc_recall_floor_active"])
        self.assertEqual(metadata["crc_added_key_count"], 0)

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

    def test_build_new_format_data_sets_nace2_sector_and_product_features(self):
        company = CompanyData(
            company_name="NACE2 Bank",
            sector_list=["64"],
            subsidiaries_regions=["EU"],
            products_services=["64", "65"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=400,
            annual_turnover_million_euro=45.0,
            stock_listed=False,
            reporting_currency="EUR",
        )
        profile = service_predict.ModelProfile(
            name="new_format_732_v1_test_nace2",
            artifact_dir=Path("unused"),
            expected_key_count=102,
            required_artifacts=(),
            runtime_enabled=False,
            description="test",
        )
        feature_columns = [
            "sector_64",
            "sector_K",
            "product_64",
            "product_65",
            "region_EU",
            "headquarters_country",
            "annual_turnover_log",
            "company_size",
            "juridic_form",
            "stock_listed_flag",
            "reporting_currency",
        ]

        with patch.object(service_predict, "load_profile_feature_columns", return_value=feature_columns):
            dataframe, metadata = service_predict.load_new_format_data(company, profile, classifier=object())

        self.assertEqual(dataframe.loc[0, "sector_64"], 1)
        self.assertEqual(dataframe.loc[0, "sector_K"], 1)
        self.assertEqual(dataframe.loc[0, "product_64"], 1)
        self.assertEqual(dataframe.loc[0, "product_65"], 1)
        self.assertEqual(dataframe.loc[0, "region_EU"], 1)
        self.assertEqual(metadata.missing_required_fields, [])

    def test_build_new_format_data_uses_nace3_then_nace2_then_section_hierarchy(self):
        company = CompanyData(
            company_name="NACE3 Bank",
            sector_list=["641"],
            subsidiaries_regions=["EU"],
            products_services=["64.1 banking"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=400,
            annual_turnover_million_euro=45.0,
            stock_listed=False,
            reporting_currency="EUR",
        )
        profile = service_predict.ModelProfile(
            name="new_format_732_v1_test_nace3_hierarchy",
            artifact_dir=Path("unused"),
            expected_key_count=102,
            required_artifacts=(),
            runtime_enabled=False,
            description="test",
        )
        feature_columns = [
            "sector_641",
            "sector_64",
            "sector_K",
            "product_641",
            "product_64",
            "product_K",
            "region_EU",
            "headquarters_country",
            "annual_turnover_log",
            "company_size",
            "juridic_form",
            "stock_listed_flag",
            "reporting_currency",
        ]

        with patch.object(service_predict, "load_profile_feature_columns", return_value=feature_columns):
            dataframe, metadata = service_predict.load_new_format_data(company, profile, classifier=object())

        self.assertEqual(dataframe.loc[0, "sector_641"], 1)
        self.assertEqual(dataframe.loc[0, "sector_64"], 1)
        self.assertEqual(dataframe.loc[0, "sector_K"], 1)
        self.assertEqual(dataframe.loc[0, "product_641"], 1)
        self.assertEqual(dataframe.loc[0, "product_64"], 1)
        self.assertEqual(dataframe.loc[0, "product_K"], 1)
        self.assertEqual(
            metadata.derived_fields["industry_basis"],
            {
                "sector": {
                    "input_codes": ["641"],
                    "feature_codes": ["641", "64", "K"],
                    "effective_nace_code": "641",
                    "effective_nace_level": "group_3digit",
                    "fallback_used": False,
                },
                "products_services": {
                    "input_codes": ["641"],
                    "feature_codes": ["641", "64", "K"],
                    "effective_nace_code": "641",
                    "effective_nace_level": "group_3digit",
                    "fallback_used": False,
                },
            },
        )
        self.assertEqual(metadata.missing_required_fields, [])

    def test_build_new_format_data_falls_back_to_nace2_when_group_feature_is_missing(self):
        company = CompanyData(
            company_name="NACE2 Fallback Bank",
            sector_list=["641"],
            subsidiaries_regions=["EU"],
            products_services=["641"],
            headquarters_country="Spain",
            num_subsidiaries_countries=1,
            employees_total=400,
            annual_turnover_million_euro=45.0,
            stock_listed=False,
            reporting_currency="EUR",
        )
        profile = service_predict.ModelProfile(
            name="new_format_732_v1_test_nace3_fallback",
            artifact_dir=Path("unused"),
            expected_key_count=102,
            required_artifacts=(),
            runtime_enabled=False,
            description="test",
        )
        feature_columns = [
            "sector_64",
            "sector_K",
            "product_64",
            "product_K",
            "region_EU",
            "headquarters_country",
            "annual_turnover_log",
            "company_size",
            "juridic_form",
            "stock_listed_flag",
            "reporting_currency",
        ]

        with patch.object(service_predict, "load_profile_feature_columns", return_value=feature_columns):
            dataframe, metadata = service_predict.load_new_format_data(company, profile, classifier=object())

        self.assertEqual(dataframe.loc[0, "sector_64"], 1)
        self.assertEqual(dataframe.loc[0, "sector_K"], 1)
        self.assertEqual(dataframe.loc[0, "product_64"], 1)
        self.assertEqual(dataframe.loc[0, "product_K"], 1)
        self.assertEqual(metadata.derived_fields["industry_basis"]["sector"]["effective_nace_code"], "64")
        self.assertEqual(metadata.derived_fields["industry_basis"]["sector"]["effective_nace_level"], "division_2digit")
        self.assertTrue(metadata.derived_fields["industry_basis"]["sector"]["fallback_used"])

    def test_build_new_format_data_uses_large_size_for_400_employee_profile(self):
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

        self.assertEqual(dataframe.loc[0, "company_size"], "LARGE")
        self.assertEqual(metadata.derived_fields["company_size"], "LARGE")

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
