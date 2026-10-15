import hashlib
import importlib.util
import math
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import threading
import json
from concurrent.futures import ThreadPoolExecutor
from types import SimpleNamespace
from unittest.mock import patch


SCRIPT_DIR = Path(__file__).resolve().parents[2] / "scripts" / "greentech"
spec = importlib.util.spec_from_file_location("greentech_calculations", SCRIPT_DIR / "calculations.py")
calc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(calc)

sys.path.insert(0, str(SCRIPT_DIR))
try:
    measure_spec = importlib.util.spec_from_file_location("greentech_measure", SCRIPT_DIR / "measure.py")
    measure = importlib.util.module_from_spec(measure_spec)
    measure_spec.loader.exec_module(measure)
finally:
    sys.path.pop(0)


class GreenTechCalculationTest(unittest.TestCase):
    def test_cpu_and_memory_units_and_pue(self):
        result = calc.estimated_energy(3600, 3600, 160, 16, 4, 1.2)
        self.assertAlmostEqual(result["cpu_kwh"], 0.01)
        self.assertAlmostEqual(result["memory_kwh"], 4 * 0.3725 / 1000)
        self.assertAlmostEqual(result["facility_kwh"], (0.01 + 4 * 0.3725 / 1000) * 1.2)
        self.assertAlmostEqual(calc.emissions_g(0.01, 100), 1)

    def test_smt_does_not_exceed_physical_capacity(self):
        result = calc.estimated_energy(32, 1, 160, 16, 0, 1)
        self.assertEqual(result["effective_cpu_seconds"], 16)
        self.assertAlmostEqual(result["cpu_kwh"], 160 / 3_600_000)

    def test_negative_nonfinite_and_invalid_inputs_rejected(self):
        for value in (-1, math.inf, math.nan):
            with self.subTest(value=value), self.assertRaises(ValueError):
                calc.estimated_energy(value, 1, 160, 16, 4, 1)
        for cores, pue, tdp in ((0, 1, 160), (16, 0.9, 160), (16, 1, 0)):
            with self.assertRaises(ValueError):
                calc.estimated_energy(1, 1, tdp, cores, 4, pue)
        with self.assertRaises(ValueError):
            calc.emissions_g(1, -1)

    def test_sample_sd_and_repetition_requirement(self):
        self.assertEqual(calc.summary([1, 2, 3]), {"n": 3, "mean": 2, "sd": 1})
        with self.assertRaises(ValueError):
            calc.summary([1])

    def test_ratio_never_claims_traditional_development_comparison(self):
        result = calc.comparison(1, 4)
        self.assertEqual(result["candidate_over_baseline"], 0.25)
        self.assertEqual(result["reduction_percent"], 75)
        self.assertTrue(result["at_least_50_percent_in_this_scope"])
        self.assertFalse(result["supports_traditional_development_comparison"])
        self.assertEqual(calc.comparison(3, 2)["reduction_percent"], -50)
        with self.assertRaises(ValueError):
            calc.comparison(1, 0)

    def test_rapl_wrap_and_invalid_counter(self):
        self.assertEqual(calc.counter_delta_uj(95, 5, 100), 10)
        self.assertEqual(calc.counter_delta_uj(5, 15, 100), 10)
        for before, after, maximum in ((100, 5, 100), (0, 0, 0), (-1, 0, 100)):
            with self.assertRaises(ValueError):
                calc.counter_delta_uj(before, after, maximum)

    def test_literature_units_no_double_pue(self):
        self.assertAlmostEqual(calc.literature_llm_kwh(500), 0.0003)
        self.assertAlmostEqual(calc.literature_llm_kwh(1000), 0.0006)
        with self.assertRaises(ValueError):
            calc.literature_llm_kwh(-1)

    def test_off_by_default_before_loading_missing_input_or_writing(self):
        with tempfile.TemporaryDirectory() as scratch:
            output = Path(scratch) / "evidence"
            result = subprocess.run(
                [sys.executable, str(SCRIPT_DIR / "measure.py"),
                 "--artifact-dir", str(Path(scratch) / "absent"),
                 "--company-csv", "absent.csv", "--label-csv", "absent.csv",
                 "--output-prefix", str(output), "--tdp-w", "170",
                 "--tdp-source", "https://www.amd.com/"],
                env={**os.environ, "GREENTECH_MEASUREMENT_ENABLED": "false"},
                capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 2)
            self.assertIn("GREENTECH_MEASUREMENT_ENABLED=true", result.stderr)
            self.assertFalse(Path(str(output) + ".json").exists())
            self.assertFalse(Path(str(output) + ".md").exists())
            self.assertFalse(Path(str(output) + ".reservation.json").exists())


