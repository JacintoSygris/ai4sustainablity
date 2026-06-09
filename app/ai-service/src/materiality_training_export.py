"""Fail-closed materiality-label export for local training releases."""

from __future__ import annotations

import hashlib
import json
from collections.abc import Iterable, Mapping
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from materiality_schema import (
    MaterialitySchemaError,
    has_open_export_blocker,
    topic_resolution_allows_export_checks,
    validate_materiality_label_schema,
)


MATERIALITY_EXPORT_SCHEMA_VERSION = "materiality-v6-20260609"
GOLD_RULE_SCHEMA_VERSION = "gold-rule-v1"

TRAINING_PRIMARY_STATUSES = {
    "explicit_material",
    "explicit_not_material",
    "assessed_not_material_implicit",
}

TRAINING_REVIEW_STATUSES = {
    "human_approved",
    "gold_promoted",
}

EXPORT_LABEL_FIELDS = {
    "label_id",
    "report_id",
    "company_id",
    "company_name",
    "report_url",
    "source_file",
    "report_year",
    "python_esrs_key",
    "matched_topic_id",
    "primary_status",
    "review_status",
    "topic_resolution_method",
    "topic_resolution_state",
    "blocker_status",
    "report_blockers",
    "page_blockers",
    "topic_blockers",
    "blocker_resolution_notes",
    "blocker_exception_reason",
    "reviewer_id",
    "reviewed_at",
    "gold_rule_manifest_hash",
    "evidence_items",
}

EXPORT_EVIDENCE_FIELDS = {
    "evidence_id",
    "evidence_type",
    "evidence_strength",
    "page_number",
    "bbox",
    "structured_locator",
    "excerpt",
    "scope",
    "extractor_method",
    "source_text_trusted",
}


def build_materiality_training_release(
    *,
    labels: Iterable[Mapping[str, Any]],
    registry_entries: Iterable[Mapping[str, Any] | object],
    output_dir: Path,
    release_id: str,
    gold_rule_manifest: Mapping[str, Any] | None = None,
    run_id: str | None = None,
    created_at: str | None = None,
) -> dict[str, Any]:
    """Write an approved materiality-label release and manifest.

    Rows that do not satisfy the frozen schema, review, topic, registry, blocker,
    or evidence gates are recorded as blocked and are not exported.
    """

    output_dir.mkdir(parents=True, exist_ok=True)
    release_dir = output_dir / release_id
    release_dir.mkdir(parents=True, exist_ok=True)
    labels_path = release_dir / "materiality-labels.jsonl"
    blocked_path = release_dir / "blocked-labels.jsonl"
    manifest_path = release_dir / "manifest.json"
    gold_rule_manifest_path = release_dir / "gold-rule-manifest.json"

    labels_list = list(labels)
    registry_entries_list = list(registry_entries)
    registry_by_topic_id = {
        str(_registry_value(entry, "ar16_topic_id")): entry for entry in registry_entries_list
    }
    registry_entries_hash = _sha256_json(
        {"entries": [_registry_snapshot(entry) for entry in registry_entries_list]}
    )
    gold_rule_manifest_hash = (
        _sha256_json(gold_rule_manifest) if gold_rule_manifest is not None else None
    )
    gold_rule_manifest_error = _gold_rule_manifest_validation_error(gold_rule_manifest)
    gold_rule_manifest_sidecar_sha256 = None
    if gold_rule_manifest is not None:
        gold_rule_manifest_text = json.dumps(
            gold_rule_manifest,
            ensure_ascii=False,
            indent=2,
            sort_keys=True,
        )
        gold_rule_manifest_path.write_text(gold_rule_manifest_text, encoding="utf-8")
        gold_rule_manifest_sidecar_sha256 = _sha256_text(gold_rule_manifest_text)

    exported: list[dict[str, Any]] = []
    blocked: list[dict[str, str]] = []

    for index, label in enumerate(labels_list):
        label_id = str(label.get("label_id", f"row-{index}"))
        block_reason = _export_block_reason(
            label=label,
            registry_by_topic_id=registry_by_topic_id,
            gold_rule_manifest_hash=gold_rule_manifest_hash,
            gold_rule_manifest_error=gold_rule_manifest_error,
        )
        if block_reason is not None:
            blocked.append({"label_id": label_id, "reason": block_reason})
            continue

        exported.append(_exportable_label(label))

    labels_text = "".join(
        f"{json.dumps(row, ensure_ascii=False, sort_keys=True)}\n" for row in exported
    )
    labels_path.write_text(labels_text, encoding="utf-8")
    labels_sha256 = _sha256_text(labels_text)

    blocked_text = "".join(
        f"{json.dumps(row, ensure_ascii=False, sort_keys=True)}\n" for row in blocked
    )
    blocked_path.write_text(blocked_text, encoding="utf-8")
    blocked_sha256 = _sha256_text(blocked_text)

    manifest = {
        "release_id": release_id,
        "run_id": run_id or release_id,
        "created_at": created_at or datetime.now(timezone.utc).isoformat(),
        "schema_version": MATERIALITY_EXPORT_SCHEMA_VERSION,
        "export_schema_version": MATERIALITY_EXPORT_SCHEMA_VERSION,
        "input_label_count": len(labels_list),
        "registry_entry_count": len(registry_entries_list),
        "registry_entries_sha256": registry_entries_hash,
        "exported_count": len(exported),
        "blocked_count": len(blocked),
        "labels_sha256": labels_sha256,
        "blocked_sha256": blocked_sha256,
        "gold_rule_manifest_sha256": gold_rule_manifest_hash,
        "gold_rule_manifest_validation_error": gold_rule_manifest_error,
        "labels_path": str(labels_path),
        "blocked_path": str(blocked_path),
    }
    if gold_rule_manifest is not None:
        manifest["gold_rule_manifest_path"] = str(gold_rule_manifest_path)
        manifest["gold_rule_manifest_sidecar_sha256"] = gold_rule_manifest_sidecar_sha256
    manifest_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True),
        encoding="utf-8",
    )

    return {
        **manifest,
        "labels_path": str(labels_path),
        "blocked_path": str(blocked_path),
        "manifest_path": str(manifest_path),
        "blocked": blocked,
    }


