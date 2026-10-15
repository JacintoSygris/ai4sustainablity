"""Offline XBRL concept retrieval and supervised pair ranking; no taxonomy/network access.

The local evaluation report goes to ai-service/artifacts/ (git-ignored, never committed).
"""
from __future__ import annotations

import argparse
from collections import defaultdict
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import platform
import re

import numpy as np
import scipy
import sklearn
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import GroupKFold

# Keep the historical CLI/evaluation imports working from a bare checkout.
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from services.xbrl_concept_matcher import (
    MODEL_VERSION, SEED, LEARNED_FEATURE_INDICES, FEATURES,
    Matcher, concept_text, datapoint_text, load_inputs,
)


def rank_metrics(ranks: list[int]) -> dict:
    values = np.asarray(ranks)
    return {"count": len(ranks), "top_1": float(np.mean(values == 1)),
            "top_5": float(np.mean(values <= 5)), "mrr": float(np.mean(1 / values))}


def evaluate(known, concepts, folds=5):
    groups = np.array([p["target"] for p in known])
    all_ranks = defaultdict(list)
    fold_results = []
    assignments = []
    for fold, (train_ids, test_ids) in enumerate(GroupKFold(n_splits=folds).split(known, groups=groups), 1):
        train = [known[i] for i in train_ids]
        test = [known[i] for i in test_ids]
        assert {p["target"] for p in train}.isdisjoint(p["target"] for p in test)
        matcher = Matcher(concepts, train).fit(train)
        ranks = defaultdict(list)
        for p in test:
            query_ranks = {}
            for model, scores in matcher.scores(p).items():
                if model == "dr_prefix_baseline":
                    continue  # No independent DR metadata for held-out concepts.
                rank = int(np.flatnonzero(matcher.order(scores) == matcher.index[p["target"]])[0]) + 1
                query_ranks[model] = rank
                ranks[model].append(rank)
                all_ranks[model].append(rank)
            assignments.append({"datapoint_id": p["id"], "fold": fold, "ranks": query_ranks})
        fold_results.append({"fold": fold, "train_count": len(train), "test_count": len(test),
                             "metrics": {k: rank_metrics(v) for k, v in ranks.items()}})
    return {"group_by": "target_concept_qname", "folds": fold_results,
            "metrics": {k: rank_metrics(v) for k, v in all_ranks.items()},
            "assignments": sorted(assignments, key=lambda x: x["datapoint_id"])}


def evaluate_dr_reuse(known, concepts, folds=5):
    """Separate closed-world DR reuse task; no held-out mapping supplies metadata.

    Group identical query texts, not concepts: other indicators can describe an
    already seen concept. Report coverage separately; unseen DR/concept pairs are
    outside this baseline's capability and are not silently scored as successes.
    """
    groups = np.array([" ".join(datapoint_text(p).lower().split()) for p in known])
    all_ranks = defaultdict(list)
    assignments, fold_results = [], []
    for fold, (train_ids, test_ids) in enumerate(GroupKFold(n_splits=folds).split(known, groups=groups), 1):
        train = [known[i] for i in train_ids]
        test = [known[i] for i in test_ids]
        model = Matcher(concepts, train).fit(train)
        ranks = defaultdict(list)
        for p in test:
            applicable = model.index[p["target"]] in model.dr.get(p["dr"], ())
            entry = {"datapoint_id": p["id"], "fold": fold, "applicable": applicable, "ranks": {}}
            if applicable:
                for name, scores in model.scores(p).items():
                    rank = int(np.flatnonzero(model.order(scores) == model.index[p["target"]])[0]) + 1
                    entry["ranks"][name] = rank
                    ranks[name].append(rank)
                    all_ranks[name].append(rank)
            assignments.append(entry)
        fold_results.append({"fold": fold, "train_count": len(train), "test_count": len(test),
                             "applicable_count": len(ranks["dr_prefix_baseline"]),
                             "metrics": {k: rank_metrics(v) for k, v in ranks.items() if v}})
    applicable_count = len(all_ranks["dr_prefix_baseline"])
    return {"protocol": "closed_world_dr_reuse", "group_by": "normalized_datapoint_text",
            "applicability": "target DR/concept association present in training only",
            "query_count": len(known), "applicable_count": applicable_count,
            "excluded_count": len(known) - applicable_count,
            "coverage": applicable_count / len(known), "folds": fold_results,
            "metrics": {k: rank_metrics(v) for k, v in all_ranks.items() if v},
            "assignments": sorted(assignments, key=lambda x: x["datapoint_id"])}


def suggestions(matcher, unknown, top_k=5):
    rows = []
    for point in unknown:
        scores = matcher.scores(point)["logistic_reranker"]
        for rank, idx in enumerate(matcher.order(scores)[:top_k], 1):
            rows.append({"id": f'{point["id"]}::{rank}', "datapoint_id": point["id"],
                         "concept_id": matcher.concepts[idx], "rank": rank,
                         "confidence": round(float(scores[idx]), 8), "status": "pending_review"})
    return rows


