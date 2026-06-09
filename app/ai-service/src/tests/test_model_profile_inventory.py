import json
import unittest
from pathlib import Path

from project_paths import resolve_web_data_file
from services import service_predict


class ModelProfileInventoryTest(unittest.TestCase):
    def test_new_format_mapping_inventory_matches_both_732_profiles(self):
        mapping_path = resolve_web_data_file("ar16_to_python_esrs_mapping_new_format_732_v1.json")

        with mapping_path.open("r", encoding="utf-8") as handle:
            mapping = json.load(handle)

        gpt41_profile = service_predict.resolve_model_profile("new_format_732_v1_gpt41")
        materiality_profile = service_predict.resolve_model_profile(
            "new_format_732_v1_gpt41_materiality_gold_v4"
        )
        gemini_profile = service_predict.resolve_model_profile("new_format_732_v1_gemini")
        gpt41_keys = service_predict.load_profile_esrs_columns(gpt41_profile)
        materiality_keys = service_predict.load_profile_esrs_columns(materiality_profile)
        gemini_keys = service_predict.load_profile_esrs_columns(gemini_profile)
        inventory_keys = [row["python_esrs_key"] for row in mapping["keys"]]

        self.assertEqual(gpt41_keys, gemini_keys)
        self.assertEqual(gpt41_keys, materiality_keys)
        self.assertEqual(inventory_keys, gpt41_keys)
        self.assertIn("new_format_732_v1_gpt41_materiality_gold_v4", mapping["model_profiles"])
        self.assertEqual(mapping["schema_version"], "1.0")
        self.assertEqual(mapping["mapping_version"], "new_format_732_v1")
        self.assertEqual(mapping["status"], "runtime-approved-for-candidate-suggestions")
        self.assertEqual(mapping["model_key_count"], 102)
        self.assertEqual(mapping["approved_key_count"], 91)
        self.assertEqual(mapping["approved_by"], "local-ar16-equivalence-crosswalk")
        self.assertEqual(mapping["approved_at"], "2026-06-08")
        self.assertEqual(len(mapping["keys"]), 102)
        self.assertEqual(len(set(inventory_keys)), 102)

        allowed_statuses = {"approved", "aggregate_only", "review_only", "excluded"}
        for row in mapping["keys"]:
            self.assertIn(row["status"], allowed_statuses)
            self.assertIsInstance(row["ar16_topic_ids"], list)

        rows_by_key = {row["python_esrs_key"]: row for row in mapping["keys"]}
        self.assertEqual(rows_by_key["esrs_e1_climate_change_adaptation"]["status"], "approved")
        self.assertEqual(rows_by_key["esrs_e1_climate_change_adaptation"]["ar16_topic_ids"], [1])
        self.assertEqual(rows_by_key["esrs_e1_climate_change_mitigation"]["ar16_topic_ids"], [2])
        self.assertEqual(rows_by_key["esrs_e1_energy_use"]["ar16_topic_ids"], [3])
        self.assertEqual(rows_by_key["esrs_e2_air_pollution"]["ar16_topic_ids"], [4])
        self.assertEqual(rows_by_key["esrs_s4_social_inclusion_access_products"]["ar16_topic_ids"], [81])
        self.assertEqual(rows_by_key["esrs_e1_summary"]["status"], "aggregate_only")
        self.assertEqual(rows_by_key["esrs_e1_summary"]["ar16_topic_ids"], [])
        self.assertEqual(rows_by_key["esrs_e3_other_issues_related_to_esrs_e3"]["status"], "review_only")
        self.assertEqual(rows_by_key["esrs_e3_other_issues_related_to_esrs_e3"]["ar16_topic_ids"], [])


if __name__ == "__main__":
    unittest.main()
