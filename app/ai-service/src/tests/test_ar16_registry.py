import json
import textwrap
import tempfile
import unittest
from pathlib import Path

from registry.ar16 import build_reconciliation_report, default_paths, inventory_yaml_esrs_fields


class Ar16RegistryTest(unittest.TestCase):
    def test_reconciliation_reports_unmatched_keys_without_silent_rename(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            mapping_path = root / "ar16_to_python_esrs_mapping.json"
            company_csv_path = root / "company_esrs.csv"
            feature_path = root / "feature_description.py"
            yaml_dir = root / "extraction_models"
            yaml_dir.mkdir()

            mapping_path.write_text(
                json.dumps(
                    {
                        "candidate_topics": [
                            {
                                "ar16_topic_id": 3,
                                "web_esrs": "E1",
                                "web_label_en": "Energy",
                                "web_theme_en": "Climate change",
                                "web_subtheme_en": "Energy",
                                "web_subtopic_en": None,
                                "python_esrs_keys": ["esrs_e1_energy_use"],
                                "mapping_status": "approved",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            company_csv_path.write_text(
                "file;esrs_e1_energy_use;esrs_e1_unmapped_dataset\n",
                encoding="utf-8",
            )
            feature_path.write_text(
                textwrap.dedent(
                    """
                    class ESRS_E1:
                        esrs_e1_energy_use: str
                        esrs_e1_energy_use_context: str
                        esrs_e1_unmapped_feature: str
                    """
                ),
                encoding="utf-8",
            )
            (yaml_dir / "ESRS_E1.yaml").write_text(
                textwrap.dedent(
                    """
                    task:
                      name: ESRS E1
                      description: Climate Change
                      fields:
                        - name: Energy Use
                          description: Energy consumption
                          extractContext: true
                        - name: Unmapped YAML
                          description: Extra YAML-only topic
                    """
                ),
                encoding="utf-8",
            )

            report = build_reconciliation_report(
                mapping_path=mapping_path,
                company_esrs_csv_path=company_csv_path,
                feature_description_path=feature_path,
                yaml_models_dir=yaml_dir,
            )

        self.assertEqual(report.mapping_keys, ["esrs_e1_energy_use"])
        self.assertEqual(report.company_esrs_without_mapping, ["esrs_e1_unmapped_dataset"])
        self.assertEqual(report.feature_fields_without_mapping, ["esrs_e1_unmapped_feature"])
        self.assertEqual(report.yaml_fields_without_mapping, ["esrs_e1_unmapped_yaml"])
        self.assertTrue(report.has_unmatched())
        self.assertEqual(report.entries[0].ar16_topic_id, 3)

    def test_yaml_inventory_uses_active_esrs_tasks_and_ignores_auxiliary_fields(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            yaml_dir = Path(temp_dir)
            (yaml_dir / "company_data.yaml").write_text(
                textwrap.dedent(
                    """
                    task:
                      active: false
                      name: Company Data
                      description: Company metadata
                      isESR: false
                      fields:
                        - name: Annual Turnover
                          description: Revenue
                    """
                ),
                encoding="utf-8",
            )
            (yaml_dir / "ESRS_E1.yaml").write_text(
                textwrap.dedent(
                    """
                    task:
                      active: true
                      name: ESRS E1
                      description: Climate Change
                      summaryField: true
                      fields:
                        - name: Energy Use
                          description: Energy consumption
                          extractContext: true
                    """
                ),
                encoding="utf-8",
            )

            inventory = inventory_yaml_esrs_fields(yaml_dir)

        self.assertEqual(inventory.fields, ["esrs_e1_energy_use"])
        self.assertEqual(inventory.inactive_tasks, ["company_data"])
        self.assertEqual(inventory.ignored_context_fields, ["esrs_e1_energy_use_context"])
        self.assertEqual(inventory.ignored_summary_fields, ["esrs_e1_summary"])

    def test_explicit_equivalence_resolves_yaml_alias_without_hiding_raw_drift(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            mapping_path = root / "ar16_to_python_esrs_mapping.json"
            company_csv_path = root / "company_esrs.csv"
            feature_path = root / "feature_description.py"
            yaml_dir = root / "extraction_models"
            equivalence_path = root / "ar16_key_equivalences.json"
            yaml_dir.mkdir()

            mapping_path.write_text(
                json.dumps(
                    {
                        "candidate_topics": [
                            {
                                "ar16_topic_id": 1,
                                "web_esrs": "E1",
                                "web_label_en": "Adaptation to climate change",
                                "web_theme_en": "Climate change",
                                "web_subtheme_en": "Adaptation to climate change",
                                "web_subtopic_en": None,
                                "python_esrs_keys": ["esrs_e1_adaptation_to_climate_change"],
                                "mapping_status": "approved",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            company_csv_path.write_text(
                "file;esrs_e1_adaptation_to_climate_change\n",
                encoding="utf-8",
            )
            feature_path.write_text(
                textwrap.dedent(
                    """
                    class ESRS_E1:
                        esrs_e1_adaptation_to_climate_change: str
                    """
                ),
                encoding="utf-8",
            )
            (yaml_dir / "ESRS_E1.yaml").write_text(
                textwrap.dedent(
                    """
                    task:
                      name: ESRS E1
                      description: Climate Change
                      themes:
                        - name: Climate Change Adaptation
                          description: Adaptation measures
                    """
                ),
                encoding="utf-8",
            )
            equivalence_path.write_text(
                json.dumps(
                    {
                        "version": "test",
                        "equivalences": [
                            {
                                "source_surface": "yaml",
                                "source_key": "esrs_e1_climate_change_adaptation",
                                "target_key": "esrs_e1_adaptation_to_climate_change",
                                "status": "equivalent",
                                "basis": "Same AR16 E1 adaptation topic with different YAML naming.",
                            }
                        ],
                    }
                ),
                encoding="utf-8",
            )

            report = build_reconciliation_report(
                mapping_path=mapping_path,
                company_esrs_csv_path=company_csv_path,
                feature_description_path=feature_path,
                yaml_models_dir=yaml_dir,
                key_equivalences_path=equivalence_path,
            )

        self.assertEqual(
            report.yaml_fields_without_mapping,
            ["esrs_e1_climate_change_adaptation"],
        )
        self.assertEqual(
            report.mapping_without_yaml_fields,
            ["esrs_e1_adaptation_to_climate_change"],
        )
        self.assertEqual(report.pending_unmatched["yaml_fields_without_mapping"], [])
        self.assertEqual(report.pending_unmatched["mapping_without_yaml_fields"], [])
        self.assertEqual(len(report.resolved_equivalences), 1)
        self.assertFalse(report.has_unmatched())

    def test_default_project_equivalences_leave_no_pending_unmatched_keys(self):
        report = build_reconciliation_report(**default_paths())

        self.assertEqual(report.invalid_equivalences, [])
        self.assertFalse(report.has_unmatched(), report.pending_unmatched)


if __name__ == "__main__":
    unittest.main()
