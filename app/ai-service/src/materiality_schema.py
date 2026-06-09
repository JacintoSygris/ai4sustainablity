"""Schema gates for materiality-label extraction.

This module intentionally covers only the first implementation slice from the
L3 V6 consensus: closed vocabularies, topic-resolution invariants, and the
fail-closed handoff into later export checks. It does not classify PDFs.
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any


class MaterialitySchemaError(ValueError):
    """Raised when materiality label metadata violates the frozen schema."""


ALLOWED_AR16_MAPPING_STATUSES: set[str] = {
    "approved",
    "needs_review",
    "review_only",
    "aggregate_only",
    "out_of_scope",
}

FINAL_EXPORT_AR16_MAPPING_STATUS = "approved"

TOPIC_RESOLUTION_METHODS: set[str] = {
    "registry_exact_match",
    "registry_alias_match",
    "legacy_python_key_reconciled",
    "machine_synonym_candidate",
    "reviewer_override",
    "unresolved",
    "non_topic_aggregate_candidate",
    "out_of_scope_candidate",
}

TOPIC_RESOLUTION_STATES: set[str] = {
    "resolved_to_registry_topic",
    "machine_candidate_needs_review",
    "ambiguous_needs_review",
    "unresolved_blocked",
    "excluded_non_topic",
    "reviewer_resolved",
}

FORBIDDEN_TOPIC_RESOLUTION_VALUES: set[str] = {
    "direct",
    "equivalent",
    "approved",
    "needs_review",
    "review_only",
    "aggregate_only",
    "out_of_scope",
    "review_required",
    "ignored",
}

VALID_TOPIC_RESOLUTION_PAIRS: set[tuple[str, str]] = {
    ("registry_exact_match", "resolved_to_registry_topic"),
    ("registry_alias_match", "resolved_to_registry_topic"),
    ("legacy_python_key_reconciled", "resolved_to_registry_topic"),
    ("legacy_python_key_reconciled", "machine_candidate_needs_review"),
    ("machine_synonym_candidate", "machine_candidate_needs_review"),
    ("machine_synonym_candidate", "ambiguous_needs_review"),
    ("machine_synonym_candidate", "reviewer_resolved"),
    ("reviewer_override", "reviewer_resolved"),
    ("reviewer_override", "excluded_non_topic"),
    ("unresolved", "unresolved_blocked"),
    ("non_topic_aggregate_candidate", "excluded_non_topic"),
    ("out_of_scope_candidate", "excluded_non_topic"),
}

EXPORT_CHECK_TOPIC_RESOLUTION_STATES: set[str] = {
    "resolved_to_registry_topic",
    "reviewer_resolved",
}

BLOCKING_TOPIC_RESOLUTION_STATES: set[str] = {
    "machine_candidate_needs_review",
    "ambiguous_needs_review",
    "unresolved_blocked",
    "excluded_non_topic",
}

PRIMARY_STATUSES: set[str] = {
    "explicit_material",
    "explicit_not_material",
    "assessed_not_material_implicit",
    "substantive_reported",
    "mentioned_only",
    "not_found",
    "ambiguous",
    "contradictory",
    "needs_review",
    "out_of_scope_or_not_assessed",
}

BLOCKER_CODES: set[str] = {
    "matrix_image_unreadable",
    "materiality_table_unreadable",
    "low_text_yield",
    "ocr_required",
    "vision_required",
    "language_unsupported",
    "scope_unresolved",
    "mapping_unresolved",
    "complete_dma_unproven",
    "source_locator_missing",
    "prompt_injection_detected",
}

BLOCKER_FIELDS = ("report_blockers", "page_blockers", "topic_blockers")

BLOCKER_STATUSES: set[str] = {
    "open",
    "resolved",
    "accepted_exception",
    "not_applicable",
}

ACCEPTED_EXCEPTION_REASONS: set[str] = {
    "source_limitation_accepted",
    "manual_quality_override",
    "legacy_source_accepted",
    "reviewer_override",
}

EVIDENCE_TYPES: set[str] = {
    "materiality_table",
    "materiality_matrix",
    "dma_table",
    "iro_register",
    "materiality_methodology",
    "scoring_or_threshold_table",
    "topic_specific_disclosure_marked_material",
    "narrative_materiality_statement",
    "negative_materiality_statement",
    "complete_dma_absence",
    "esrs_content_index",
    "gri_content_index",
    "table_of_contents",
    "glossary_or_definition",
    "boilerplate_requirement_text",
    "substantive_disclosure",
    "omission_statement",
    "not_material_but_monitored",
    "synonym_or_custom_label",
    "matrix_image_unreadable",
    "materiality_table_unreadable",
    "reviewer_override",
}

EXTRACTOR_METHODS: set[str] = {
    "deterministic",
    "llm_bounded",
    "vision_bounded",
    "ocr",
    "reviewer_override",
}

EVIDENCE_STRENGTHS: set[str] = {
    "direct",
    "strong",
    "supporting",
    "weak",
    "absence",
    "reviewer_override",
}

EVIDENCE_SCOPES: set[str] = {
    "group",
    "entity",
    "site",
    "operation",
    "value_chain",
    "unknown",
}

PROMOTABLE_EVIDENCE_FIELDS = (
    "page_number",
    "excerpt",
    "evidence_type",
    "evidence_strength",
    "scope",
    "extractor_method",
)


def validate_topic_resolution(method: str, state: str) -> None:
    """Validate topic-resolution metadata against the V6 closed pair matrix."""

    if method in FORBIDDEN_TOPIC_RESOLUTION_VALUES:
        raise MaterialitySchemaError(
            f"topic_resolution_method uses forbidden cross-surface value: {method}"
        )
    if state in FORBIDDEN_TOPIC_RESOLUTION_VALUES:
        raise MaterialitySchemaError(
            f"topic_resolution_state uses forbidden cross-surface value: {state}"
        )
    if method not in TOPIC_RESOLUTION_METHODS:
        raise MaterialitySchemaError(f"unknown topic_resolution_method: {method}")
    if state not in TOPIC_RESOLUTION_STATES:
        raise MaterialitySchemaError(f"unknown topic_resolution_state: {state}")
    if (method, state) not in VALID_TOPIC_RESOLUTION_PAIRS:
        raise MaterialitySchemaError(
            f"invalid topic-resolution pair: {method} / {state}"
        )


def validate_ar16_mapping_status(status: str) -> None:
    if status not in ALLOWED_AR16_MAPPING_STATUSES:
        raise MaterialitySchemaError(f"unknown Ar16RegistryEntry.mapping_status: {status}")


def validate_primary_status(status: str) -> None:
    if status in BLOCKER_CODES:
        raise MaterialitySchemaError(
            f"primary_status must not contain extraction blocker code: {status}"
        )
    if status not in PRIMARY_STATUSES:
        raise MaterialitySchemaError(f"unknown primary_status: {status}")


def validate_blocker_fields(label: Mapping[str, Any]) -> None:
    status = str(label.get("blocker_status", "not_applicable"))
    if status not in BLOCKER_STATUSES:
        raise MaterialitySchemaError(f"unknown blocker_status: {status}")

    for field in BLOCKER_FIELDS:
        raw_codes = label.get(field, [])
        if raw_codes in (None, ""):
            raw_codes = []
        if not isinstance(raw_codes, list):
            raise MaterialitySchemaError(f"{field} must be an array")
        for code in raw_codes:
            if code not in BLOCKER_CODES:
                raise MaterialitySchemaError(f"unknown blocker code in {field}: {code}")

    if status == "accepted_exception" and _has_any_blocker(label):
        if "prompt_injection_detected" in _all_blocker_codes(label):
            raise MaterialitySchemaError(
                "accepted_exception cannot override prompt_injection_detected"
            )
        if not label.get("blocker_resolution_notes"):
            raise MaterialitySchemaError(
                "accepted_exception blockers require blocker_resolution_notes"
            )
        exception_reason = str(label.get("blocker_exception_reason", ""))
        if exception_reason not in ACCEPTED_EXCEPTION_REASONS:
            raise MaterialitySchemaError(
                "accepted_exception blockers require blocker_exception_reason"
            )
        if not label.get("reviewer_id") or not label.get("reviewed_at"):
            raise MaterialitySchemaError(
                "accepted_exception blockers require reviewer_id and reviewed_at"
            )


def has_open_export_blocker(label: Mapping[str, Any]) -> bool:
    validate_blocker_fields(label)
    if str(label.get("blocker_status", "not_applicable")) != "open":
        return False
    return _has_any_blocker(label)


def validate_evidence_items(label: Mapping[str, Any]) -> None:
    evidence_items = label.get("evidence_items", [])
    if evidence_items in (None, ""):
        evidence_items = []
    if not isinstance(evidence_items, list):
        raise MaterialitySchemaError("evidence_items must be an array")

    for index, item in enumerate(evidence_items):
        if not isinstance(item, Mapping):
            raise MaterialitySchemaError(f"evidence_items[{index}] must be an object")
        if item.get("evidence_id") in (None, ""):
            raise MaterialitySchemaError(
                f"evidence_items[{index}] missing required field: evidence_id"
            )
        for field in PROMOTABLE_EVIDENCE_FIELDS:
            if item.get(field) in (None, ""):
                raise MaterialitySchemaError(
                    f"evidence_items[{index}] missing required field: {field}"
                )
        if not item.get("bbox") and not item.get("structured_locator"):
            raise MaterialitySchemaError(
                f"evidence_items[{index}] needs bbox or structured_locator"
            )
        if item["evidence_type"] not in EVIDENCE_TYPES:
            raise MaterialitySchemaError(
                f"unknown evidence_type: {item['evidence_type']}"
            )
        if item["evidence_strength"] not in EVIDENCE_STRENGTHS:
            raise MaterialitySchemaError(
                f"unknown evidence_strength: {item['evidence_strength']}"
            )
        if item["scope"] not in EVIDENCE_SCOPES:
            raise MaterialitySchemaError(f"unknown evidence scope: {item['scope']}")
        if not _is_positive_int(item["page_number"]):
            raise MaterialitySchemaError(
                f"evidence_items[{index}].page_number must be a positive integer"
            )
        bbox = item.get("bbox")
        if bbox:
            _validate_bbox(index, bbox)
        if item["extractor_method"] not in EXTRACTOR_METHODS:
            raise MaterialitySchemaError(
                f"unknown extractor_method: {item['extractor_method']}"
            )
        if item.get("source_text_trusted") is not False:
            raise MaterialitySchemaError(
                f"evidence_items[{index}].source_text_trusted must be false"
            )


def validate_materiality_label_schema(label: Mapping[str, Any]) -> None:
    if "mapping_review_status" in label:
        raise MaterialitySchemaError(
            "mapping_review_status is superseded; use topic_resolution_state and review_status"
        )

    if "primary_status" in label:
        validate_primary_status(str(label["primary_status"]))

    validate_blocker_fields(label)
    validate_evidence_items(label)

    try:
        method = str(label["topic_resolution_method"])
        state = str(label["topic_resolution_state"])
    except KeyError as exc:
        raise MaterialitySchemaError(f"missing required materiality field: {exc.args[0]}") from exc

    validate_topic_resolution(method, state)


def topic_resolution_allows_export_checks(
    label: Mapping[str, Any],
    registry_entry: Mapping[str, Any] | object,
) -> bool:
    """Return whether topic resolution may proceed to later export gates.

    A true result is not final export authorization. Evidence, blocker, scope,
    review, gold-rule, manifest, and training-release gates still have to pass.
    """

    validate_materiality_label_schema(label)
    state = str(label["topic_resolution_state"])

    if state in BLOCKING_TOPIC_RESOLUTION_STATES:
        return False

    if state not in EXPORT_CHECK_TOPIC_RESOLUTION_STATES:
        return False

    if state == "reviewer_resolved":
        _require_reviewer_metadata(label)

    matched_topic_id = label.get("matched_topic_id")
    if matched_topic_id in (None, ""):
        return False

    registry_topic_id = _registry_value(registry_entry, "ar16_topic_id")
    if str(registry_topic_id) != str(matched_topic_id):
        return False

    mapping_status = str(_registry_value(registry_entry, "mapping_status"))
    validate_ar16_mapping_status(mapping_status)
    return mapping_status == FINAL_EXPORT_AR16_MAPPING_STATUS


def get_cross_surface_table() -> list[dict[str, Any]]:
    return [
        {
            "field": "topic_resolution_method",
            "owner": "materiality extractor",
            "valid_vocabulary": sorted(TOPIC_RESOLUTION_METHODS),
            "descriptive": True,
            "can_block_export": False,
            "participates_in_final_export_authorization": False,
        },
        {
            "field": "topic_resolution_state",
            "owner": "materiality extractor/reviewer",
            "valid_vocabulary": sorted(TOPIC_RESOLUTION_STATES),
            "descriptive": True,
            "can_block_export": True,
            "participates_in_final_export_authorization": False,
        },
        {
            "field": "Ar16RegistryEntry.mapping_status",
            "owner": "AR16 registry",
            "valid_vocabulary": sorted(ALLOWED_AR16_MAPPING_STATUSES),
            "descriptive": False,
            "can_block_export": True,
            "participates_in_final_export_authorization": True,
        },
        {
            "field": "Ar16KeyEquivalence.status",
            "owner": "legacy key reconciliation",
            "valid_vocabulary": [
                "equivalent",
                "known_non_ar16",
                "known_non_ar16_summary",
                "review_only_non_ar16",
            ],
            "descriptive": True,
            "can_block_export": False,
            "participates_in_final_export_authorization": False,
        },
        {
            "field": "review_status",
            "owner": "human review workflow",
            "valid_vocabulary": [
                "machine_proposed",
                "needs_review",
                "human_approved",
                "human_rejected",
                "gold_promoted",
            ],
            "descriptive": True,
            "can_block_export": True,
            "participates_in_final_export_authorization": False,
        },
    ]


def _registry_value(registry_entry: Mapping[str, Any] | object, key: str) -> Any:
    if isinstance(registry_entry, Mapping):
        return registry_entry.get(key)
    return getattr(registry_entry, key)


def _require_reviewer_metadata(label: Mapping[str, Any]) -> None:
    if not label.get("reviewer_id") or not label.get("reviewed_at"):
        raise MaterialitySchemaError(
            "reviewer_resolved topic resolution requires reviewer_id and reviewed_at"
        )


def _has_any_blocker(label: Mapping[str, Any]) -> bool:
    return any(label.get(field) for field in BLOCKER_FIELDS)


def _all_blocker_codes(label: Mapping[str, Any]) -> set[str]:
    codes: set[str] = set()
    for field in BLOCKER_FIELDS:
        raw_codes = label.get(field, [])
        if isinstance(raw_codes, list):
            codes.update(str(code) for code in raw_codes)
    return codes


def _is_positive_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


def _is_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def _validate_bbox(index: int, bbox: Any) -> None:
    if isinstance(bbox, Mapping):
        required_keys = ("x0", "y0", "x1", "y1")
        if not all(key in bbox for key in required_keys):
            raise MaterialitySchemaError(
                f"evidence_items[{index}].bbox object must contain x0, y0, x1, y1"
            )
        values = [bbox[key] for key in required_keys]
    elif isinstance(bbox, (list, tuple)):
        if len(bbox) != 4:
            raise MaterialitySchemaError(
                f"evidence_items[{index}].bbox array must contain four numbers"
            )
        values = list(bbox)
    else:
        raise MaterialitySchemaError(
            f"evidence_items[{index}].bbox must be an array or object"
        )

    if not all(_is_number(value) for value in values):
        raise MaterialitySchemaError(
            f"evidence_items[{index}].bbox coordinates must be numeric"
        )
