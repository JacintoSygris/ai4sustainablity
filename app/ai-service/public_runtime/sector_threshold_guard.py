"""Optional sector-conditioned guard for candidate-topic suppression.

The guard suppresses configured ESRS families only when the supplied sectors
have base rates at or below the configured cutoff. Unknown sectors do not
suppress candidates, and the guard never adds candidates.

Default OFF: `load_sector_guard()` returns None unless
`ESRS_SECTOR_THRESHOLD_GUARD_PATH` points at a valid config file.
Invalid or partial config fails closed to OFF.

Config JSON shape:
    {"version": "...", "cutoff": 0.03,
     "families": ["esrs_e2", "esrs_e3", "esrs_e4", "esrs_s2"],
     "base_rates": {"<sector>": {"esrs_e2": 0.20, ...}, ...}}
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path

GUARD_PATH_ENV = "ESRS_SECTOR_THRESHOLD_GUARD_PATH"


def standard_of(key: str) -> str:
    """esrs_e2_pollution_air -> esrs_e2 ; esrs_s2_... -> esrs_s2."""
    return "_".join(key.split("_")[:2])


@dataclass(frozen=True)
class SectorThresholdGuard:
    cutoff: float
    families: tuple[str, ...]
    base_rates: dict[str, dict[str, float]]
    version: str = "unversioned"

    def suppresses(self, key: str, sectors) -> bool:
        std = standard_of(key)
        if std not in self.families:
            return False
        rates = [
            self.base_rates[s][std]
            for s in (sectors or [])
            if s in self.base_rates and std in self.base_rates[s]
        ]
        if not rates:
            return False
        return max(rates) <= self.cutoff

    def filter_keys(self, keys, sectors) -> tuple[list[str], list[str]]:
        """Return (kept_keys, suppressed_keys), order-preserving."""
        kept, suppressed = [], []
        for key in keys:
            (suppressed if self.suppresses(key, sectors) else kept).append(key)
        return kept, suppressed


def build_guard_from_config(config: dict) -> SectorThresholdGuard | None:
    """Validate a config dict; return a guard, or None if it is unusable (fails closed to OFF)."""
    if not isinstance(config, dict):
        return None
    try:
        cutoff = float(config["cutoff"])
        families = tuple(str(f) for f in config["families"])
        raw_rates = config["base_rates"]
    except (KeyError, TypeError, ValueError):
        return None
    if not (0.0 <= cutoff <= 1.0) or not families or not isinstance(raw_rates, dict):
        return None
    base_rates: dict[str, dict[str, float]] = {}
    for sector, by_std in raw_rates.items():
        if not isinstance(by_std, dict):
            return None
        clean: dict[str, float] = {}
        for std, rate in by_std.items():
            try:
                clean[str(std)] = float(rate)
            except (TypeError, ValueError):
                return None
        base_rates[str(sector)] = clean
    return SectorThresholdGuard(
        cutoff=cutoff, families=families, base_rates=base_rates,
        version=str(config.get("version", "unversioned")),
    )


def load_sector_guard(env_var: str = GUARD_PATH_ENV) -> SectorThresholdGuard | None:
    """Load the guard from `env_var`; return None when unset, missing, or invalid."""
    path = os.getenv(env_var)
    if not path or not path.strip():
        return None
    config_path = Path(path.strip())
    if not config_path.is_file():
        return None
    try:
        config = json.loads(config_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return build_guard_from_config(config)
