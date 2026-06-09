"""AR16-to-Python ESRS reconciliation helpers.

This module is intentionally read-only. It compares the project surfaces that
name ESRS/AR16 topic keys and reports drift without renaming or normalizing
keys behind the original developer's back.
"""

from __future__ import annotations

import ast
import csv
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

import yaml

from project_paths import AI_SERVICE_ROOT, resolve_web_data_file


@dataclass(frozen=True)
class Ar16RegistryEntry:
    ar16_topic_id: int
    web_esrs: str
    web_label_en: str
    web_theme_en: str
    web_subtheme_en: str | None
    web_subtopic_en: str | None
    python_esrs_keys: tuple[str, ...]
    mapping_status: str
    notes: str = ""

    def to_dict(self) -> dict[str, Any]:
        return {
            "ar16_topic_id": self.ar16_topic_id,
            "web_esrs": self.web_esrs,
            "web_label_en": self.web_label_en,
            "web_theme_en": self.web_theme_en,
            "web_subtheme_en": self.web_subtheme_en,
            "web_subtopic_en": self.web_subtopic_en,
            "python_esrs_keys": list(self.python_esrs_keys),
            "mapping_status": self.mapping_status,
            "notes": self.notes,
        }


@dataclass(frozen=True)
class YamlEsrsInventory:
    fields: list[str]
    inactive_tasks: list[str]
    non_esrs_tasks: list[str]
    ignored_context_fields: list[str]
    ignored_summary_fields: list[str]

    def to_dict(self) -> dict[str, Any]:
        return {
            "fields": self.fields,
            "inactive_tasks": self.inactive_tasks,
            "non_esrs_tasks": self.non_esrs_tasks,
            "ignored_context_fields": self.ignored_context_fields,
            "ignored_summary_fields": self.ignored_summary_fields,
        }


@dataclass(frozen=True)
class Ar16KeyEquivalence:
    source_surface: str
    source_key: str
    target_key: str | None
    status: str
    basis: str = ""

    def to_dict(self) -> dict[str, Any]:
        return {
            "source_surface": self.source_surface,
            "source_key": self.source_key,
            "target_key": self.target_key,
            "status": self.status,
            "basis": self.basis,
        }


@dataclass(frozen=True)
class ReconciliationReport:
    entries: list[Ar16RegistryEntry]
    mapping_keys: list[str]
    company_esrs_keys: list[str]
    feature_fields: list[str]
    yaml_fields: list[str]
    inactive_yaml_tasks: list[str]
    non_esrs_yaml_tasks: list[str]
    ignored_yaml_context_fields: list[str]
    ignored_yaml_summary_fields: list[str]
    company_esrs_without_mapping: list[str]
    mapping_without_company_esrs: list[str]
    feature_fields_without_mapping: list[str]
    mapping_without_feature_fields: list[str]
    yaml_fields_without_mapping: list[str]
    mapping_without_yaml_fields: list[str]
    duplicate_mapping_keys: list[str]
    resolved_equivalences: list[Ar16KeyEquivalence]
    invalid_equivalences: list[dict[str, Any]]
    pending_unmatched: dict[str, list[str]]

    def has_unmatched(self) -> bool:
        return any(self.pending_unmatched.values()) or bool(self.invalid_equivalences)

    def has_raw_unmatched(self) -> bool:
        return any(
            [
                self.company_esrs_without_mapping,
                self.mapping_without_company_esrs,
                self.feature_fields_without_mapping,
                self.mapping_without_feature_fields,
                self.yaml_fields_without_mapping,
                self.mapping_without_yaml_fields,
                self.duplicate_mapping_keys,
            ]
        )

    def to_dict(self) -> dict[str, Any]:
        return {
            "summary": {
                "mapping_entries": len(self.entries),
                "mapping_keys": len(self.mapping_keys),
                "company_esrs_keys": len(self.company_esrs_keys),
                "feature_fields": len(self.feature_fields),
                "yaml_fields": len(self.yaml_fields),
                "has_unmatched": self.has_unmatched(),
                "has_raw_unmatched": self.has_raw_unmatched(),
                "resolved_equivalences": len(self.resolved_equivalences),
                "invalid_equivalences": len(self.invalid_equivalences),
            },
            "entries": [entry.to_dict() for entry in self.entries],
            "inventories": {
                "mapping_keys": self.mapping_keys,
                "company_esrs_keys": self.company_esrs_keys,
                "feature_fields": self.feature_fields,
                "yaml_fields": self.yaml_fields,
                "inactive_yaml_tasks": self.inactive_yaml_tasks,
                "non_esrs_yaml_tasks": self.non_esrs_yaml_tasks,
                "ignored_yaml_context_fields": self.ignored_yaml_context_fields,
                "ignored_yaml_summary_fields": self.ignored_yaml_summary_fields,
            },
            "unmatched": {
                "company_esrs_without_mapping": self.company_esrs_without_mapping,
                "mapping_without_company_esrs": self.mapping_without_company_esrs,
                "feature_fields_without_mapping": self.feature_fields_without_mapping,
                "mapping_without_feature_fields": self.mapping_without_feature_fields,
                "yaml_fields_without_mapping": self.yaml_fields_without_mapping,
                "mapping_without_yaml_fields": self.mapping_without_yaml_fields,
                "duplicate_mapping_keys": self.duplicate_mapping_keys,
            },
            "resolved_by_equivalence": [
                equivalence.to_dict() for equivalence in self.resolved_equivalences
            ],
            "invalid_equivalences": self.invalid_equivalences,
            "pending_unmatched": self.pending_unmatched,
        }


