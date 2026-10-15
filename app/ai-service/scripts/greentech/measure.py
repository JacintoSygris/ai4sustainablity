#!/usr/bin/env python3
"""Opt-in, offline benchmark. Never imported by the serving application."""

import argparse
from contextlib import contextmanager
import gc
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import subprocess
import sys
import threading
import time
import warnings
from datetime import datetime, timezone
from importlib.metadata import version

from calculations import (comparison, counter_delta_uj, emissions_g,
                          estimated_energy, literature_llm_kwh, summary)

SOURCES = {
    "green_algorithms": {"year": 2021, "url": "https://www.green-algorithms.org/",
                         "paper": "https://arxiv.org/abs/2007.07610"},
    "grid_emissions": {"year": 2024, "url": "https://www.sistemaelectrico-ree.es/es/2024/informe-del-sistema-electrico/generacion/generacion-de-energia-electrica/generacion-no-renovable-de-energia-electrica",
                       "national_tonnes_co2e": 27_000_000},
    "grid_generation": {"year": 2024, "url": "https://www.sistemaelectrico-ree.es/es/2024/informe-del-sistema-electrico/generacion/generacion-de-energia-electrica/generacion-total-de-energia-electrica",
                        "national_gwh": 262_247, "status": "provisional January 2025"},
    "llm": {"year": 2025, "url": "https://epoch.ai/gradient-updates/how-much-energy-does-chatgpt-use",
            "reference_wh": 0.3, "reference_output_tokens": 500,
            "scope": "Literature estimate: short-input GPT-4o, infrastructure overhead included"},
}


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


# The product vocabulary is checked against the model's own feature names, so
# product_columns.pkl is provenance only. The bundled model-artifacts/gpt41
# directory does not ship it: hash it when present, record None when absent.
REQUIRED_ARTIFACTS = ("esrs_classifier.pkl", "esrs_columns.pkl", "sector_columns.pkl", "region_columns.pkl")
OPTIONAL_ARTIFACTS = ("product_columns.pkl",)


def input_hashes(inputs):
    """SHA-256 per read-only input; only an absent optional artifact maps to None."""
    return {name: None if name in OPTIONAL_ARTIFACTS and not path.exists() else sha256(path)
            for name, path in inputs.items()}


@contextmanager
def reserve_evidence(prefix):
    """Keep an exclusive reservation, including after failure or completion.

    Readers must require status=complete in the reservation before using the
    pair. A crashed/failed run retains its prefix; retries need a new prefix.
    """
    outputs = [Path(str(prefix) + suffix) for suffix in (".json", ".md")]
    reservation = Path(str(prefix) + ".reservation.json")
    reservation.parent.mkdir(parents=True, exist_ok=True)
    with reservation.open("x", encoding="utf-8") as marker:
        def status(value):
            marker.seek(0)
            marker.truncate()
            json.dump({"status": value}, marker)
            marker.flush()
            os.fsync(marker.fileno())
        status("reserved")
        try:
            if any(path.exists() or path.is_symlink() for path in outputs):
                raise FileExistsError("evidence destination already exists")
            yield outputs
        except BaseException:
            status("failed")
            raise
        else:
            status("complete")


def publish_evidence(outputs, report):
    """Exclusive output creation under the caller's prefix reservation."""
    payloads = [json.dumps(report, ensure_ascii=False, indent=2, allow_nan=False) + "\n",
                markdown(report)]
    with outputs[0].open("x", encoding="utf-8") as json_file, outputs[1].open("x", encoding="utf-8") as md_file:
        for handle, payload in zip((json_file, md_file), payloads):
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())


