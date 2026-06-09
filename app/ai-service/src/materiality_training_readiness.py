"""Readiness gates before exporting reviewed materiality labels to flat training.

The current classifier format stores every missing topic as 0. That is only
valid when unresolved parent/family evidence for the same report has been
reviewed. Otherwise the export would turn unknown child topics into false
negatives.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Iterable, Mapping


DEFAULT_MIN_TRAINING_REPORTS = 100
DEFAULT_MIN_CHILD_KEYS = 20


def assess_child_training_readiness(
    *,
    child_labels: Iterable[Mapping[str, Any]],
    review_queue: Iterable[Mapping[str, Any]],
    min_training_reports: int = DEFAULT_MIN_TRAINING_REPORTS,
    min_child_keys: int = DEFAULT_MIN_CHILD_KEYS,
) -> dict[str, Any]:
    child_rows = list(child_labels)
    review_rows = list(review_queue)

    child_reports = {_report_key(row) for row in child_rows}
    unresolved_reports = {_report_key(row) for row in review_rows}
    trainable_reports = child_reports - unresolved_reports
    blocked_reports = child_reports & unresolved_reports
    child_keys = {str(row.get("python_esrs_key") or "") for row in child_rows if row.get("python_esrs_key")}
    trainable_child_rows = [
        row for row in child_rows if _report_key(row) in trainable_reports
    ]
    trainable_child_keys = {
        str(row.get("python_esrs_key") or "")
        for row in trainable_child_rows
        if row.get("python_esrs_key")
    }

    blockers: list[dict[str, Any]] = []
    for report in sorted(blocked_reports):
        blockers.append(
            {
                "reason": "unresolved_parent_or_ambiguous_materiality_for_report",
                "report_year": report[0],
                "source_file": report[1],
                "report_url": report[2],
            }
        )

    if len(trainable_reports) < min_training_reports:
        blockers.append(
            {
                "reason": "insufficient_unambiguous_training_reports",
                "minimum_required": min_training_reports,
                "actual": len(trainable_reports),
            }
        )

    if len(trainable_child_keys) < min_child_keys:
        blockers.append(
            {
                "reason": "insufficient_unambiguous_child_key_coverage",
                "minimum_required": min_child_keys,
                "actual": len(trainable_child_keys),
            }
        )

    return {
        "ready": not blockers,
        "child_label_count": len(child_rows),
        "child_report_count": len(child_reports),
        "child_key_count": len(child_keys),
        "unresolved_review_report_count": len(unresolved_reports),
        "blocked_child_report_count": len(blocked_reports),
        "trainable_child_label_count": len(trainable_child_rows),
        "trainable_report_count": len(trainable_reports),
        "trainable_child_key_count": len(trainable_child_keys),
        "blocker_count": len(blockers),
        "blockers": blockers,
    }


def assess_child_training_readiness_from_files(
    *,
    child_labels_path: Path,
    review_queue_path: Path,
    min_training_reports: int = DEFAULT_MIN_TRAINING_REPORTS,
    min_child_keys: int = DEFAULT_MIN_CHILD_KEYS,
) -> dict[str, Any]:
    return assess_child_training_readiness(
        child_labels=_iter_jsonl(child_labels_path),
        review_queue=_iter_jsonl(review_queue_path),
        min_training_reports=min_training_reports,
        min_child_keys=min_child_keys,
    )


def _iter_jsonl(path: Path) -> Iterable[dict[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                yield json.loads(line)


def _report_key(row: Mapping[str, Any]) -> tuple[Any, Any, Any]:
    return (row.get("report_year"), row.get("source_file"), row.get("report_url"))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Check v5 materiality training readiness")
    parser.add_argument("--child-labels", required=True, type=Path)
    parser.add_argument("--review-queue", required=True, type=Path)
    parser.add_argument("--min-training-reports", type=int, default=DEFAULT_MIN_TRAINING_REPORTS)
    parser.add_argument("--min-child-keys", type=int, default=DEFAULT_MIN_CHILD_KEYS)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args(argv)

    result = assess_child_training_readiness_from_files(
        child_labels_path=args.child_labels,
        review_queue_path=args.review_queue,
        min_training_reports=args.min_training_reports,
        min_child_keys=args.min_child_keys,
    )
    text = json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(text, encoding="utf-8")
    print(text)
    return 0 if result["ready"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