def default_paths(project_root: Path | None = None) -> dict[str, Path]:
    if project_root is None:
        ai_service_root = AI_SERVICE_ROOT
        mapping_path = resolve_web_data_file("ar16_to_python_esrs_mapping.json")
    else:
        ai_service_root = project_root / "app" / "ai-service"
        mapping_path = project_root / "app" / "web" / "data" / "ar16_to_python_esrs_mapping.json"

    return {
        "mapping_path": mapping_path,
        "company_esrs_csv_path": ai_service_root / "data" / "company_esrs.csv",
        "feature_description_path": ai_service_root / "src" / "features" / "feature_description.py",
        "yaml_models_dir": ai_service_root / "extraction_models",
        "key_equivalences_path": ai_service_root / "data" / "ar16_key_equivalences.json",
    }


def build_reconciliation_report(
    *,
    mapping_path: Path,
    company_esrs_csv_path: Path,
    feature_description_path: Path,
    yaml_models_dir: Path,
    key_equivalences_path: Path | None = None,
) -> ReconciliationReport:
    entries = load_ar16_mapping(mapping_path)
    mapping_keys = _unique_preserve_order(key for entry in entries for key in entry.python_esrs_keys)
    company_esrs_keys = load_company_esrs_keys(company_esrs_csv_path)
    feature_fields = load_feature_esrs_fields(feature_description_path)
    yaml_inventory = inventory_yaml_esrs_fields(yaml_models_dir)
    duplicate_mapping_keys = _duplicates(key for entry in entries for key in entry.python_esrs_keys)
    raw_unmatched = {
        "company_esrs_without_mapping": _sorted_difference(company_esrs_keys, mapping_keys),
        "mapping_without_company_esrs": _sorted_difference(mapping_keys, company_esrs_keys),
        "feature_fields_without_mapping": _sorted_difference(feature_fields, mapping_keys),
        "mapping_without_feature_fields": _sorted_difference(mapping_keys, feature_fields),
        "yaml_fields_without_mapping": _sorted_difference(yaml_inventory.fields, mapping_keys),
        "mapping_without_yaml_fields": _sorted_difference(mapping_keys, yaml_inventory.fields),
        "duplicate_mapping_keys": duplicate_mapping_keys,
    }
    equivalences = load_key_equivalences(key_equivalences_path)
    equivalence_result = _apply_key_equivalences(
        equivalences=equivalences,
        mapping_keys=mapping_keys,
        raw_unmatched=raw_unmatched,
    )

    return ReconciliationReport(
        entries=entries,
        mapping_keys=mapping_keys,
        company_esrs_keys=company_esrs_keys,
        feature_fields=feature_fields,
        yaml_fields=yaml_inventory.fields,
        inactive_yaml_tasks=yaml_inventory.inactive_tasks,
        non_esrs_yaml_tasks=yaml_inventory.non_esrs_tasks,
        ignored_yaml_context_fields=yaml_inventory.ignored_context_fields,
        ignored_yaml_summary_fields=yaml_inventory.ignored_summary_fields,
        company_esrs_without_mapping=raw_unmatched["company_esrs_without_mapping"],
        mapping_without_company_esrs=raw_unmatched["mapping_without_company_esrs"],
        feature_fields_without_mapping=raw_unmatched["feature_fields_without_mapping"],
        mapping_without_feature_fields=raw_unmatched["mapping_without_feature_fields"],
        yaml_fields_without_mapping=raw_unmatched["yaml_fields_without_mapping"],
        mapping_without_yaml_fields=raw_unmatched["mapping_without_yaml_fields"],
        duplicate_mapping_keys=raw_unmatched["duplicate_mapping_keys"],
        resolved_equivalences=equivalence_result["resolved_equivalences"],
        invalid_equivalences=equivalence_result["invalid_equivalences"],
        pending_unmatched=equivalence_result["pending_unmatched"],
    )


