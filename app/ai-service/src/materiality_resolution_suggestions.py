"""Suggest child-topic resolutions for parent materiality review rows.

This is an evidence-gated helper, not an approval path. It uses the same
scope-lock principle as the review resolver: suggested child keys must be a
subset of the review row candidates, and only exact child-label evidence can
produce a no-review suggestion template.
"""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping

from ar16_multilingual_terms import (
    DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
    load_official_terms_by_ar16_id,
    official_terms_for_mapping_row,
)
from materiality_label_promotion import _iter_jsonl, _normalize_text, _phrase_from_key, _sha256_text


@dataclass(frozen=True)
class ResolutionSuggestionResult:
    suggestions: list[dict[str, Any]]
    blocked: list[dict[str, Any]]


def build_review_resolution_suggestions(
    *,
    review_queue: Iterable[Mapping[str, Any]],
    mapping: Mapping[str, Any],
    min_unique_confidence: float = 0.95,
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> ResolutionSuggestionResult:
    mapping_by_key = _mapping_by_python_key(mapping, official_translations_path=official_translations_path)
    suggestions: list[dict[str, Any]] = []
    blocked: list[dict[str, Any]] = []

    for queue_row in review_queue:
        candidate_keys = _string_list(queue_row.get("candidate_python_esrs_keys"))
        evidence_text = _evidence_text(queue_row)
        candidate_matches: list[dict[str, Any]] = []

        for python_esrs_key in candidate_keys:
            mapping_row = mapping_by_key.get(python_esrs_key)
            if mapping_row is None:
                blocked.append(
                    {
                        "review_row_id": queue_row.get("review_row_id"),
                        "python_esrs_key": python_esrs_key,
                        "reason": "candidate_key_missing_from_mapping",
                    }
                )
                continue

            matched_terms = [
                term["display"]
                for term in mapping_row["child_terms"]
                if _contains_term(evidence_text, str(term["normalized"]))
            ]
            if matched_terms:
                candidate_matches.append(
                    {
                        "python_esrs_key": python_esrs_key,
                        "matched_terms": _dedupe_preserve_order(matched_terms),
                        "score": 1.0,
                        "ar16_topic_ids": list(mapping_row["ar16_topic_ids"]),
                    }
                )

        suggestion = _suggestion_for_row(
            queue_row=queue_row,
            candidate_matches=sorted(candidate_matches, key=lambda row: row["python_esrs_key"]),
            min_unique_confidence=min_unique_confidence,
        )
        suggestions.append(suggestion)

    return ResolutionSuggestionResult(
        suggestions=sorted(suggestions, key=lambda row: str(row.get("review_row_id") or "")),
        blocked=blocked,
    )


def write_resolution_suggestions(
    *,
    result: ResolutionSuggestionResult,
    output_path: Path,
    summary_path: Path | None = None,
) -> dict[str, Any]:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_text = _jsonl_text(result.suggestions)
    output_path.write_text(output_text, encoding="utf-8")

    status_counts = Counter(str(row.get("suggestion_status") or "") for row in result.suggestions)
    summary = {
        "suggestion_count": len(result.suggestions),
        "blocked_count": len(result.blocked),
        "status_counts": dict(sorted(status_counts.items())),
        "auto_decision_template_count": sum(
            1 for row in result.suggestions if not row.get("requires_human_review")
        ),
        "output_path": str(output_path),
        "output_sha256": _sha256_text(output_text),
    }
    if result.blocked:
        summary["blocked"] = result.blocked
    if summary_path is not None:
        summary_path.parent.mkdir(parents=True, exist_ok=True)
        summary_text = json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True)
        summary_path.write_text(summary_text + "\n", encoding="utf-8")
        summary["summary_path"] = str(summary_path)
        summary["summary_sha256"] = _sha256_text(summary_text + "\n")
    return summary


