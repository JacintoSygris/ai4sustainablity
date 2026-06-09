"""Promote reviewed parent/ambiguous materiality queue rows to child labels."""

from __future__ import annotations

import argparse
import hashlib
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping

from materiality_label_promotion import _company_key, _iter_jsonl, _sha256_text


DECISION_STATUSES = {
    "approved_child_topics",
    "parent_only",
    "rejected",
}


@dataclass(frozen=True)
class ReviewResolutionResult:
    labels: list[dict[str, Any]]
    outcomes: list[dict[str, Any]]
    blocked: list[dict[str, Any]]


def promote_review_decisions_to_child_labels(
    *,
    review_queue: Iterable[Mapping[str, Any]],
    decisions: Iterable[Mapping[str, Any]],
    mapping: Mapping[str, Any],
) -> ReviewResolutionResult:
    queue_by_id = {str(row.get("review_row_id") or ""): row for row in review_queue}
    mapping_by_key = _mapping_by_python_key(mapping)
    labels: list[dict[str, Any]] = []
    outcomes: list[dict[str, Any]] = []
    blocked: list[dict[str, Any]] = []

    for decision in decisions:
        review_row_id = str(decision.get("review_row_id") or "")
        queue_row = queue_by_id.get(review_row_id)
        if queue_row is None:
            blocked.append(_blocked_decision(decision, "review_row_id_not_found"))
            continue

        status = str(decision.get("decision_status") or "")
        if status not in DECISION_STATUSES:
            blocked.append(_blocked_decision(decision, "invalid_decision_status"))
            continue

        reviewer_id = str(decision.get("reviewer_id") or "")
        reviewed_at = str(decision.get("reviewed_at") or "")
        if not reviewer_id or not reviewed_at:
            blocked.append(_blocked_decision(decision, "reviewer_metadata_missing"))
            continue

        if status != "approved_child_topics":
            outcomes.append(_outcome(queue_row=queue_row, decision=decision, emitted_labels=[]))
            continue

        approved_keys = _string_list(decision.get("approved_python_esrs_keys"))
        if not approved_keys:
            blocked.append(_blocked_decision(decision, "approved_python_esrs_keys_empty"))
            continue

        candidate_keys = set(_string_list(queue_row.get("candidate_python_esrs_keys")))
        invalid_keys = sorted(set(approved_keys) - candidate_keys)
        if invalid_keys:
            blocked.append(
                _blocked_decision(
                    decision,
                    "approved_key_not_in_review_candidates",
                    extra={"invalid_keys": invalid_keys},
                )
            )
            continue

        emitted: list[str] = []
        for python_esrs_key in approved_keys:
            mapping_row = mapping_by_key.get(python_esrs_key)
            if mapping_row is None:
                blocked.append(
                    _blocked_decision(
                        decision,
                        "approved_key_missing_from_mapping",
                        extra={"python_esrs_key": python_esrs_key},
                    )
                )
                continue
            for ar16_topic_id in mapping_row["ar16_topic_ids"]:
                label = _new_human_approved_label(
                    queue_row=queue_row,
                    decision=decision,
                    python_esrs_key=python_esrs_key,
                    ar16_topic_id=int(ar16_topic_id),
                )
                labels.append(label)
                emitted.append(label["label_id"])

        outcomes.append(_outcome(queue_row=queue_row, decision=decision, emitted_labels=emitted))

    return ReviewResolutionResult(
        labels=sorted(labels, key=lambda row: row["label_id"]),
        outcomes=sorted(outcomes, key=lambda row: row["review_row_id"]),
        blocked=blocked,
    )


def write_review_resolution(
    *,
    result: ReviewResolutionResult,
    output_labels_path: Path,
    outcomes_path: Path,
    blocked_path: Path,
) -> dict[str, Any]:
    output_labels_path.parent.mkdir(parents=True, exist_ok=True)
    outcomes_path.parent.mkdir(parents=True, exist_ok=True)
    blocked_path.parent.mkdir(parents=True, exist_ok=True)

    labels_text = _jsonl_text(result.labels)
    outcomes_text = _jsonl_text(result.outcomes)
    blocked_text = _jsonl_text(result.blocked)
    output_labels_path.write_text(labels_text, encoding="utf-8")
    outcomes_path.write_text(outcomes_text, encoding="utf-8")
    blocked_path.write_text(blocked_text, encoding="utf-8")
    return {
        "label_count": len(result.labels),
        "outcome_count": len(result.outcomes),
        "blocked_count": len(result.blocked),
        "labels_path": str(output_labels_path),
        "outcomes_path": str(outcomes_path),
        "blocked_path": str(blocked_path),
        "labels_sha256": _sha256_text(labels_text),
        "outcomes_sha256": _sha256_text(outcomes_text),
        "blocked_sha256": _sha256_text(blocked_text),
    }