def load_ar16_mapping(mapping_path: Path) -> list[Ar16RegistryEntry]:
    payload = json.loads(mapping_path.read_text(encoding="utf-8"))
    entries: list[Ar16RegistryEntry] = []

    for raw_entry in payload.get("candidate_topics", []):
        entries.append(
            Ar16RegistryEntry(
                ar16_topic_id=int(raw_entry["ar16_topic_id"]),
                web_esrs=str(raw_entry.get("web_esrs", "")),
                web_label_en=str(raw_entry.get("web_label_en", "")),
                web_theme_en=str(raw_entry.get("web_theme_en", "")),
                web_subtheme_en=raw_entry.get("web_subtheme_en"),
                web_subtopic_en=raw_entry.get("web_subtopic_en"),
                python_esrs_keys=tuple(raw_entry.get("python_esrs_keys", [])),
                mapping_status=str(raw_entry.get("mapping_status", "")),
                notes=str(raw_entry.get("notes", "")),
            )
        )

    return entries


def load_key_equivalences(key_equivalences_path: Path | None) -> list[Ar16KeyEquivalence]:
    if key_equivalences_path is None or not key_equivalences_path.exists():
        return []

    payload = json.loads(key_equivalences_path.read_text(encoding="utf-8"))
    equivalences: list[Ar16KeyEquivalence] = []
    for raw_equivalence in payload.get("equivalences", []):
        equivalences.append(
            Ar16KeyEquivalence(
                source_surface=str(raw_equivalence.get("source_surface", "")),
                source_key=str(raw_equivalence.get("source_key", "")),
                target_key=raw_equivalence.get("target_key"),
                status=str(raw_equivalence.get("status", "")),
                basis=str(raw_equivalence.get("basis", "")),
            )
        )

    return equivalences


def load_company_esrs_keys(company_esrs_csv_path: Path) -> list[str]:
    with company_esrs_csv_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter=";")
        header = next(reader)

    return [cell.strip() for cell in header if _is_primary_esrs_key(cell.strip())]


def load_feature_esrs_fields(feature_description_path: Path) -> list[str]:
    tree = ast.parse(feature_description_path.read_text(encoding="utf-8"))
    fields: list[str] = []

    for node in tree.body:
        if not isinstance(node, ast.ClassDef) or not node.name.startswith("ESRS_"):
            continue
        for statement in node.body:
            if isinstance(statement, ast.AnnAssign) and isinstance(statement.target, ast.Name):
                field_name = statement.target.id
                if _is_primary_esrs_key(field_name):
                    fields.append(field_name)

    return _unique_preserve_order(fields)


def inventory_yaml_esrs_fields(yaml_models_dir: Path) -> YamlEsrsInventory:
    fields: list[str] = []
    inactive_tasks: list[str] = []
    non_esrs_tasks: list[str] = []
    ignored_context_fields: list[str] = []
    ignored_summary_fields: list[str] = []

    for path in sorted(yaml_models_dir.glob("*.yaml")):
        payload = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
        task = payload.get("task", {})
        task_name = _yaml_name(task.get("name", path.stem))

        if not task.get("active", True):
            inactive_tasks.append(task_name)
            continue
        if not task.get("isESR", True):
            non_esrs_tasks.append(task_name)
            continue
        if task.get("summaryField", True):
            ignored_summary_fields.append(f"{task_name}_summary")

        for theme in task.get("fields", task.get("themes", [])):
            _collect_yaml_theme_fields(
                task_name,
                theme,
                fields=fields,
                ignored_context_fields=ignored_context_fields,
            )

    return YamlEsrsInventory(
        fields=_unique_preserve_order(fields),
        inactive_tasks=inactive_tasks,
        non_esrs_tasks=non_esrs_tasks,
        ignored_context_fields=_unique_preserve_order(ignored_context_fields),
        ignored_summary_fields=_unique_preserve_order(ignored_summary_fields),
    )


