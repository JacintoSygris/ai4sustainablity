"""Per-label decision thresholds for materiality suggestion models.

Each label can use its own decision threshold so model scores are converted to
candidate topics consistently across labels. Thresholds are selected from a
fixed grid, optionally constrained by a precision floor, with a neutral fallback
for labels that cannot be scored from the provided data.
"""
from __future__ import annotations

import json
from pathlib import Path

import numpy as np

DEFAULT_PRECISION_FLOOR = 0.0
DEFAULT_THRESHOLD = 0.5
CANDIDATE_THRESHOLDS = [round(t, 2) for t in np.arange(0.05, 1.0, 0.05)]


def pick_per_label_thresholds(
    y_true,
    probabilities,
    label_keys: list[str],
    precision_floor: float = DEFAULT_PRECISION_FLOOR,
    default_threshold: float = DEFAULT_THRESHOLD,
) -> dict[str, float]:
    y_true = np.asarray(y_true)
    probabilities = np.asarray(probabilities)
    thresholds: dict[str, float] = {}
    for index, key in enumerate(label_keys):
        actual = y_true[:, index]
        scores = probabilities[:, index]
        if actual.sum() == 0:
            thresholds[key] = default_threshold
            continue
        best_f1 = 0.0
        best_threshold = default_threshold
        for candidate in CANDIDATE_THRESHOLDS:
            predicted = (scores >= candidate).astype(int)
            tp = int(((predicted == 1) & (actual == 1)).sum())
            fp = int(((predicted == 1) & (actual == 0)).sum())
            fn = int(((predicted == 0) & (actual == 1)).sum())
            if tp + fp == 0:
                continue
            precision = tp / (tp + fp)
            if precision < precision_floor:
                continue
            recall = tp / (tp + fn) if (tp + fn) else 0.0
            f1 = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0
            if f1 > best_f1:
                best_f1 = f1
                best_threshold = candidate
        thresholds[key] = best_threshold
    return thresholds


def write_label_thresholds(thresholds: dict[str, float], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(thresholds, ensure_ascii=False, indent=2, sort_keys=True),
        encoding="utf-8",
    )


def load_label_thresholds(model_dir: Path) -> dict[str, float] | None:
    path = model_dir / "label_thresholds.json"
    if not path.exists():
        return None
    try:
        loaded = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    if not isinstance(loaded, dict):
        return None
    return {str(k): float(v) for k, v in loaded.items()}
