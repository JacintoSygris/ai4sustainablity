import tempfile
import unittest
from pathlib import Path

from services.datatypes import CompanyDataAndEsrs
from services import service_retrain


COMPANY = CompanyDataAndEsrs(
    company_name="NewCo",
    sector_list=["Manufacturing", "Services"],
    headquarters_country="Spain",
    num_subsidiaries_countries=2,
    employees_total=200,
    annual_turnover_million_euro=10.0,
    stock_listed=False,
    reporting_currency="EUR",
    esrs={"esrs_e1_energy_use": 1},
)


def write_fixture_data(data_dir: Path):
    data_dir.mkdir(parents=True, exist_ok=True)
    (data_dir / "company_data.csv").write_text(
        "file;company_name;sector;headquarters_country;num_subsidiaries_countries;"
        "employees_total;annual_turnover_million_euro;stock_listed;reporting_currency\n"
        "OldCo;OldCo;Manufacturing;Spain;1;100;5.0;False;EUR\n",
        encoding="utf-8",
    )
    (data_dir / "company_esrs.csv").write_text(
        "file;esrs_e1_energy_use\n"
        "OldCo;0\n",
        encoding="utf-8",
    )
    for artifact in ["esrs_classifier.pkl", "esrs_columns.pkl", "sector_columns.pkl"]:
        (data_dir / artifact).write_text("old", encoding="utf-8")


class FailingTrainModule:
    def load_data(self, company_data_path, company_esrs_path):
        return {"company_data_path": company_data_path, "company_esrs_path": company_esrs_path}

    def train_model(self, *args, **kwargs):
        raise RuntimeError("training failed")


class SuccessfulTrainModule:
    def load_data(self, company_data_path, company_esrs_path):
        return {"company_data_path": company_data_path, "company_esrs_path": company_esrs_path}

    def train_model(self, df, output_path, *args, **kwargs):
        output_dir = Path(output_path)
        for artifact in ["esrs_classifier.pkl", "esrs_columns.pkl", "sector_columns.pkl"]:
            (output_dir / artifact).write_text(f"new-{artifact}", encoding="utf-8")


class ServiceRetrainTest(unittest.TestCase):
    def test_failed_retrain_does_not_mutate_canonical_csvs_or_artifacts(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            data_dir = Path(temp_dir) / "data"
            write_fixture_data(data_dir)
            before = {path.name: path.read_bytes() for path in data_dir.iterdir()}

            with self.assertRaises(RuntimeError):
                service_retrain.retrain_classifier(
                    COMPANY,
                    data_path=data_dir,
                    train_module=FailingTrainModule(),
                )

            after = {path.name: path.read_bytes() for path in data_dir.iterdir()}

        self.assertEqual(after, before)

    def test_successful_retrain_promotes_staged_csvs_and_artifacts(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            data_dir = Path(temp_dir) / "data"
            write_fixture_data(data_dir)

            service_retrain.retrain_classifier(
                COMPANY,
                data_path=data_dir,
                train_module=SuccessfulTrainModule(),
            )

            company_csv = (data_dir / "company_data.csv").read_text(encoding="utf-8")
            esrs_csv = (data_dir / "company_esrs.csv").read_text(encoding="utf-8")
            classifier = (data_dir / "esrs_classifier.pkl").read_text(encoding="utf-8")

        self.assertIn("NewCo", company_csv)
        self.assertIn("Manufacturing,Services", company_csv)
        self.assertIn("NewCo;1", esrs_csv)
        self.assertEqual(classifier, "new-esrs_classifier.pkl")


if __name__ == "__main__":
    unittest.main()