def write_json(path: Path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n")


def main():
    root = Path(__file__).resolve().parents[3]  # app/
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ig3", type=Path, default=root / "web/data/esrs_datapoints_ig3.json")
    parser.add_argument("--concept-map", type=Path, default=root / "web/data/atomizer_xbrl_concepts_v1.json")
    args = parser.parse_args()
    known, unknown, concepts = load_inputs(args.ig3, args.concept_map)
    cv = evaluate(known, concepts)
    dr_reuse = evaluate_dr_reuse(known, concepts)
    provenance = {"ig3_sha256": hashlib.sha256(args.ig3.read_bytes()).hexdigest(),
                  "concept_map_sha256": hashlib.sha256(args.concept_map.read_bytes()).hexdigest()}
    report = {"model_version": MODEL_VERSION, "run_date": datetime.now(timezone.utc).date().isoformat(), "seed": SEED,
              "sklearn_version": sklearn.__version__, "numpy_version": np.__version__,
              "scipy_version": scipy.__version__, "python_version": platform.python_version(),
              "source": provenance,
              "parameters": {"word_ngrams": [1, 2], "char_wb_ngrams": [3, 5],
                             "sublinear_tf": True, "hard_negatives": 20, "random_negatives": 20,
                             "logistic_C": 1.0, "logistic_solver": "lbfgs",
                             "learned_features": [FEATURES[i] for i in LEARNED_FEATURE_INDICES],
                             "reranker_dr_logodds_prior": 0.25, "reranker_standard_logodds_prior": 0.10},
              "counts": {"known_mappings": len(known), "unmapped_datapoints": len(unknown), "candidate_concepts": len(concepts)},
              "features": FEATURES, "evaluation": cv, "dr_reuse_evaluation": dr_reuse,
              "limits": ["El mapa estático es una referencia indirecta, sin validación contra la taxonomía externa.",
                         "No hay etiquetas oficiales ni campo de descripción: se usan nombres locales de QName y nombres IG-3.",
                         "El baseline DR se compara por separado en reutilización de conceptos con asociación DR conocida; cobertura y exclusiones explícitas. No mide descubrimiento de conceptos nuevos.",
                         "La confianza es una probabilidad de par sin calibrar, ajustada por prior y negativos muestreados; no mide la corrección del concepto.",
                         "No se validan datatype, periodType, dimensiones, presentación oficial ni Arelle. Se exige revisión humana.",
                         "Evaluación exploratoria de desarrollo, sin conjunto de evaluación independiente reservado."]}
    target = root / "ai-service/artifacts/xbrl-concept-mapping-eval"
    write_json(target.with_suffix(".json"), report)
    lines = [f"# Evaluación offline de sugerencias de conceptos XBRL ({report['run_date']})", "", "Resultados ejecutados contra el mapa estático existente; no validan la taxonomía externa.", "",
             f"Correspondencias conocidas: {len(known)}. Sin mapa: {len(unknown)}. Conceptos candidatos: {len(concepts)}.", "",
             "Validación cruzada agrupada por QName, con entrenamiento y prueba sin conceptos compartidos.", "",
             "| Modelo | Top-1 | Top-5 | MRR |", "|---|---:|---:|---:|"]
    for name, metrics in cv["metrics"].items():
        lines.append(f'| {name} | {metrics["top_1"]:.6f} | {metrics["top_5"]:.6f} | {metrics["mrr"]:.6f} |')
    lines += ["", "## Comparación separada: reutilización de conceptos por DR", "",
              f"Consultas aplicables: {dr_reuse['applicable_count']}/{len(known)}. Excluidas: {dr_reuse['excluded_count']}.",
              "Agrupación por texto normalizado. Ninguna asociación del indicador retenido entra en entrenamiento.",
              "Todos los modelos se comparan sobre el mismo subconjunto aplicable; no es descubrimiento de conceptos nuevos.", "",
              "| Modelo | Top-1 | Top-5 | MRR |", "|---|---:|---:|---:|"]
    for name, metrics in dr_reuse["metrics"].items():
        lines.append(f'| {name} | {metrics["top_1"]:.6f} | {metrics["top_5"]:.6f} | {metrics["mrr"]:.6f} |')
    lines += ["", "## Límites", ""] + [f"- {v}" for v in report["limits"]]
    target.with_suffix(".md").write_text("\n".join(lines) + "\n")
    matcher = Matcher(concepts, known).fit(known)
    write_json(root / "web/data/xbrl_concept_suggestions_v1.json", {
        "schema_version": 1, "model_version": MODEL_VERSION, "generated_on": report["run_date"],
        "source": provenance, "evaluation_metrics": cv["metrics"],
        "dr_reuse_metrics": dr_reuse["metrics"],
        "confidence_kind": "uncalibrated_prior_adjusted_sampled_pair_probability",
        "suggestions": suggestions(matcher, unknown)})
    print(json.dumps({"counts": report["counts"], "metrics": cv["metrics"],
                      "dr_reuse": {k: v for k, v in dr_reuse.items() if k not in ("assignments", "folds")}}, indent=2))


if __name__ == "__main__":
    main()
