"""Unit-explicit helpers for the offline Green Tech benchmark (stdlib only)."""

import math
import statistics


def nonnegative(value, name):
    if not math.isfinite(value) or value < 0:
        raise ValueError(f"{name} must be finite and nonnegative")
    return value


def estimated_energy(cpu_seconds, wall_seconds, tdp_w, physical_cores, memory_gb, pue):
    """Green Algorithms-inspired CPU allocation + declared RAM allocation.

    CPU seconds sum all process threads. Cap at physical capacity because SMT
    threads share cores; TDP is a model input, not measured electrical power.
    The RAM coefficient is Green Algorithms' 0.3725 W/GB.
    """
    for name, value in locals().copy().items():
        nonnegative(value, name)
    if physical_cores <= 0 or tdp_w <= 0 or pue < 1:
        raise ValueError("positive cores/TDP and PUE >= 1 required")
    effective_cpu_seconds = min(cpu_seconds, wall_seconds * physical_cores)
    cpu_kwh = effective_cpu_seconds * tdp_w / physical_cores / 3_600_000
    memory_kwh = wall_seconds * memory_gb * 0.3725 / 3_600_000
    return {"cpu_kwh": cpu_kwh, "memory_kwh": memory_kwh,
            "facility_kwh": (cpu_kwh + memory_kwh) * pue,
            "effective_cpu_seconds": effective_cpu_seconds}


def emissions_g(energy_kwh, grid_g_per_kwh):
    return nonnegative(energy_kwh, "energy") * nonnegative(grid_g_per_kwh, "grid")


def counter_delta_uj(before, after, maximum):
    if maximum <= 0 or not 0 <= before < maximum or not 0 <= after < maximum:
        raise ValueError("invalid RAPL counter")
    return (after - before) % maximum


def summary(values):
    values = list(values)
    if len(values) < 2:
        raise ValueError("at least two repetitions required for sample SD")
    for value in values:
        nonnegative(value, "sample")
    return {"n": len(values), "mean": statistics.mean(values),
            "sd": statistics.stdev(values)}


def comparison(candidate, baseline):
    nonnegative(candidate, "candidate")
    nonnegative(baseline, "baseline")
    if baseline == 0:
        raise ValueError("baseline must be positive")
    ratio = candidate / baseline
    return {"candidate_over_baseline": ratio, "reduction_percent": 100 * (1 - ratio),
            "at_least_50_percent_in_this_scope": ratio <= 0.5,
            "supports_traditional_development_comparison": False}


def literature_llm_kwh(output_tokens):
    """Indicative linear scaling of Epoch AI's 0.3 Wh / 500 output tokens.

    Short-input GPT-4o scenario only; includes source's infrastructure overhead.
    It is neither a measured API cost nor a GPT-4.1 extraction estimate.
    """
    return nonnegative(output_tokens, "output_tokens") * (0.3 / 500) / 1000