class Rapl:
    """Package counters only (never sum package and nested core domains).

    Polling prevents multiple-wrap ambiguity during long fits. A failed read
    aborts the run instead of converting missing measurements into zero energy.
    These counters include other host workloads and idle package energy.
    """

    def __init__(self, root=Path("/sys/class/powercap")):
        self.domains = []
        self.probe = []
        for folder in sorted(root.glob("intel-rapl:*")):
            if folder.name.count(":") != 1:
                continue
            try:
                name = (folder / "name").read_text().strip()
                maximum = int((folder / "max_energy_range_uj").read_text())
                counter = folder / "energy_uj"
                int(counter.read_text())
                if name.startswith("package-"):
                    self.domains.append((counter, maximum))
                self.probe.append({"domain": folder.name, "name": name, "readable": True})
            except (OSError, ValueError) as exc:
                self.probe.append({"domain": folder.name, "readable": False,
                                   "reason": type(exc).__name__})

    def read(self):
        return [int(path.read_text()) for path, _ in self.domains]

    def start(self):
        self.previous = self.read()
        self.total_uj = 0
        self.error = None
        self.stop_event = threading.Event()
        self.thread = threading.Thread(target=self.poll, daemon=True)
        if self.domains:
            self.thread.start()

    def tick(self):
        current = self.read()
        self.total_uj += sum(counter_delta_uj(before, after, maximum)
                             for before, after, (_, maximum)
                             in zip(self.previous, current, self.domains))
        self.previous = current

    def poll(self):
        try:
            while not self.stop_event.wait(0.2):
                self.tick()
        except Exception as exc:
            self.error = exc

    def stop(self):
        if not self.domains:
            return None
        self.stop_event.set()
        self.thread.join()
        if self.error:
            raise self.error
        self.tick()
        return self.total_uj / 1_000_000


def hardware():
    cpu = json.loads(subprocess.check_output(["lscpu", "--json"], env={**os.environ, "LC_ALL": "C"}))
    fields = {row["field"].rstrip(":"): row["data"] for row in cpu["lscpu"]}
    return {"cpu_model": fields["Model name"], "logical_cpus": int(fields["CPU(s)"]),
            "physical_cores": int(fields["Core(s) per socket"]) * int(fields["Socket(s)"]),
            "sockets": int(fields["Socket(s)"]), "affinity_logical_cpus": len(os.sched_getaffinity(0)),
            "os": platform.system(), "kernel": platform.release(),
            "distribution": platform.freedesktop_os_release().get("PRETTY_NAME", "unknown"),
            "python": platform.python_version()}


class Meter:
    def __init__(self, args, host):
        self.args, self.host, self.rapl = args, host, Rapl()
        if self.rapl.domains and len(self.rapl.domains) != host["sockets"]:
            self.rapl.probe.append({"readable": False, "reason": "incomplete_package_coverage"})
            self.rapl.domains = []
        self.grid = SOURCES["grid_emissions"]["national_tonnes_co2e"] / SOURCES["grid_generation"]["national_gwh"]
        self.samples = {}

    def run(self, name, operation, units=1):
        gc.collect()
        self.rapl.start()
        cpu_start, wall_start = time.process_time(), time.perf_counter()
        try:
            result = operation()
        finally:
            wall, cpu = time.perf_counter() - wall_start, time.process_time() - cpu_start
            joules = self.rapl.stop()
        estimate = estimated_energy(cpu, wall, self.args.tdp_w, self.host["physical_cores"],
                                    self.args.memory_gb, self.args.pue)
        # RAPL package + RAM model + PUE remains a mixed estimate at facility level.
        energy = estimate["facility_kwh"] if joules is None else (
            joules / 3_600_000 + estimate["memory_kwh"]) * self.args.pue
        sample = {"wall_seconds": wall, "process_cpu_seconds": cpu,
                  "units": units, "energy_kwh": energy,
                  "energy_kwh_per_unit": energy / units,
                  "wall_seconds_per_unit": wall / units,
                  "emissions_g_co2e": emissions_g(energy, self.grid),
                  "rapl_package_joules": joules, "tdp_model": estimate}
        self.samples.setdefault(name, []).append(sample)
        print(f"{name}: {wall:.3f} s; {'ESTIMACIÓN' if joules is None else 'RAPL + estimación RAM/PUE'} {energy:.8g} kWh", flush=True)
        return result

    def results(self):
        keys = ("wall_seconds", "process_cpu_seconds", "energy_kwh", "energy_kwh_per_unit",
                "wall_seconds_per_unit", "emissions_g_co2e")
        return {name: {"samples": samples, "summary": {key: summary(s[key] for s in samples) for key in keys}}
                for name, samples in self.samples.items()}


def load_model(path):
    import joblib
    # Match serving's read-bytes -> in-memory joblib path; OS page cache is warm.
    return joblib.load(io.BytesIO(path.read_bytes()))