def write_report(report: ReconciliationReport, output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(report.to_dict(), indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )


_SURFACE_UNMATCHED_KEYS = {
    "company_esrs": ("company_esrs_without_mapping", "mapping_without_company_esrs"),
    "feature_description": ("feature_fields_without_mapping", "mapping_without_feature_fields"),
    "yaml": ("yaml_fields_without_mapping", "mapping_without_yaml_fields"),
}

_KNOWN_NON_AR16_STATUSES = {
    "known_non_ar16",
    "known_non_ar16_summary",
    "review_only_non_ar16",
}


def _apply_key_equivalences(
    *,
    equivalences: list[Ar16KeyEquivalence],
    mapping_keys: list[str],
    raw_unmatched: dict[str, list[str]],
) -> dict[str, Any]:
    pending_unmatched = {name: list(values) for name, values in raw_unmatched.items()}
    resolved_equivalences: list[Ar16KeyEquivalence] = []
    invalid_equivalences: list[dict[str, Any]] = []
    seen_sources: set[tuple[str, str]] = set()
    mapping_key_set = set(mapping_keys)

    for equivalence in equivalences:
        surface_keys = _SURFACE_UNMATCHED_KEYS.get(equivalence.source_surface)
        source_identity = (equivalence.source_surface, equivalence.source_key)
        if source_identity in seen_sources:
            invalid_equivalences.append(
                {
                    **equivalence.to_dict(),
                    "reason": "duplicate_source_surface_and_key",
                }
            )
            continue
        seen_sources.add(source_identity)

        if surface_keys is None:
            invalid_equivalences.append(
                {
                    **equivalence.to_dict(),
                    "reason": "unknown_source_surface",
                }
            )
            continue

        left_key, right_key = surface_keys
        if equivalence.status == "equivalent":
            if not equivalence.target_key:
                invalid_equivalences.append(
                    {
                        **equivalence.to_dict(),
                        "reason": "equivalent_status_requires_target_key",
                    }
                )
                continue
            if equivalence.target_key not in mapping_key_set:
                invalid_equivalences.append(
                    {
                        **equivalence.to_dict(),
                        "reason": "target_key_not_in_ar16_mapping",
                    }
                )
                continue
            _remove_if_present(pending_unmatched[left_key], equivalence.source_key)
            _remove_if_present(pending_unmatched[right_key], equivalence.target_key)
            resolved_equivalences.append(equivalence)
            continue

        if equivalence.status in _KNOWN_NON_AR16_STATUSES:
            _remove_if_present(pending_unmatched[left_key], equivalence.source_key)
            resolved_equivalences.append(equivalence)
            continue

        invalid_equivalences.append(
            {
                **equivalence.to_dict(),
                "reason": "unknown_status",
            }
        )

    return {
        "pending_unmatched": pending_unmatched,
        "resolved_equivalences": resolved_equivalences,
        "invalid_equivalences": invalid_equivalences,
    }


def _remove_if_present(values: list[str], value: str) -> None:
    try:
        values.remove(value)
    except ValueError:
        pass


def _collect_yaml_theme_fields(
    task_name: str,
    theme: dict[str, Any],
    *,
    fields: list[str],
    ignored_context_fields: list[str],
    parent_name: str | None = None,
) -> None:
    theme_name = _yaml_name(theme.get("name", ""))
    full_theme_name = f"{parent_name}_{theme_name}" if parent_name else theme_name
    subthemes = theme.get("subthemes") or []

    if subthemes:
        for subtheme in subthemes:
            _collect_yaml_theme_fields(
                task_name,
                subtheme,
                fields=fields,
                ignored_context_fields=ignored_context_fields,
                parent_name=full_theme_name,
            )
        return

    field_name = f"{task_name}_{full_theme_name}"
    fields.append(field_name)
    if theme.get("extractContext", False):
        ignored_context_fields.append(f"{field_name}_context")


def _is_primary_esrs_key(value: str) -> bool:
    return value.startswith("esrs_") and not value.endswith("_context")


def _yaml_name(value: Any) -> str:
    return str(value).replace(" ", "_").lower()


def _sorted_difference(left: Iterable[str], right: Iterable[str]) -> list[str]:
    return sorted(set(left) - set(right))


def _unique_preserve_order(values: Iterable[str]) -> list[str]:
    seen: set[str] = set()
    unique_values: list[str] = []
    for value in values:
        if value not in seen:
            seen.add(value)
            unique_values.append(value)
    return unique_values


def _duplicates(values: Iterable[str]) -> list[str]:
    seen: set[str] = set()
    duplicates: set[str] = set()
    for value in values:
        if value in seen:
            duplicates.add(value)
        seen.add(value)
    return sorted(duplicates)