def _mapping_by_python_key(mapping: Mapping[str, Any]) -> dict[str, dict[str, Any]]:
    rows: dict[str, dict[str, Any]] = {}
    for row in mapping.get("keys", []):
        if row.get("status") != "approved" or not row.get("ar16_topic_ids"):
            continue
        key = str(row.get("python_esrs_key") or "")
        rows[key] = {
            "python_esrs_key": key,
            "ar16_topic_ids": [int(value) for value in row.get("ar16_topic_ids")],
        }
    return rows


def _new_human_approved_label(
    *,
    queue_row: Mapping[str, Any],
    decision: Mapping[str, Any],
    python_esrs_key: str,
    ar16_topic_id: int,
) -> dict[str, Any]:
    seed = f"{queue_row.get('review_row_id')}|{python_esrs_key}|{ar16_topic_id}"
    return {
        "label_id": hashlib.sha1(seed.encode("utf-8")).hexdigest(),
        "report_id": f"{queue_row.get('report_year')}::{queue_row.get('source_file')}",
        "company_id": _company_key(queue_row.get("company_name", "")),
        "company_name": queue_row.get("company_name", ""),
        "report_url": queue_row.get("report_url"),
        "source_file": queue_row.get("source_file"),
        "report_year": queue_row.get("report_year"),
        "python_esrs_key": python_esrs_key,
        "matched_topic_id": ar16_topic_id,
        "primary_status": "explicit_material",
        "review_status": "human_approved",
        "topic_resolution_method": "reviewer_override",
        "topic_resolution_state": "reviewer_resolved",
        "blocker_status": "not_applicable",
        "report_blockers": [],
        "page_blockers": [],
        "topic_blockers": [],
        "blocker_resolution_notes": "",
        "blocker_exception_reason": "",
        "reviewer_id": decision.get("reviewer_id"),
        "reviewed_at": decision.get("reviewed_at"),
        "review_notes": decision.get("review_notes", ""),
        "source_review_row_id": queue_row.get("review_row_id"),
        "source_matched_term": queue_row.get("matched_term"),
        "evidence_items": list(queue_row.get("evidence_items") or []),
    }


def _outcome(
    *,
    queue_row: Mapping[str, Any],
    decision: Mapping[str, Any],
    emitted_labels: list[str],
) -> dict[str, Any]:
    return {
        "review_row_id": queue_row.get("review_row_id"),
        "decision_status": decision.get("decision_status"),
        "reviewer_id": decision.get("reviewer_id"),
        "reviewed_at": decision.get("reviewed_at"),
        "emitted_label_ids": emitted_labels,
        "approved_python_esrs_keys": _string_list(decision.get("approved_python_esrs_keys")),
        "review_notes": decision.get("review_notes", ""),
    }


def _blocked_decision(
    decision: Mapping[str, Any],
    reason: str,
    *,
    extra: Mapping[str, Any] | None = None,
) -> dict[str, Any]:
    row = {
        "review_row_id": decision.get("review_row_id"),
        "decision_status": decision.get("decision_status"),
        "reason": reason,
    }
    if extra:
        row.update(extra)
    return row


def _string_list(value: Any) -> list[str]:
    if value in (None, ""):
        return []
    if isinstance(value, str):
        return [item.strip() for item in value.split(",") if item.strip()]
    if isinstance(value, list):
        return [str(item).strip() for item in value if str(item).strip()]
    return []


def _jsonl_text(rows: Iterable[Mapping[str, Any]]) -> str:
    return "".join(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in rows)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Promote reviewed materiality decisions")
    parser.add_argument("--review-queue", required=True, type=Path)
    parser.add_argument("--decisions", required=True, type=Path)
    parser.add_argument("--mapping", required=True, type=Path)
    parser.add_argument("--output-labels", required=True, type=Path)
    parser.add_argument("--outcomes", required=True, type=Path)
    parser.add_argument("--blocked", required=True, type=Path)
    args = parser.parse_args(argv)

    result = promote_review_decisions_to_child_labels(
        review_queue=_iter_jsonl(args.review_queue),
        decisions=_iter_jsonl(args.decisions),
        mapping=json.loads(args.mapping.read_text(encoding="utf-8")),
    )
    summary = write_review_resolution(
        result=result,
        output_labels_path=args.output_labels,
        outcomes_path=args.outcomes,
        blocked_path=args.blocked,
    )
    print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if summary["blocked_count"] == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