def dataset(company_csv, label_csv, model):
    """Mirror train_model_yaml's new-format features, in persisted model order.

    No company names, report text or predictions are written to evidence.
    Vocabulary is fixed to the deployed artifact; no new featurizer is promoted.
    """
    import numpy as np
    import pandas as pd

    company = pd.read_csv(company_csv, sep=";")
    labels = pd.read_csv(label_csv, sep=";")
    if company["file"].duplicated().any() or labels["file"].duplicated().any():
        raise ValueError("duplicate training keys")
    frame = company.merge(labels, on="file", validate="one_to_one")
    if len(frame) != len(company) or len(frame) != len(labels):
        raise ValueError("training CSV keys do not align")
    features = pd.DataFrame(index=frame.index)
    lists = {"sector": "company_data_sector", "region": "company_data_subsidiaries_countries",
             "product": "company_data_products_services"}
    for prefix, source in lists.items():
        tokens = frame[source].fillna("").str.split(",").apply(lambda xs: {x.strip() for x in xs if x.strip()})
        expected = {name.removeprefix(prefix + "_") for name in model.feature_names_in_ if name.startswith(prefix + "_")}
        if set().union(*tokens) != expected:
            raise ValueError(f"{prefix} vocabulary differs from deployed recipe")
        for token in sorted(expected):
            features[prefix + "_" + token] = tokens.apply(lambda xs: int(token in xs))
    for target, source in {"headquarters_country": "company_data_headquarters_country",
                           "company_size": "company_data_company_size", "juridic_form": "company_data_juridic_form",
                           "reporting_currency": "company_data_reporting_currency"}.items():
        features[target] = frame[source]
    features["annual_turnover_log"] = np.log1p(frame["company_data_annual_turnover"].fillna(0).clip(lower=0))
    features["stock_listed_flag"] = frame["company_data_stock_listed"].map({True: 1, False: 0, "True": 1, "False": 0}).fillna(0).astype(int)
    keys = [key for key in frame.columns if key.startswith("esrs_")]
    targets = frame[keys].fillna(0)
    if not targets.isin([0, 1]).all().all():
        raise ValueError("non-binary targets")
    return features[list(model.feature_names_in_)], targets.astype(int)


