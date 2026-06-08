import unittest
import warnings
import tempfile
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


if __name__ == "__main__":
    unittest.main()