class GreenTechEvidenceTest(unittest.TestCase):
    def test_dataset_rejects_fractional_targets_before_conversion(self):
        import pandas as pd
        company = pd.DataFrame({"file": ["synthetic"],
            "company_data_sector": ["A"], "company_data_subsidiaries_countries": ["Spain"],
            "company_data_products_services": ["service"],
            "company_data_headquarters_country": ["Spain"], "company_data_company_size": ["small"],
            "company_data_juridic_form": ["limited"], "company_data_reporting_currency": ["EUR"],
            "company_data_annual_turnover": [1], "company_data_stock_listed": [False]})
        model = SimpleNamespace(feature_names_in_=["sector_A", "region_Spain", "product_service"])
        with tempfile.TemporaryDirectory() as scratch:
            companies, labels = Path(scratch) / "companies.csv", Path(scratch) / "labels.csv"
            company.to_csv(companies, sep=";", index=False)
            for value in (0.9, 1.9, -0.9, float("inf"), -1, 2):
                pd.DataFrame({"file": ["synthetic"], "esrs_e1": [value]}).to_csv(labels, sep=";", index=False)
                with self.subTest(value=value), self.assertRaisesRegex(ValueError, "non-binary targets"):
                    measure.dataset(companies, labels, model)
            for value in (0, 1, None):
                pd.DataFrame({"file": ["synthetic"], "esrs_e1": [value]}).to_csv(labels, sep=";", index=False)
                _, targets = measure.dataset(companies, labels, model)
                self.assertEqual(targets.iloc[0, 0], value or 0)
                self.assertEqual(targets.dtypes.iloc[0].kind, "i")

    def test_concurrent_runs_cannot_mix_or_overwrite_evidence(self):
        barrier, collision = threading.Barrier(2), threading.Event()
        with tempfile.TemporaryDirectory() as scratch, patch.object(measure, "markdown", side_effect=lambda report: report["run"]):
            prefix = Path(scratch) / "evidence"
            def run(name):
                barrier.wait(timeout=5)
                try:
                    with measure.reserve_evidence(prefix) as outputs:
                        self.assertTrue(collision.wait(timeout=5))
                        measure.publish_evidence(outputs, {"run": name})
                    return name
                except FileExistsError:
                    collision.set()
                    return None
            with ThreadPoolExecutor(max_workers=2) as pool:
                winners = list(pool.map(run, ("first", "second")))
            winner = next(name for name in winners if name is not None)
            self.assertEqual(winners.count(None), 1)
            self.assertEqual(json.loads(Path(str(prefix) + ".json").read_text()), {"run": winner})
            self.assertEqual(Path(str(prefix) + ".md").read_text(), winner)
            self.assertEqual(json.loads(Path(str(prefix) + ".reservation.json").read_text())["status"], "complete")
            with self.assertRaises(FileExistsError), measure.reserve_evidence(prefix):
                self.fail("completed prefix reused")

    def test_existing_output_and_failed_reservation_are_preserved(self):
        with tempfile.TemporaryDirectory() as scratch:
            prefix = Path(scratch) / "evidence"
            output = Path(str(prefix) + ".md")
            output.write_text("original")
            with self.assertRaises(FileExistsError), measure.reserve_evidence(prefix):
                self.fail("existing output accepted")
            self.assertEqual(output.read_text(), "original")
            self.assertEqual(json.loads(Path(str(prefix) + ".reservation.json").read_text())["status"], "failed")
            with self.assertRaises(FileExistsError), measure.reserve_evidence(prefix):
                self.fail("failed prefix reused")

    def test_product_vocabulary_artifact_is_optional_and_required_artifacts_fail_closed(self):
        with tempfile.TemporaryDirectory() as scratch:
            inputs = {name: Path(scratch) / name for name in measure.REQUIRED_ARTIFACTS + measure.OPTIONAL_ARTIFACTS}
            for name in measure.REQUIRED_ARTIFACTS:
                inputs[name].write_bytes(name.encode())
            hashes = measure.input_hashes(inputs)
            self.assertIsNone(hashes["product_columns.pkl"])
            self.assertEqual(hashes["esrs_classifier.pkl"], hashlib.sha256(b"esrs_classifier.pkl").hexdigest())
            inputs["product_columns.pkl"].write_bytes(b"vocabulary")
            self.assertNotEqual(hashes, measure.input_hashes(inputs))
            self.assertEqual(measure.input_hashes(inputs)["product_columns.pkl"], hashlib.sha256(b"vocabulary").hexdigest())
            inputs["esrs_columns.pkl"].unlink()
            with self.assertRaises(FileNotFoundError):
                measure.input_hashes(inputs)

    def test_exclusive_publisher_never_truncates_existing_output(self):
        with tempfile.TemporaryDirectory() as scratch, patch.object(measure, "markdown", return_value="new"):
            outputs = [Path(scratch) / "evidence.json", Path(scratch) / "evidence.md"]
            outputs[1].write_text("original")
            with self.assertRaises(FileExistsError):
                measure.publish_evidence(outputs, {"run": "new"})
            self.assertEqual(outputs[1].read_text(), "original")

    def test_tdp_year_is_explicit_or_unknown(self):
        report = {"started_at_utc": "2026-10-08", "hardware": {"cpu_model": "synthetic", "distribution": "test", "physical_cores": 1},
            "measurement": {"method": "test", "rapl_probe": [], "tdp_w": 1, "memory_gb": 1, "pue": 1, "grid_g_co2e_per_kwh": 1},
            "operations": {}, "comparisons": {}, "literature_reference": {"output_tokens": 1, "energy_kwh_per_query": 1},
            "phases_es": {}, "limitations_es": [], "sources": {"tdp": {"url": "https://example.org/", "year": None}}}
        self.assertIn("tdp (año desconocido)", measure.markdown(report))
        report["sources"]["tdp"]["year"] = 2025
        self.assertIn("tdp (2025)", measure.markdown(report))
        with tempfile.TemporaryDirectory() as scratch:
            argv = ["measure.py", "--artifact-dir", "absent", "--company-csv", "absent", "--label-csv", "absent",
                    "--output-prefix", str(Path(scratch) / "evidence"), "--tdp-w", "1", "--tdp-source", "https://example.org/"]
            with patch.dict(os.environ, {"GREENTECH_MEASUREMENT_ENABLED": "true"}), patch.object(measure, "hardware", return_value={"physical_cores": 1}), patch.object(measure, "benchmark") as benchmark:
                with patch.object(sys, "argv", argv):
                    measure.main()
                self.assertIsNone(benchmark.call_args.args[0].tdp_source_year)
                argv[argv.index("--output-prefix") + 1] += "-dated"
                with patch.object(sys, "argv", argv + ["--tdp-source-year", "2025"]):
                    measure.main()
                self.assertEqual(benchmark.call_args.args[0].tdp_source_year, 2025)


if __name__ == "__main__":
    unittest.main()
