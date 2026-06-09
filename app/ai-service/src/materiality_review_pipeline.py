"""Orchestrate reviewed materiality labels after resolution suggestions.

The pipeline keeps three steps explicit:

1. Convert bounded suggestions into decision rows with reviewer metadata.
2. Use ``materiality_review_resolution.py`` to promote approved decisions.
3. Assemble base and resolved child labels plus the residual review queue.

This module does not infer child labels from parent-only rows. It can close
parent-only rows as reviewed decisions, but those rows emit no child labels.
"""

from __future__ import annotations

import argparse
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping

from materiality_label_promotion import _iter_jsonl, _sha256_text


@dataclass(frozen=True)
class ReviewedTrainingInputs:
    merged_child_labels: list[dict[str, Any]]
    residual_review_queue: list[dict[str, Any]]
    summary: dict[str, Any]


def build_machine_review_decisions_from_suggestions(
    *,
    suggestions: Iterable[Mapping[str, Any]],
    reviewer_id: str,
    reviewed_at: str,
    approve_multiple_exact_matches: bool = False,
    resolve_parent_only: bool = False,
) -> list[dict[str, Any]]:
    decisions: list[dict[str, Any]] = []

    for suggestion in suggestions:
        review_row_id = str(suggestion.get("review_row_id") or "")
        if not review_row_id:
            continue

        status = str(suggestion.get("suggestion_status") or "")
        matched_keys = _string_list(suggestion.get("matched_python_esrs_keys"))
        decision_status = ""
        approved_keys: list[str] = []

        if status == "unique_child_match" and len(matched_keys) == 1:
            decision_status = "approved_child_topics"
            approved_keys = matched_keys
        elif (
            status == "multiple_child_matches_needs_review"
            and approve_multiple_exact_matches
            and matched_keys
        ):
            decision_status = "approved_child_topics"
            approved_keys = matched_keys
        elif status == "parent_only_or_needs_review" and resolve_parent_only:
            decision_status = "parent_only"

        if not decision_status:
            continue

        decisions.append(
            {
                "review_row_id": review_row_id,
                "decision_status": decision_status,
                "approved_python_esrs_keys": approved_keys,
                "reviewer_id": reviewer_id,
                "reviewed_at": reviewed_at,
                "review_notes": _review_notes(suggestion=suggestion, decision_status=decision_status),
            }
        )

    return sorted(decisions, key=lambda row: str(row.get("review_row_id") or ""))


def assemble_reviewed_training_inputs(
    *,
    base_child_labels: Iterable[Mapping[str, Any]],
    resolved_child_labels: Iterable[Mapping[str, Any]],
    review_queue: Iterable[Mapping[str, Any]],
    review_outcomes: Iterable[Mapping[str, Any]],
) -> ReviewedTrainingInputs:
    base_rows = list(base_child_labels)
    resolved_rows = list(resolved_child_labels)
    review_rows = list(review_queue)
    outcome_rows = list(review_outcomes)
    merged_labels: list[dict[str, Any]] = []
    seen_label_keys: set[tuple[Any, ...]] = set()
    duplicate_label_count = 0

    for label in [*base_rows, *resolved_rows]:
        key = _label_key(label)
        if key in seen_label_keys:
            duplicate_label_count += 1
            continue
        seen_label_keys.add(key)
        merged_labels.append(dict(label))

    resolved_review_ids = {
        str(row.get("review_row_id") or "")
        for row in outcome_rows
        if row.get("review_row_id")
    }
    residual_review_queue = [
        dict(row)
        for row in review_rows
        if str(row.get("review_row_id") or "") not in resolved_review_ids
    ]

    summary = {
        "base_child_label_count": len(base_rows),
        "resolved_child_label_count": len(resolved_rows),
        "merged_child_label_count": len(merged_labels),
        "duplicate_label_count": duplicate_label_count,
        "resolved_review_row_count": len(resolved_review_ids),
        "residual_review_queue_count": len(residual_review_queue),
    }
    return ReviewedTrainingInputs(
        merged_child_labels=sorted(merged_labels, key=lambda row: str(row.get("label_id") or "")),
        residual_review_queue=sorted(
            residual_review_queue,
            key=lambda row: str(row.get("review_row_id") or ""),
        ),
        summary=summary,
    )


def write_machine_review_decisions(
    *,
    decisions: Iterable[Mapping[str, Any]],
    output_path: Path,
    summary_path: Path | None = None,
) -> dict[str, Any]:
    rows = list(decisions)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_text = _jsonl_text(rows)
    output_path.write_text(output_text, encoding="utf-8")
    summary = {
        "decision_count": len(rows),
        "approved_child_topic_decision_count": sum(
            1 for row in rows if row.get("decision_status") == "approved_child_topics"
        ),
        "parent_only_decision_count": sum(
            1 for row in rows if row.get("decision_status") == "parent_only"
        ),
        "approved_python_esrs_key_count": len(
            {
                key
                for row in rows
                for key in _string_list(row.get("approved_python_esrs_keys"))
            }
        ),
        "output_path": str(output_path),
        "output_sha256": _sha256_text(output_text),
    }
    if summary_path is not None:
        summary_path.parent.mkdir(parents=True, exist_ok=True)
        summary_text = json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        summary_path.write_text(summary_text, encoding="utf-8")
        summary["summary_path"] = str(summary_path)
        summary["summary_sha256"] = _sha256_text(summary_text)
    return summary