def markdown(report):
    lines = ["# Medición Green Tech — " + report["started_at_utc"][:10], "",
             "Evidencia local. Tiempos observados; energía y emisiones estimadas.",
             "No se compara con una línea base de desarrollo tradicional.", "",
             "## Entorno y método", "",
             f"CPU: {report['hardware']['cpu_model']}. Sistema: {report['hardware']['distribution']}.",
             f"Método: `{report['measurement']['method']}`. Sondeo RAPL: `{report['measurement']['rapl_probe']}`.",
             f"TDP declarado: {report['measurement']['tdp_w']} W; núcleos físicos: {report['hardware']['physical_cores']}; "
             f"RAM asignada supuesta: {report['measurement']['memory_gb']} GB; PUE supuesto: {report['measurement']['pue']}.",
             "E = PUE × [min(CPU_s, tiempo_s × núcleos) × TDP/núcleos + tiempo_s × RAM_GB × 0,3725] / 3.600.000 kWh.",
             "Es una adaptación de Green Algorithms; TDP no es potencia medida. RAPL, si es legible, sustituye sólo el término CPU.",
             f"Intensidad española de generación (2024): {report['measurement']['grid_g_co2e_per_kwh']:.6g} gCO₂e/kWh, "
             "calculada con 27 millones tCO₂e / 262.247 GWh (Red Eléctrica). No es el factor horario de esta ejecución ni el del servidor de despliegue.", "",
             "## Operaciones (media ± desviación estándar muestral)", "",
             "| Operación | Repeticiones | Unidades/repetición | Tiempo s | Energía estimada kWh | Emisiones estimadas gCO₂e |",
             "|---|---:|---:|---:|---:|---:|"]
    for name, value in report["operations"].items():
        s = value["summary"]
        pair = lambda key: f"{s[key]['mean']:.6g} ± {s[key]['sd']:.3g}"
        lines.append(f"| `{name}` | {s['wall_seconds']['n']} | {value['samples'][0]['units']} | {pair('wall_seconds')} | {pair('energy_kwh')} | {pair('emissions_g_co2e')} |")
    lines += ["", "Unidades: perfiles para inferencia; ajustes para entrenamiento; cargas para deserialización.",
              "Inferencia: `Pipeline.predict`, preprocesado incluido; no mide HTTP, extracción, CRC ni todo `/predict`.",
              "Lotes y llamadas individuales procesan los mismos perfiles; se comprueba igualdad de predicciones.",
              "Carga por petición frente a una carga: incluye una carga inicial en la opción reutilizada; caché de disco caliente.",
              "", "## Cocientes y alcance", "",
              "| Comparación | Candidato/base | Reducción % | ¿Al menos 50 % en este alcance? |",
              "|---|---:|---:|---|"]
    for name, value in report["comparisons"].items():
        lines.append(f"| `{name}` | {value['candidate_over_baseline']:.6g} | {value['reduction_percent']:.6g} | {'Sí' if value['at_least_50_percent_in_this_scope'] else 'No'} |")
    lines.append("")
    lines.extend(f"- `{name}`: {value['interpretation_es']}" for name, value in report["comparisons"].items())
    lines += ["", "## Referencia bibliográfica", "",
              f"Escenario de {report['literature_reference']['output_tokens']} tokens de salida: "
              f"{report['literature_reference']['energy_kwh_per_query']:.6g} kWh/petición estimados con Epoch AI "
              "(GPT-4o, entrada corta). No se ha llamado a un LLM ni probado equivalencia de calidad; no es el coste real de GPT-4.1.",
              "El factor español aplicado a esta referencia es hipotético. La infraestructura ya está incluida: no se vuelve a multiplicar por PUE.",
              "", "## Fases y límites", ""]
    lines.extend(f"- **{phase}**: {text}" for phase, text in report["phases_es"].items())
    lines += ["", *["- " + limitation for limitation in report["limitations_es"]], "",
              "## Fuentes", ""]
    for name, source in report["sources"].items():
        lines.append(f"- [{name} ({source['year'] if source['year'] is not None else 'año desconocido'})]({source['url']})")
    lines += ["", "El JSON adjunto conserva muestras, hashes, parámetros, versiones y cocientes sin datos personales ni modelos."]
    return "\n".join(lines) + "\n"


def main():
    parser = argparse.ArgumentParser(description="Medición Green Tech local, desactivada por defecto")
    parser.add_argument("--artifact-dir", type=Path, required=True)
    parser.add_argument("--company-csv", type=Path, required=True)
    parser.add_argument("--label-csv", type=Path, required=True)
    parser.add_argument("--output-prefix", type=Path, required=True)
    parser.add_argument("--tdp-w", type=float, required=True)
    parser.add_argument("--tdp-source", required=True)
    parser.add_argument("--tdp-source-year", type=int, default=None,
                        help="Año verificable de la fuente TDP; omitido = desconocido")
    parser.add_argument("--memory-gb", type=float, default=4)
    parser.add_argument("--pue", type=float, default=1)
    parser.add_argument("--repetitions", type=int, default=3)
    parser.add_argument("--calls", type=int, default=8, help="N llamadas en lote; N × tamaño de lote llamadas individuales")
    parser.add_argument("--batch-size", type=int, default=4)
    parser.add_argument("--feedback-size", type=int, default=32)
    parser.add_argument("--llm-output-tokens", type=int, default=500)
    args = parser.parse_args()
    if os.environ.get("GREENTECH_MEASUREMENT_ENABLED", "false").lower() != "true":
        parser.error("GREENTECH_MEASUREMENT_ENABLED=true es obligatorio; no se ha medido ni cargado ningún modelo")
    if args.repetitions < 2 or min(args.calls, args.batch_size, args.feedback_size, args.llm_output_tokens) < 1:
        parser.error("repeticiones >= 2 y tamaños positivos obligatorios")
    host = hardware()
    estimated_energy(0, 0, args.tdp_w, host["physical_cores"], args.memory_gb, args.pue)
    if args.tdp_source_year is not None and not 1 <= args.tdp_source_year <= datetime.now(timezone.utc).year:
        parser.error("año de fuente TDP inválido")
    try:
        with reserve_evidence(args.output_prefix) as outputs:
            benchmark(args, host, outputs)
    except FileExistsError:
        parser.error("el destino o su reserva ya existe; utilice otro prefijo para conservar la evidencia")


