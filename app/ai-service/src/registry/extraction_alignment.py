"""Align offline extraction CSVs to approved AR16/Python ESRS keys.

This module is post-processing only. It does not call LLMs, does not retrain,
and does not write the binary training CSV. Evidence/context and review flags
are written to a separate trace CSV.
"""

from __future__ import annotations

import csv
from pathlib import Path
from typing import Any

from registry.ar16 import (
    Ar16KeyEquivalence,
    default_paths,
    load_ar16_mapping,
    load_key_equivalences,
)


TRACE_FIELDS = [
    "file",
    "source_key",
    "target_key",
    "status",
    "review_required",
    "source_value",
    "source_context_key",
    "source_context_value",
    "basis",
]

_NON_AR16_STATUSES = {
    "known_non_ar16",
    "known_non_ar16_summary",
    "review_only_non_ar16",
}


def align_extraction_csv(
    *,
    input_path: Path,
    output_path: Path,
    trace_output_path: Path,
    mapping_path: Path | None = None,
    key_equivalences_path: Path | None = None,
    delimiter: str = ";",
) -> dict[str, Any]:
    paths = default_paths()
    mapping_path = mapping_path or paths["mapping_path"]
    key_equivalences_path = key_equivalences_path or paths["key_equivalences_path"]

    mapping_keys = _mapping_keys(mapping_path)
    equivalences = _yaml_equivalence_index(load_key_equivalences(key_equivalences_path))

    with input_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle, delimiter=delimiter)
        if not reader.fieldnames or "file" not in reader.fieldnames:
            raise ValueError("Extraction CSV must include a 'file' column.")
        input_rows = list(reader)
        fieldnames = list(reader.fieldnames)

    aligned_rows: list[dict[str, str]] = []
    trace_rows: list[dict[str, str]] = []
    used_targets: set[str] = set()
    review_required_count = 0

    for row in input_rows:
        aligned_row: dict[str, str] = {"file": row["file"]}
        for source_key in fieldnames:
            if source_key == "file" or source_key.endswith("_context"):
                continue

            source_value = row.get(source_key, "")
            decision = _classify_source_key(source_key, mapping_keys, equivalences)
            target_key = decision["target_key"]
            review_required = decision["review_required"]
            context_key = f"{source_key}_context"
            context_value = row.get(context_key, "") if context_key in row else ""

            if target_key:
                if target_key in aligned_row and aligned_row[target_key] and source_value:
                    decision = {
                        **decision,
                        "status": "duplicate_target",
                        "review_required": True,
                        "basis": (
                            "Another source column already populated this AR16/Python target; "
                            "kept the first value and flagged this one for review."
                        ),
                    }
                    review_required = True
                else:
                    aligned_row[target_key] = source_value
                    used_targets.add(target_key)
            if review_required:
                review_required_count += 1

            trace_rows.append(
                {
                    "file": row["file"],
                    "source_key": source_key,
                    "target_key": target_key or "",
                    "status": decision["status"],
                    "review_required": "true" if review_required else "false",
                    "source_value": source_value,
                    "source_context_key": context_key if context_value else "",
                    "source_context_value": context_value,
                    "basis": decision["basis"],
                }
            )
        aligned_rows.append(aligned_row)

    ordered_targets = [key for key in mapping_keys if key in used_targets]
    _write_dict_rows(output_path, ["file", *ordered_targets], aligned_rows, delimiter)
    _write_dict_rows(trace_output_path, TRACE_FIELDS, trace_rows, delimiter)

    return {
        "rows": len(aligned_rows),
        "aligned_targets": len(ordered_targets),
        "trace_rows": len(trace_rows),
        "review_required": review_required_count,
    }


def _mapping_keys(mapping_path: Path) -> list[str]:
    keys: list[str] = []
    for entry in load_ar16_mapping(mapping_path):
        for key in entry.python_esrs_keys:
            if key not in keys:
                keys.append(key)
    return keys


def _yaml_equivalence_index(
    equivalences: list[Ar16KeyEquivalence],
) -> dict[str, Ar16KeyEquivalence]:
    return {
        equivalence.source_key: equivalence
        for equivalence in equivalences
        if equivalence.source_surface == "yaml"
    }


def _classify_source_key(
    source_key: str,
    mapping_keys: list[str],
    equivalences: dict[str, Ar16KeyEquivalence],
) -> dict[str, Any]:
    if source_key.endswith("_summary"):
        return {
            "target_key": None,
            "status": "ignored_summary",
            "review_required": False,
            "basis": "YAML summary flag, kept out of AR16-aligned value CSV.",
        }
    if source_key in mapping_keys:
        return {
            "target_key": source_key,
            "status": "direct",
            "review_required": False,
            "basis": "Source key already matches approved AR16/Python key.",
        }

    equivalence = equivalences.get(source_key)
    if equivalence is not None:
        if equivalence.status == "equivalent":
            return {
                "target_key": equivalence.target_key,
                "status": "equivalent",
                "review_required": False,
                "basis": equivalence.basis,
            }
        if equivalence.status in _NON_AR16_STATUSES:
            return {
                "target_key": None,
                "status": equivalence.status,
                "review_required": False,
                "basis": equivalence.basis,
            }
        return {
            "target_key": equivalence.target_key,
            "status": f"invalid_equivalence_status:{equivalence.status}",
            "review_required": True,
            "basis": equivalence.basis,
        }

    if source_key.startswith("esrs_"):
        return {
            "target_key": None,
            "status": "unknown_esrs_key",
            "review_required": True,
            "basis": "No direct AR16/Python key or explicit YAML equivalence.",
        }

    return {
        "target_key": None,
        "status": "ignored_non_esrs",
        "review_required": False,
        "basis": "Non-ESRS column.",
    }


def _write_dict_rows(
    output_path: Path,
    fieldnames: list[str],
    rows: list[dict[str, str]],
    delimiter: str,
) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            delimiter=delimiter,
            fieldnames=fieldnames,
            extrasaction="ignore",
        )
        writer.writeheader()
        writer.writerows(rows)