def write_reviewed_training_inputs(
    *,
    result: ReviewedTrainingInputs,
    output_child_labels_path: Path,
    residual_review_queue_path: Path,
    summary_path: Path | None = None,
) -> dict[str, Any]:
    output_child_labels_path.parent.mkdir(parents=True, exist_ok=True)
    residual_review_queue_path.parent.mkdir(parents=True, exist_ok=True)
    child_text = _jsonl_text(result.merged_child_labels)
    residual_text = _jsonl_text(result.residual_review_queue)
    output_child_labels_path.write_text(child_text, encoding="utf-8")
    residual_review_queue_path.write_text(residual_text, encoding="utf-8")
    summary = {
        **result.summary,
        "child_labels_path": str(output_child_labels_path),
        "child_labels_sha256": _sha256_text(child_text),
        "residual_review_queue_path": str(residual_review_queue_path),
        "residual_review_queue_sha256": _sha256_text(residual_text),
    }
    if summary_path is not None:
        summary_path.parent.mkdir(parents=True, exist_ok=True)
        summary_text = json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        summary_path.write_text(summary_text, encoding="utf-8")
        summary["summary_path"] = str(summary_path)
        summary["summary_sha256"] = _sha256_text(summary_text)
    return summary


def _review_notes(*, suggestion: Mapping[str, Any], decision_status: str) -> str:
    status = suggestion.get("suggestion_status")
    rationale = suggestion.get("rationale")
    if decision_status == "approved_child_topics":
        return (
            "Machine review: exact child-topic term(s) appeared in materiality evidence; "
            f"suggestion_status={status}; rationale={rationale}"
        )
    return (
        "Machine review: parent materiality retained without child-label promotion; "
        f"suggestion_status={status}; rationale={rationale}"
    )


def _label_key(label: Mapping[str, Any]) -> tuple[Any, ...]:
    return (
        label.get("report_year"),
        label.get("source_file"),
        label.get("report_url"),
        label.get("python_esrs_key"),
        label.get("matched_topic_id"),
    )


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
    parser = argparse.ArgumentParser(description="Assemble reviewed materiality training inputs")
    subparsers = parser.add_subparsers(dest="command", required=True)

    decisions_parser = subparsers.add_parser("decisions")
    decisions_parser.add_argument("--suggestions", required=True, type=Path)
    decisions_parser.add_argument("--output", required=True, type=Path)
    decisions_parser.add_argument("--summary", type=Path)
    decisions_parser.add_argument("--reviewer-id", required=True)
    decisions_parser.add_argument("--reviewed-at", required=True)
    decisions_parser.add_argument("--approve-multiple-exact-matches", action="store_true")
    decisions_parser.add_argument("--resolve-parent-only", action="store_true")

    assemble_parser = subparsers.add_parser("assemble")
    assemble_parser.add_argument("--base-child-labels", required=True, type=Path)
    assemble_parser.add_argument("--resolved-child-labels", required=True, type=Path)
    assemble_parser.add_argument("--review-queue", required=True, type=Path)
    assemble_parser.add_argument("--review-outcomes", required=True, type=Path)
    assemble_parser.add_argument("--output-child-labels", required=True, type=Path)
    assemble_parser.add_argument("--residual-review-queue", required=True, type=Path)
    assemble_parser.add_argument("--summary", type=Path)

    args = parser.parse_args(argv)
    if args.command == "decisions":
        decisions = build_machine_review_decisions_from_suggestions(
            suggestions=_iter_jsonl(args.suggestions),
            reviewer_id=args.reviewer_id,
            reviewed_at=args.reviewed_at,
            approve_multiple_exact_matches=args.approve_multiple_exact_matches,
            resolve_parent_only=args.resolve_parent_only,
        )
        summary = write_machine_review_decisions(
            decisions=decisions,
            output_path=args.output,
            summary_path=args.summary,
        )
    else:
        result = assemble_reviewed_training_inputs(
            base_child_labels=list(_iter_jsonl(args.base_child_labels)),
            resolved_child_labels=list(_iter_jsonl(args.resolved_child_labels)),
            review_queue=list(_iter_jsonl(args.review_queue)),
            review_outcomes=list(_iter_jsonl(args.review_outcomes)),
        )
        summary = write_reviewed_training_inputs(
            result=result,
            output_child_labels_path=args.output_child_labels,
            residual_review_queue_path=args.residual_review_queue,
            summary_path=args.summary,
        )
    print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