def _export_block_reason(
    *,
    label: Mapping[str, Any],
    registry_by_topic_id: Mapping[str, Mapping[str, Any] | object],
    gold_rule_manifest_hash: str | None,
    gold_rule_manifest_error: str | None,
) -> str | None:
    try:
        validate_materiality_label_schema(label)
    except MaterialitySchemaError as exc:
        return f"schema: {exc}"

    if has_open_export_blocker(label):
        return "open blocker prevents training export"

    if label.get("primary_status") not in TRAINING_PRIMARY_STATUSES:
        return f"primary_status is not exportable for training: {label.get('primary_status')}"

    if not label.get("evidence_items"):
        return "evidence_items are required for training export"

    review_status = str(label.get("review_status", ""))
    if review_status not in TRAINING_REVIEW_STATUSES:
        return f"review_status is not approved for training export: {review_status}"

    if review_status == "gold_promoted":
        if not gold_rule_manifest_hash:
            return "gold_promoted requires gold_rule_manifest"
        if gold_rule_manifest_error:
            return f"gold_rule_manifest invalid: {gold_rule_manifest_error}"
        if label.get("gold_rule_manifest_hash") != gold_rule_manifest_hash:
            return "gold_rule_manifest_hash does not match active manifest"

    registry_entry = registry_by_topic_id.get(str(label.get("matched_topic_id")))
    if registry_entry is None:
        return "registry/topic gate blocked: no matching AR16 registry entry"

    try:
        if not topic_resolution_allows_export_checks(label, registry_entry):
            return "registry/topic gate blocked"
    except MaterialitySchemaError as exc:
        return f"registry/topic gate blocked: {exc}"

    return None


def _exportable_label(label: Mapping[str, Any]) -> dict[str, Any]:
    row = {
        key: label[key]
        for key in sorted(EXPORT_LABEL_FIELDS)
        if key in label and key != "evidence_items"
    }
    evidence_items = label.get("evidence_items", [])
    if isinstance(evidence_items, list):
        row["evidence_items"] = [
            {
                key: item[key]
                for key in sorted(EXPORT_EVIDENCE_FIELDS)
                if isinstance(item, Mapping) and key in item
            }
            for item in evidence_items
        ]
    return row


def _gold_rule_manifest_validation_error(
    gold_rule_manifest: Mapping[str, Any] | None,
) -> str | None:
    if gold_rule_manifest is None:
        return None

    required_fields = (
        "rule_id",
        "schema_version",
        "status",
        "approved_by",
        "approved_at",
        "quality",
    )
    for field in required_fields:
        if gold_rule_manifest.get(field) in (None, ""):
            return f"missing required field: {field}"

    if gold_rule_manifest["schema_version"] != GOLD_RULE_SCHEMA_VERSION:
        return f"schema_version must be {GOLD_RULE_SCHEMA_VERSION}"
    if gold_rule_manifest["status"] != "approved":
        return "status must be approved"

    quality = gold_rule_manifest["quality"]
    if not isinstance(quality, Mapping):
        return "quality must be an object"
    minimum_precision = quality.get("minimum_precision")
    if not _is_number(minimum_precision) or minimum_precision < 0.95:
        return "quality.minimum_precision must be at least 0.95"
    reviewed_label_count = quality.get("reviewed_label_count")
    if (
        not isinstance(reviewed_label_count, int)
        or isinstance(reviewed_label_count, bool)
        or reviewed_label_count <= 0
    ):
        return "quality.reviewed_label_count must be a positive integer"

    return None


def _registry_snapshot(registry_entry: Mapping[str, Any] | object) -> dict[str, Any]:
    if isinstance(registry_entry, Mapping):
        return {
            key: registry_entry.get(key)
            for key in ("ar16_topic_id", "mapping_status", "topic_key", "topic_name")
            if key in registry_entry
        }
    return {
        key: getattr(registry_entry, key)
        for key in ("ar16_topic_id", "mapping_status", "topic_key", "topic_name")
        if hasattr(registry_entry, key)
    }


def _registry_value(registry_entry: Mapping[str, Any] | object, key: str) -> Any:
    if isinstance(registry_entry, Mapping):
        return registry_entry.get(key)
    return getattr(registry_entry, key)


def _sha256_json(payload: Mapping[str, Any]) -> str:
    text = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return _sha256_text(text)


def _sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _is_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)
