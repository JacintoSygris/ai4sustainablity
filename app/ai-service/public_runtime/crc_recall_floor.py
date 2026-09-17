"""CRC recall-floor serving lever for the new-format materiality classifier.

Optional standard-level floor for the public serving profile. A standard's
score is the MAX positive probability over its esrs_* columns, including
_summary roll-ups, and the serving rule is "emit every standard whose score is
at least lambda". For the bundled gpt41 profile, the reference lambda is 0.13
with alpha metadata of 0.02.

Runtime translation: serving emits CHILD keys, so the floor AUGMENTS the base
emission — for every standard passing lambda that has no emitted child key, it
adds the standard's highest-scoring candidate child key. It NEVER removes keys,
so the configured standard-level serving set is a subset of what is served and
the recall guarantee carries over.

DEFAULT OFF: `load_crc_recall_floor(profile)` returns None unless
`ESRS_CRC_RECALL_FLOOR_PATH` points at a valid config whose `profile` matches
the profile being served (a lambda selected for one model must never apply to
another). Invalid/partial config fails closed to OFF. Reversible: unset the
env var.

Config JSON shape:
    {"version": "...", "profile": "new_format_732_v1_gpt41",
     "lambda": 0.13, "alpha": 0.02, "source": "crc-floor-config.json"}
"""
from __future__ import annotations

import json
import os
import re
from dataclasses import dataclass
from pathlib import Path

CRC_PATH_ENV = "ESRS_CRC_RECALL_FLOOR_PATH"

# Standard score maxes over every esrs_<std>_* column, _summary roll-ups included.
_STD_RE = re.compile(r"^esrs_(e[1-5]|s[1-4]|g1)_", re.IGNORECASE)


def _standard_of(column: str) -> str | None:
    match = _STD_RE.match(column)
    if not match:
        return None
    return f"esrs_{match.group(1).lower()}"


@dataclass(frozen=True)
class CrcRecallFloor:
    lambda_: float
    profile: str
    version: str = "unversioned"
    alpha: float | None = None

    def served_standards(self, esrs_columns, scores) -> set[str]:
        """Standards whose max column score reaches lambda."""
        best: dict[str, float] = {}
        for column, score in zip(esrs_columns, scores):
            standard = _standard_of(column)
            if standard is None:
                continue
            best[standard] = max(best.get(standard, 0.0), float(score))
        return {standard for standard, score in best.items() if score >= self.lambda_}

    def augment_keys(self, emitted_keys, esrs_columns, scores, candidate_predicate) -> list[str]:
        """Added child keys so every served standard has at least one emitted key.

        For each standard passing lambda with no emitted key, adds the standard's
        highest-scoring column accepted by candidate_predicate (the added key's own
        score may be below lambda when the standard passed via a _summary roll-up).
        Never removes or reorders emitted_keys; returns additions sorted by
        (-score, key) for determinism.
        """
        covered = {_standard_of(key) for key in emitted_keys}
        additions: list[tuple[float, str]] = []
        for standard in self.served_standards(esrs_columns, scores) - covered:
            best_key, best_score = None, -1.0
            for column, score in zip(esrs_columns, scores):
                if _standard_of(column) != standard or not candidate_predicate(column):
                    continue
                if float(score) > best_score or (float(score) == best_score and column < best_key):
                    best_key, best_score = column, float(score)
            if best_key is not None:
                additions.append((best_score, best_key))
        additions.sort(key=lambda item: (-item[0], item[1]))
        return [key for _score, key in additions]


def build_crc_floor_from_config(config) -> CrcRecallFloor | None:
    """Build a floor from a parsed config dict; any invalid shape fails closed to None."""
    if not isinstance(config, dict):
        return None
    profile = config.get("profile")
    raw_lambda = config.get("lambda")
    if not isinstance(profile, str) or not profile.strip():
        return None
    if isinstance(raw_lambda, bool) or not isinstance(raw_lambda, (int, float)):
        return None
    lambda_ = float(raw_lambda)
    if not (0.0 <= lambda_ < 1.0):
        return None
    alpha = config.get("alpha")
    alpha = float(alpha) if isinstance(alpha, (int, float)) and not isinstance(alpha, bool) else None
    version = config.get("version")
    version = version if isinstance(version, str) and version.strip() else "unversioned"
    return CrcRecallFloor(lambda_=lambda_, profile=profile, version=version, alpha=alpha)


def load_crc_recall_floor(profile_name: str) -> CrcRecallFloor | None:
    """Default-OFF loader: returns None unless the env points at a valid config
    whose profile matches the profile being served."""
    raw_path = os.getenv(CRC_PATH_ENV)
    if not raw_path or not raw_path.strip():
        return None
    path = Path(raw_path)
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None
    floor = build_crc_floor_from_config(config)
    if floor is None or floor.profile != profile_name:
        return None
    return floor