def benchmark(args, host, outputs):
    benchmark_code = {path.name: path for path in (Path(__file__), Path(__file__).with_name("calculations.py"))}
    code_hashes = {name: sha256(path) for name, path in benchmark_code.items()}
    started = datetime.now(timezone.utc).isoformat()
    import joblib
    import numpy as np
    from sklearn.base import clone
    from sklearn.ensemble import RandomForestClassifier
    from sklearn.exceptions import InconsistentVersionWarning
    from sklearn.linear_model import SGDClassifier
    from sklearn.multioutput import ClassifierChain
    from skmultilearn.model_selection import IterativeStratification

    model_path = args.artifact_dir / "esrs_classifier.pkl"
    inputs = {"companies": args.company_csv, "labels": args.label_csv,
              **{name: args.artifact_dir / name for name in REQUIRED_ARTIFACTS + OPTIONAL_ARTIFACTS}}
    hashes = input_hashes(inputs)
    with warnings.catch_warnings(record=True) as caught:
        warnings.simplefilter("always", InconsistentVersionWarning)
        model = load_model(model_path)
    serialized_versions = sorted({w.message.original_sklearn_version for w in caught if isinstance(w.message, InconsistentVersionWarning)})
    # Warn once in metadata; repeated load tests must not spend time logging.
    warnings.filterwarnings("ignore", category=InconsistentVersionWarning)
    chain = model.named_steps["classifier"]
    if not isinstance(chain, ClassifierChain) or not isinstance(chain.estimators_[0], RandomForestClassifier):
        raise ValueError("expected deployed RandomForest ClassifierChain")
    x, y = dataset(args.company_csv, args.label_csv, model)
    if y.columns.tolist() != joblib.load(args.artifact_dir / "esrs_columns.pkl"):
        raise ValueError("target order differs from deployed artifact")
    np.random.seed(42)  # Freeze iterative tie-breaking for this run, not historical split recovery.
    train_idx, test_idx = next(IterativeStratification(n_splits=2, order=1).split(x, y))
    count = args.calls * args.batch_size
    if max(count, args.feedback_size) > len(test_idx):
        raise ValueError("requested benchmark/feedback larger than reserved fold")
    xt, yt = x.iloc[train_idx], y.iloc[train_idx]
    fb_idx = test_idx[:args.feedback_size]
    xf, yf = x.iloc[fb_idx], y.iloc[fb_idx]
    all_idx = np.concatenate([train_idx, fb_idx])
    benchmark = x.iloc[test_idx[:count]]
    rows = [benchmark.iloc[[index]] for index in range(count)]
    batches = [benchmark.iloc[index:index + args.batch_size] for index in range(0, count, args.batch_size)]
    meter = Meter(args, host)
    equal = []
    # One unmeasured warm-up; all timed repeats then use the same warm-cache scope.
    model.predict(rows[0])

    def singles(classifier):
        return np.concatenate([classifier.predict(row) for row in rows])

    def reload_each():
        predictions = []
        for row in rows:
            fresh = load_model(model_path)
            predictions.append(fresh.predict(row))
            del fresh
        return np.concatenate(predictions)

    def load_once():
        fresh = load_model(model_path)
        return singles(fresh)

    for repetition in range(args.repetitions):
        print(f"Repetición {repetition + 1}/{args.repetitions}", flush=True)
        meter.run("rf_recipe_fit", lambda: clone(model).fit(xt, yt))
        # Alternate paired inference order to reduce a systematic order advantage.
        operations = [("inference_single_loaded", lambda: singles(model)),
                      ("inference_batch_loaded", lambda: np.concatenate([model.predict(batch) for batch in batches]))]
        predictions = {}
        for name, operation in operations[::1 if repetition % 2 == 0 else -1]:
            predictions[name] = meter.run(name, operation, count)
        equal.append(bool(np.array_equal(*predictions.values())))
        meter.run("model_load", lambda: load_model(model_path))
        load_operations = [("requests_load_each", reload_each), ("requests_load_once", load_once)]
        for name, operation in load_operations[::1 if repetition % 2 == 0 else -1]:
            actual = meter.run(name, operation, count)
            equal.append(bool(np.array_equal(actual, predictions["inference_single_loaded"])))
        # Both candidates have seen exactly train + held-out feedback after this step.
        meter.run("rf_full_retrain_with_feedback", lambda: clone(model).fit(x.iloc[all_idx], y.iloc[all_idx]))
        preprocess = clone(model.named_steps["preprocessor"])
        estimators = [SGDClassifier(loss="log_loss", random_state=42) for _ in y.columns]

        def initialize_sgd():
            transformed = preprocess.fit_transform(xt)
            for index, estimator in enumerate(estimators):
                estimator.partial_fit(transformed, yt.iloc[:, index], classes=np.array([0, 1]))
        meter.run("sgd_initial_fit", initialize_sgd)

        def update_sgd():
            transformed = preprocess.transform(xf)
            for index, estimator in enumerate(estimators):
                estimator.partial_fit(transformed, yf.iloc[:, index])
        meter.run("sgd_partial_fit_feedback", update_sgd)
    if not all(equal):
        raise ValueError("batched/cached predictions differ; benchmark invalid")
    if hashes != input_hashes(inputs):
        raise ValueError("read-only inputs changed during benchmark")
    if code_hashes != {name: sha256(path) for name, path in benchmark_code.items()}:
        raise ValueError("benchmark code changed during run; evidence invalid")
    results = meter.results()
    mean = lambda name, key="energy_kwh": results[name]["summary"][key]["mean"]
    comparisons = {}
    for name, candidate, baseline, interpretation in [
        ("batch_vs_single", mean("inference_batch_loaded"), mean("inference_single_loaded"),
         "Mismos perfiles y predicciones: sólo acredita el alcance local de agrupación de llamadas, con energía estimada."),
        ("load_once_vs_each_request", mean("requests_load_once"), mean("requests_load_each"),
         "Mismos perfiles y predicciones, incluyendo una carga inicial: comparación local de reutilización; disco caliente, sin arranque de proceso."),
        ("incremental_vs_full_retrain", mean("sgd_partial_fit_feedback"), mean("rf_full_retrain_with_feedback"),
         "Coste de actualización condicionado a un SGD existente. Algoritmos y calidad distintos; no prueba que el SGD pueda sustituir al RF."),
        ("sgd_initial_plus_update_vs_full_retrain", mean("sgd_initial_fit") + mean("sgd_partial_fit_feedback"), mean("rf_full_retrain_with_feedback"),
         "Incluye inicialización del SGD. No evalúa convergencia, calidad de recomendaciones ni reentrenamiento real con correcciones humanas."),
        ("rf_single_vs_literature_llm", mean("inference_single_loaded", "energy_kwh_per_unit"), literature_llm_kwh(args.llm_output_tokens),
         "Referencia bibliográfica hipotética de LLM más pesado; alcance de infraestructura y tarea distintos. No existe equivalencia de calidad ni medida del proveedor.")]:
        comparisons[name] = {**comparison(candidate, baseline), "candidate_energy_kwh": candidate,
                             "baseline_energy_kwh": baseline, "interpretation_es": interpretation}
    report = {
        "schema": "greentech-offline-v1", "started_at_utc": started,
        "finished_at_utc": datetime.now(timezone.utc).isoformat(),
        "feature_flag": {"name": "GREENTECH_MEASUREMENT_ENABLED", "default": False, "enabled_for_this_run": True},
        "hardware": host,
        "versions": {name: version(name) for name in ("scikit-learn", "numpy", "pandas", "joblib", "scikit-multilearn")},
        "serialized_sklearn_versions": serialized_versions,
        "measurement": {"method": "rapl_package_plus_estimated_ram_pue" if meter.rapl.domains else "estimated_cpu_time_tdp_plus_allocated_ram",
                        "rapl_probe": meter.rapl.probe, "tdp_w": args.tdp_w, "tdp_source": args.tdp_source,
                        "memory_gb": args.memory_gb, "memory_basis": "declared allocation assumption, not measured draw",
                        "pue": args.pue, "pue_basis": "operator assumption, not measured facility PUE",
                        "grid_country": "Spain", "grid_year": 2024, "grid_g_co2e_per_kwh": meter.grid,
                        "grid_basis": "national generation average, rounded emissions numerator; not consumption/lifecycle intensity",
                        "codecarbon": "not required: direct RAPL probe, explicit fallback; no telemetry or dependency installation"},
        "inputs_sha256": hashes,
        "benchmark_code_sha256": code_hashes,
        "workload": {"profile": "new_format_732_v1_gpt41", "rows_in_corpus": len(x), "target_count": y.shape[1],
                     "training_rows": len(train_idx), "reserved_rows": len(test_idx), "feedback_rows": len(fb_idx),
                     "feedback_basis": "reserved real corpus profiles with existing silver labels; proxy feedback, no human corrections",
                     "full_retrain_rows": len(all_idx), "feature_count": x.shape[1],
                     "split": "IterativeStratification(n_splits=2, order=1), numpy seed=42; historical exact indices unavailable",
                     "train_indices_sha256": hashlib.sha256(np.asarray(train_idx, dtype="<i8").tobytes()).hexdigest(),
                     "rf_parameters": chain.estimators_[0].get_params(),
                     "joblib_effective_n_jobs_minus_one": joblib.effective_n_jobs(-1),
                     "chain_parameters": {"cv": chain.cv, "order": chain.order, "random_state": chain.random_state},
                     "fit_scope": "clone persisted RF/chain/preprocessor recipe, fit only; no search, evaluation, serialization or label extraction",
                     "batch_predict_calls_per_repeat": args.calls, "batch_size": args.batch_size,
                     "single_predict_calls_per_repeat": count, "prediction_equality_checks": equal,
                     "warmup": "one single predict excluded; page-cache warm loads; no process restart"},
        "operations": results, "comparisons": comparisons,
        "literature_reference": {"output_tokens": args.llm_output_tokens,
                                 "derived_wh_per_output_token": literature_llm_kwh(1) * 1000,
                                 "energy_kwh_per_query": literature_llm_kwh(args.llm_output_tokens),
                                 "emissions_g_co2e_spain_hypothetical": emissions_g(literature_llm_kwh(args.llm_output_tokens), meter.grid),
                                 "measured": False, "quality_equivalence_validated": False},
        "traditional_development_comparison": {"supported": False,
                        "reason_es": "Falta una línea base de desarrollo tradicional con alcance, calidad, volumen y ciclo de vida equivalentes."},
        "phases_es": {
            "Diseño": "Comparaciones locales de reutilización y lotes; referencia LLM hipotética. Sin energía histórica de diseño.",
            "Desarrollo": "No medido: sesiones de programación, compilación, CI y experimentos anteriores sin contadores recuperables.",
            "Entrenamiento": "Ajuste repetido de la receta RF y comparación exploratoria SGD con etiquetas de plata; no incluye extracción LLM histórica.",
            "Ejecución": "Inferencia CPU y deserialización locales; no mide la infraestructura completa de Laravel/Next/FastAPI ni usuarios reales."},
        "limitations_es": [
            "Sin contador accesible, la energía es una estimación; la SD mide variabilidad de ejecución, no incertidumbre de TDP/RAM/PUE.",
            "TDP nominal no refleja potencia instantánea, turbo, límite Eco ni consumo en reposo. SMT se limita a capacidad física en el modelo.",
            "RAM fija supuesta; excluye disco, red, GPU, monitor y carbono incorporado. Si RAPL es legible, incluye otras cargas del host.",
            "Host compartido, sin aislamiento ni sustracción de reposo; resultados locales no extrapolables al servidor de despliegue.",
            "Partición iterativa reproducible en esta sesión, sin reconstruir el entrenamiento histórico ni validar fuga entre empresas.",
            "Ninguna comparación se mide frente a una línea base de desarrollo tradicional.",
            "No se modifica el modelo desplegado, el motor de aprendizaje, los datos de usuarios ni configuración de producción."],
        "sources": {**SOURCES, "tdp": {"url": args.tdp_source, "year": args.tdp_source_year}},
    }
    publish_evidence(outputs, report)
    print("Evidencia escrita. Comparación con desarrollo tradicional: no evaluada.")


if __name__ == "__main__":
    main()
