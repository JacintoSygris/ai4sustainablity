import unittest
import tempfile
import json
from io import StringIO
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

from services.datatypes import Prediction


class ShadowModelProfilesTest(unittest.TestCase):
    def test_build_sme_shadow_cases_covers_requested_company_sizes(self):
        from shadow_model_profiles import build_sme_shadow_cases

        cases = build_sme_shadow_cases()

        self.assertEqual([case.name for case in cases], [
            "small_10_agri",
            "small_150_manufacturing",
            "medium_400_services",
        ])
        self.assertEqual([case.company.employees_total for case in cases], [10, 150, 400])
        self.assertEqual([case.company.annual_turnover_million_euro for case in cases], [1.0, 6.0, 45.0])

    def test_build_heterogeneous_shadow_cases_covers_broad_morphologies_without_large_multinationals(self):
        from shadow_model_profiles import build_heterogeneous_shadow_cases

        cases = build_heterogeneous_shadow_cases()
        sectors = {sector for case in cases for sector in case.company.sector_list}
        employee_counts = [case.company.employees_total for case in cases]

        self.assertGreaterEqual(len(cases), 15)
        self.assertIn(3, employee_counts)
        self.assertIn(10, employee_counts)
        self.assertIn(150, employee_counts)
        self.assertIn(400, employee_counts)
        self.assertLessEqual(max(employee_counts), 499)
        self.assertTrue({"A", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "P", "R"}.issubset(sectors))
        self.assertTrue(any(case.company.stock_listed for case in cases))
        self.assertTrue(any(case.company.num_subsidiaries_countries == 0 for case in cases))
        self.assertTrue(any(case.company.num_subsidiaries_countries > 1 for case in cases))

    def test_build_shadow_cases_rejects_unknown_case_set(self):
        from shadow_model_profiles import build_shadow_cases

        with self.assertRaises(ValueError):
            build_shadow_cases("unknown")

    def test_run_shadow_matrix_uses_shadow_mode_for_new_format_profiles(self):
        from shadow_model_profiles import build_sme_shadow_cases, run_shadow_matrix

        calls = []

        def fake_predict(company, allow_inventoried_profile=False):
            calls.append((company.model_profile, allow_inventoried_profile, company.employees_total))
            return Prediction(
                esrs={"esrs_e1_summary": 1, "esrs_e2_summary": 0},
                model_profile=company.model_profile,
                model_key_count=2,
                mapping_metadata={
                    "runtime_activation": "shadow_only_profile_not_endpoint_enabled"
                    if allow_inventoried_profile
                    else "runtime_enabled",
                },
            )

        with patch("shadow_model_profiles.service_predict.predict_esrs", side_effect=fake_predict):
            result = run_shadow_matrix(
                cases=build_sme_shadow_cases(),
                model_profiles=["legacy_v0", "new_format_732_v1_gpt41"],
            )

        self.assertEqual(len(result["cases"]), 3)
        self.assertIn("legacy_v0", result["cases"][0]["runs"])
        self.assertIn("new_format_732_v1_gpt41", result["cases"][0]["runs"])
        self.assertEqual(result["cases"][0]["runs"]["new_format_732_v1_gpt41"]["positive_key_count"], 1)
        self.assertIn(("legacy_v0", False, 10), calls)
        self.assertIn(("new_format_732_v1_gpt41", True, 10), calls)

    def test_projects_new_format_predictions_through_mapping_inventory(self):
        from shadow_model_profiles import project_new_format_mapping

        projection = project_new_format_mapping(
            positive_keys=[
                "esrs_e1_climate_change_adaptation",
                "esrs_e1_summary",
                "esrs_e3_other_issues_related_to_esrs_e3",
                "esrs_unknown",
            ],
            mapping_inventory={
                "keys": [
                    {
                        "python_esrs_key": "esrs_e1_climate_change_adaptation",
                        "status": "approved",
                        "ar16_topic_ids": [1],
                    },
                    {
                        "python_esrs_key": "esrs_e1_summary",
                        "status": "aggregate_only",
                        "ar16_topic_ids": [],
                    },
                    {
                        "python_esrs_key": "esrs_e3_other_issues_related_to_esrs_e3",
                        "status": "review_only",
                        "ar16_topic_ids": [],
                    },
                ],
            },
        )

        self.assertEqual(projection["candidate_topic_count"], 1)
        self.assertEqual(projection["candidate_topic_ids"], [1])
        self.assertEqual(projection["approved_positive_key_count"], 1)
        self.assertEqual(projection["aggregate_positive_keys"], ["esrs_e1_summary"])
        self.assertEqual(projection["review_required_keys"], [
            "esrs_e3_other_issues_related_to_esrs_e3",
            "esrs_unknown",
        ])

    def test_run_shadow_matrix_can_include_new_format_mapping_projection(self):
        from shadow_model_profiles import build_sme_shadow_cases, run_shadow_matrix

        def fake_predict(company, allow_inventoried_profile=False):
            return Prediction(
                esrs={
                    "esrs_e1_climate_change_adaptation": 1,
                    "esrs_e1_summary": 1,
                },
                model_profile=company.model_profile,
                model_key_count=2,
            )

        with tempfile.TemporaryDirectory() as temp_dir:
            mapping_path = Path(temp_dir) / "mapping.json"
            mapping_path.write_text(
                json.dumps(
                    {
                        "keys": [
                            {
                                "python_esrs_key": "esrs_e1_climate_change_adaptation",
                                "status": "approved",
                                "ar16_topic_ids": [1],
                            },
                            {
                                "python_esrs_key": "esrs_e1_summary",
                                "status": "aggregate_only",
                                "ar16_topic_ids": [],
                            },
                        ],
                    }
                ),
                encoding="utf-8",
            )

            with patch("shadow_model_profiles.service_predict.predict_esrs", side_effect=fake_predict):
                result = run_shadow_matrix(
                    cases=build_sme_shadow_cases()[:1],
                    model_profiles=["legacy_v0", "new_format_732_v1_gpt41"],
                    mapping_path=mapping_path,
                )

        legacy_run = result["cases"][0]["runs"]["legacy_v0"]
        new_format_run = result["cases"][0]["runs"]["new_format_732_v1_gpt41"]
        self.assertNotIn("mapping_projection", legacy_run)
        self.assertEqual(new_format_run["mapping_projection"]["candidate_topic_count"], 1)
        self.assertEqual(new_format_run["mapping_projection"]["aggregate_positive_keys"], ["esrs_e1_summary"])

    def test_run_shadow_matrix_keeps_prediction_progress_logs_off_stdout(self):
        from shadow_model_profiles import build_sme_shadow_cases, run_shadow_matrix

        def fake_predict(company, allow_inventoried_profile=False):
            print("Start prediction")
            return Prediction(
                esrs={"esrs_e1_summary": 1},
                model_profile=company.model_profile,
                model_key_count=1,
            )

        with patch("shadow_model_profiles.service_predict.predict_esrs", side_effect=fake_predict):
            with redirect_stdout(StringIO()) as stdout:
                run_shadow_matrix(
                    cases=build_sme_shadow_cases()[:1],
                    model_profiles=["legacy_v0"],
                )

        self.assertEqual(stdout.getvalue(), "")

    def test_main_reports_missing_runtime_dependency_with_operator_hint(self):
        import shadow_model_profiles

        with patch.object(shadow_model_profiles, "run_shadow_matrix", side_effect=ModuleNotFoundError("lightgbm")):
            with patch("sys.argv", ["shadow_model_profiles.py"]):
                with patch("sys.stderr", new=StringIO()) as stderr:
                    exit_code = shadow_model_profiles.main()

        self.assertEqual(exit_code, 1)
        self.assertIn("shadow_failed=true", stderr.getvalue())
        self.assertIn("missing_python_module=lightgbm", stderr.getvalue())
        self.assertIn(".venv", stderr.getvalue())


if __name__ == "__main__":
    unittest.main()