def _mapping_by_python_key(
    mapping: Mapping[str, Any],
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> dict[str, dict[str, Any]]:
    official_terms_by_id = (
        load_official_terms_by_ar16_id(official_translations_path)
        if official_translations_path
        else {}
    )
    rows: dict[str, dict[str, Any]] = {}
    for row in mapping.get("keys", []):
        if row.get("status") != "approved" or not row.get("ar16_topic_ids"):
            continue
        python_esrs_key = str(row.get("python_esrs_key") or "")
        if not python_esrs_key:
            continue
        rows[python_esrs_key] = {
            "python_esrs_key": python_esrs_key,
            "ar16_topic_ids": [int(value) for value in row.get("ar16_topic_ids")],
            "child_terms": _child_terms_for_mapping_row(
                row,
                official_terms_by_ar16_id=official_terms_by_id,
            ),
        }
    return rows


def _child_terms_for_mapping_row(
    row: Mapping[str, Any],
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
    official_terms_by_ar16_id: Mapping[int, list[dict[str, Any]]] | None = None,
) -> list[dict[str, str]]:
    parent_terms = {
        _normalize_text(row.get("web_theme_en")),
        _normalize_text(row.get("web_subtheme_en")),
    }
    terms = [
        str(row.get("web_label_en") or ""),
        str(row.get("web_subtopic_en") or ""),
        _phrase_from_key(str(row.get("python_esrs_key") or "")),
    ]
    terms.extend(
        str(term.get("display") or "")
        for term in official_terms_for_mapping_row(
            row,
            official_terms_by_ar16_id=(
                official_terms_by_ar16_id
                if official_terms_by_ar16_id is not None
                else load_official_terms_by_ar16_id(official_translations_path)
                if official_translations_path
                else {}
            ),
            include_parent=False,
            include_child=True,
        )
    )
    child_terms: list[dict[str, str]] = []
    seen: set[str] = set()
    for display in terms:
        normalized = _normalize_text(display)
        if len(normalized) < 4:
            continue
        if normalized in parent_terms:
            continue
        if _content_token_count(normalized) < 2:
            continue
        if normalized in seen:
            continue
        seen.add(normalized)
        child_terms.append({"display": display.strip(), "normalized": normalized})
    return child_terms


def _suggestion_for_row(
    *,
    queue_row: Mapping[str, Any],
    candidate_matches: list[dict[str, Any]],
    min_unique_confidence: float,
) -> dict[str, Any]:
    matched_keys = [str(row["python_esrs_key"]) for row in candidate_matches]
    if len(candidate_matches) == 1:
        status = "unique_child_match"
        requires_review = False
        confidence = min(max(min_unique_confidence, 0.0), 1.0)
        approved_keys = matched_keys
        template_status = "approved_child_topics"
        rationale = "One candidate child topic appears verbatim in the evidence text."
    elif len(candidate_matches) > 1:
        status = "multiple_child_matches_needs_review"
        requires_review = True
        confidence = 0.5
        approved_keys = []
        template_status = ""
        rationale = "Multiple candidate child topics appear in the evidence text; reviewer must decide scope."
    else:
        status = "parent_only_or_needs_review"
        requires_review = True
        confidence = 0.0
        approved_keys = []
        template_status = "parent_only"
        rationale = "No candidate child topic appears verbatim in the evidence text."

    return {
        "review_row_id": queue_row.get("review_row_id"),
        "review_reason": queue_row.get("review_reason"),
        "company_name": queue_row.get("company_name"),
        "source_file": queue_row.get("source_file"),
        "report_url": queue_row.get("report_url"),
        "report_year": queue_row.get("report_year"),
        "matched_term": queue_row.get("matched_term"),
        "candidate_python_esrs_keys": _string_list(queue_row.get("candidate_python_esrs_keys")),
        "matched_python_esrs_keys": matched_keys,
        "candidate_matches": candidate_matches,
        "suggestion_status": status,
        "requires_human_review": requires_review,
        "requires_approval_metadata": True,
        "confidence": confidence,
        "rationale": rationale,
        "decision_template": {
            "review_row_id": queue_row.get("review_row_id"),
            "decision_status": template_status,
            "approved_python_esrs_keys": approved_keys,
            "reviewer_id": "",
            "reviewed_at": "",
            "review_notes": (
                "Machine suggestion from exact child-term evidence; verify before promotion."
                if approved_keys
                else rationale
            ),
        },
    }


def _evidence_text(queue_row: Mapping[str, Any]) -> str:
    chunks = [
        str(queue_row.get("excerpt") or ""),
        str(queue_row.get("zone_excerpt") or ""),
    ]
    for item in queue_row.get("evidence_items") or []:
        if isinstance(item, Mapping):
            chunks.append(str(item.get("excerpt") or ""))
            chunks.append(str(item.get("structured_locator") or ""))
    return _normalize_text(" ".join(chunks))


def _contains_term(text: str, normalized_term: str) -> bool:
    if not normalized_term:
        return False
    pattern = rf"(?<![a-z0-9]){re.escape(normalized_term)}(?![a-z0-9])"
    return re.search(pattern, text) is not None


def _content_token_count(value: str) -> int:
    return len([token for token in re.findall(r"\w+", value.lower()) if len(token) > 1])


def _string_list(value: Any) -> list[str]:
    if value in (None, ""):
        return []
    if isinstance(value, str):
        return [item.strip() for item in value.split(",") if item.strip()]
    if isinstance(value, list):
        return [str(item).strip() for item in value if str(item).strip()]
    return []


def _dedupe_preserve_order(values: Iterable[str]) -> list[str]:
    seen: set[str] = set()
    result: list[str] = []
    for value in values:
        if value in seen:
            continue
        seen.add(value)
        result.append(value)
    return result


def _jsonl_text(rows: Iterable[Mapping[str, Any]]) -> str:
    return "".join(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in rows)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Suggest reviewed materiality child-topic resolutions")
    parser.add_argument("--review-queue", required=True, type=Path)
    parser.add_argument("--mapping", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--summary", type=Path)
    parser.add_argument("--official-translations", type=Path, default=DEFAULT_OFFICIAL_TRANSLATIONS_PATH)
    parser.add_argument("--min-unique-confidence", type=float, default=0.95)
    args = parser.parse_args(argv)

    result = build_review_resolution_suggestions(
        review_queue=_iter_jsonl(args.review_queue),
        mapping=json.loads(args.mapping.read_text(encoding="utf-8")),
        min_unique_confidence=args.min_unique_confidence,
        official_translations_path=args.official_translations,
    )
    summary = write_resolution_suggestions(
        result=result,
        output_path=args.output,
        summary_path=args.summary,
    )
    print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if summary["blocked_count"] == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
