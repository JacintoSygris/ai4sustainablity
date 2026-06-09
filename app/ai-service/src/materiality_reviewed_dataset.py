"""Build a reviewed materiality dataset without fabricating child labels.

Reports often state materiality at ESRS theme/subtheme level, while the runtime
classifier predicts fine-grained AR16/Python keys. This module preserves that
granularity: exact or unique child hits become trainable labels; multi-child
theme/subtheme hits become parent/family materiality rows and review queue
items, not false positives for every child topic.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping

from ar16_multilingual_terms import (
    DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
    load_official_terms_by_ar16_id,
    official_terms_for_mapping_row,
)
from materiality_label_promotion import (
    DEFAULT_MAX_EVIDENCE_ITEMS_PER_LABEL,
    DEFAULT_MIN_ZONE_CONFIDENCE,
    NEGATIVE_MATERIALITY_PATTERN,
    _company_key,
    _evidence_item,
    _iter_jsonl,
    _load_zone_pages,
    _new_label,
    _normalize_text,
    _page_key_from_evidence,
    _phrase_from_key,
    _sha256_text,
)


REVIEWED_DATASET_SCHEMA_VERSION = "reviewed-materiality-v5-20260609"
BROAD_PARENT_REVIEW_TERMS = {
    "climate change",
}


@dataclass(frozen=True)
class ReviewedDatasetResult:
    child_labels: list[dict[str, Any]]
    parent_labels: list[dict[str, Any]]
    review_queue: list[dict[str, Any]]
    blocked: list[dict[str, Any]]
    ignored_count: int


def build_reviewed_materiality_dataset_from_evidence(
    *,
    zones_path: Path,
    evidence_path: Path,
    mapping_path: Path,
    reviewer_id: str,
    reviewed_at: str,
    gold_rule_manifest_hash: str | None = None,
    min_zone_confidence: float = DEFAULT_MIN_ZONE_CONFIDENCE,
    max_evidence_items_per_label: int = DEFAULT_MAX_EVIDENCE_ITEMS_PER_LABEL,
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> ReviewedDatasetResult:
    term_index = _load_term_index(mapping_path, official_translations_path=official_translations_path)
    zone_pages = _load_zone_pages(zones_path, min_zone_confidence=min_zone_confidence)

    child_labels_by_key: dict[tuple[Any, ...], dict[str, Any]] = {}
    parent_labels_by_key: dict[tuple[Any, ...], dict[str, Any]] = {}
    review_queue_by_key: dict[tuple[Any, ...], dict[str, Any]] = {}
    blocked: list[dict[str, Any]] = []
    ignored_count = 0

    for evidence in _iter_jsonl(evidence_path):
        zone = zone_pages.get(_page_key_from_evidence(evidence))
        if zone is None:
            ignored_count += 1
            continue

        match_term = _normalize_text(evidence.get("match_term"))
        if not match_term:
            blocked.append(_blocked_evidence(evidence, "empty_match_term"))
            continue

        excerpt = str(evidence.get("excerpt") or "")
        if NEGATIVE_MATERIALITY_PATTERN.search(excerpt):
            blocked.append(_blocked_evidence(evidence, "negative_materiality_context"))
            continue

        resolution = term_index.resolve(match_term)
        if resolution.kind == "unmapped":
            blocked.append(_blocked_evidence(evidence, "unmapped_materiality_term"))
            continue

        if resolution.kind in {"child_exact", "child_unique_parent_term"}:
            for entry in resolution.entries:
                for ar16_topic_id in entry["ar16_topic_ids"]:
                    label_key = (
                        evidence.get("cohort_report_year"),
                        evidence.get("source_file"),
                        evidence.get("report_url"),
                        evidence.get("pdf_sha256"),
                        int(ar16_topic_id),
                        entry["python_esrs_key"],
                    )
                    label = child_labels_by_key.get(label_key)
                    if label is None:
                        label = _new_label(
                            evidence=evidence,
                            zone=zone,
                            esrs_key=entry["python_esrs_key"],
                            ar16_topic_id=int(ar16_topic_id),
                            reviewer_id=reviewer_id,
                            reviewed_at=reviewed_at,
                            gold_rule_manifest_hash=gold_rule_manifest_hash,
                        )
                        label["promotion_rule"] = resolution.kind
                        label["matched_term"] = evidence.get("match_term")
                        child_labels_by_key[label_key] = label

                    if len(label["evidence_items"]) < max_evidence_items_per_label:
                        label["evidence_items"].append(_evidence_item(evidence=evidence, zone=zone))
            continue

        parent_key = (
            evidence.get("cohort_report_year"),
            evidence.get("source_file"),
            evidence.get("report_url"),
            evidence.get("pdf_sha256"),
            match_term,
        )
        parent_label = parent_labels_by_key.get(parent_key)
        if parent_label is None:
            parent_label = _new_parent_label(
                evidence=evidence,
                zone=zone,
                resolution=resolution,
            )
            parent_labels_by_key[parent_key] = parent_label
        if len(parent_label["evidence_items"]) < max_evidence_items_per_label:
            parent_label["evidence_items"].append(_evidence_item(evidence=evidence, zone=zone))

        queue_row = review_queue_by_key.get(parent_key)
        if queue_row is None:
            queue_row = _new_review_queue_row(
                evidence=evidence,
                zone=zone,
                resolution=resolution,
            )
            review_queue_by_key[parent_key] = queue_row
        if len(queue_row["evidence_items"]) < max_evidence_items_per_label:
            queue_row["evidence_items"].append(_evidence_item(evidence=evidence, zone=zone))

    return ReviewedDatasetResult(
        child_labels=sorted(child_labels_by_key.values(), key=lambda row: row["label_id"]),
        parent_labels=sorted(parent_labels_by_key.values(), key=lambda row: row["parent_label_id"]),
        review_queue=sorted(review_queue_by_key.values(), key=lambda row: row["review_row_id"]),
        blocked=blocked,
        ignored_count=ignored_count,
    )


def write_reviewed_dataset(
    *,
    result: ReviewedDatasetResult,
    output_dir: Path,
    run_id: str,
    mapping_path: Path,
    zones_path: Path,
    evidence_path: Path,
    min_zone_confidence: float,
) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)
    child_labels_path = output_dir / "child-labels.jsonl"
    parent_labels_path = output_dir / "parent-materiality-labels.jsonl"
    review_queue_path = output_dir / "review-queue.jsonl"
    blocked_path = output_dir / "blocked-evidence.jsonl"
    manifest_path = output_dir / "manifest.json"

    child_text = _jsonl_text(result.child_labels)
    parent_text = _jsonl_text(result.parent_labels)
    review_text = _jsonl_text(result.review_queue)
    blocked_text = _jsonl_text(result.blocked)

    child_labels_path.write_text(child_text, encoding="utf-8")
    parent_labels_path.write_text(parent_text, encoding="utf-8")
    review_queue_path.write_text(review_text, encoding="utf-8")
    blocked_path.write_text(blocked_text, encoding="utf-8")

    manifest = {
        "schema_version": REVIEWED_DATASET_SCHEMA_VERSION,
        "run_id": run_id,
        "mapping_path": str(mapping_path),
        "zones_path": str(zones_path),
        "evidence_path": str(evidence_path),
        "min_zone_confidence": min_zone_confidence,
        "child_label_count": len(result.child_labels),
        "parent_label_count": len(result.parent_labels),
        "review_queue_count": len(result.review_queue),
        "blocked_count": len(result.blocked),
        "ignored_outside_materiality_zone_count": result.ignored_count,
        "child_key_count": len({row["python_esrs_key"] for row in result.child_labels}),
        "parent_term_count": len({row["matched_term_normalized"] for row in result.parent_labels}),
        "child_labels_path": str(child_labels_path),
        "parent_labels_path": str(parent_labels_path),
        "review_queue_path": str(review_queue_path),
        "blocked_path": str(blocked_path),
        "child_labels_sha256": _sha256_text(child_text),
        "parent_labels_sha256": _sha256_text(parent_text),
        "review_queue_sha256": _sha256_text(review_text),
        "blocked_sha256": _sha256_text(blocked_text),
        "reason_counts": dict(Counter(row["reason"] for row in result.blocked)),
        "review_reason_counts": dict(Counter(row["review_reason"] for row in result.review_queue)),
    }
    manifest_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True),
        encoding="utf-8",
    )
    return {**manifest, "manifest_path": str(manifest_path)}


@dataclass(frozen=True)
class TermResolution:
    kind: str
    match_term: str
    entries: tuple[dict[str, Any], ...]
    term_roles: tuple[str, ...]


class TermIndex:
    def __init__(self, term_to_matches: Mapping[str, list[dict[str, Any]]]) -> None:
        self._term_to_matches = {
            term: tuple(_dedupe_matches(matches))
            for term, matches in term_to_matches.items()
        }

    def resolve(self, match_term: str) -> TermResolution:
        matches = self._term_to_matches.get(match_term, ())
        if not matches:
            return TermResolution("unmapped", match_term, (), ())

        child_entries = tuple(match["entry"] for match in matches)
        roles = tuple(sorted({match["role"] for match in matches}))
        unique_entries = tuple(_dedupe_entries(child_entries))
        child_roles = {
            "web_label_en",
            "web_subtopic_en",
            "key_phrase",
            "official_subtheme_child",
            "official_subtopic_child",
        }
        parent_roles = {
            "web_theme_en",
            "web_subtheme_en",
            "official_theme_parent",
            "official_subtheme_parent",
        }

        if match_term in BROAD_PARENT_REVIEW_TERMS:
            return TermResolution("parent_multi_child_review_required", match_term, unique_entries, roles)

        if len(unique_entries) == 1:
            if set(roles) & child_roles:
                return TermResolution("child_exact", match_term, unique_entries, roles)
            if set(roles) <= parent_roles:
                return TermResolution("child_unique_parent_term", match_term, unique_entries, roles)
            return TermResolution("child_exact", match_term, unique_entries, roles)

        if set(roles) <= parent_roles:
            return TermResolution("parent_multi_child_review_required", match_term, unique_entries, roles)
        if set(roles) & parent_roles:
            return TermResolution("parent_multi_child_review_required", match_term, unique_entries, roles)
        return TermResolution("ambiguous_child_term_review_required", match_term, unique_entries, roles)


def _load_term_index(
    path: Path,
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> TermIndex:
    payload = json.loads(path.read_text(encoding="utf-8"))
    official_terms_by_id = (
        load_official_terms_by_ar16_id(official_translations_path)
        if official_translations_path
        else {}
    )
    term_to_matches: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in payload.get("keys", []):
        if row.get("status") != "approved" or not row.get("ar16_topic_ids"):
            continue
        entry = {
            "python_esrs_key": str(row.get("python_esrs_key") or ""),
            "ar16_topic_ids": [int(value) for value in row.get("ar16_topic_ids")],
            "web_theme_en": row.get("web_theme_en"),
            "web_subtheme_en": row.get("web_subtheme_en"),
            "web_subtopic_en": row.get("web_subtopic_en"),
            "web_label_en": row.get("web_label_en"),
        }
        for role in ("web_label_en", "web_subtopic_en", "web_subtheme_en", "web_theme_en"):
            term = _normalize_text(row.get(role))
            if len(term) >= 4:
                term_to_matches[term].append({"role": role, "entry": entry})
        for official_term in official_terms_for_mapping_row(
            row,
            official_terms_by_ar16_id=official_terms_by_id,
            include_parent=True,
            include_child=True,
        ):
            term = _normalize_text(official_term.get("normalized") or official_term.get("display"))
            if len(term) >= 4:
                term_to_matches[term].append({"role": official_term["role"], "entry": entry})
        phrase = _normalize_text(_phrase_from_key(entry["python_esrs_key"]))
        if len(phrase) >= 4:
            term_to_matches[phrase].append({"role": "key_phrase", "entry": entry})
    return TermIndex(term_to_matches)


def _new_parent_label(
    *,
    evidence: Mapping[str, Any],
    zone: Mapping[str, Any],
    resolution: TermResolution,
) -> dict[str, Any]:
    report_year = evidence.get("cohort_report_year")
    source_file = evidence.get("source_file")
    report_url = evidence.get("report_url")
    pdf_sha256 = evidence.get("pdf_sha256")
    seed = f"{report_year}|{source_file}|{report_url}|{pdf_sha256}|{resolution.match_term}"
    return {
        "parent_label_id": hashlib.sha1(seed.encode("utf-8")).hexdigest(),
        "report_id": f"{report_year}::{source_file}",
        "company_id": _company_key(evidence.get("company_name", "")),
        "company_name": evidence.get("company_name", ""),
        "report_url": report_url,
        "source_file": source_file,
        "report_year": report_year,
        "matched_term": evidence.get("match_term"),
        "matched_term_normalized": resolution.match_term,
        "term_roles": list(resolution.term_roles),
        "primary_status": "explicit_material_parent",
        "review_status": "needs_resolution",
        "review_reason": resolution.kind,
        "candidate_python_esrs_keys": sorted({entry["python_esrs_key"] for entry in resolution.entries}),
        "candidate_ar16_topic_ids": sorted(
            {
                int(topic_id)
                for entry in resolution.entries
                for topic_id in entry.get("ar16_topic_ids", [])
            }
        ),
        "zone_type": zone.get("zone_type"),
        "evidence_items": [],
    }


def _new_review_queue_row(
    *,
    evidence: Mapping[str, Any],
    zone: Mapping[str, Any],
    resolution: TermResolution,
) -> dict[str, Any]:
    report_year = evidence.get("cohort_report_year")
    source_file = evidence.get("source_file")
    report_url = evidence.get("report_url")
    pdf_sha256 = evidence.get("pdf_sha256")
    seed = f"review|{report_year}|{source_file}|{report_url}|{pdf_sha256}|{resolution.match_term}"
    return {
        "review_row_id": hashlib.sha1(seed.encode("utf-8")).hexdigest(),
        "review_status": "needs_review",
        "review_reason": resolution.kind,
        "required_action": "resolve_to_child_topics_or_keep_parent_only",
        "company_name": evidence.get("company_name", ""),
        "source_file": source_file,
        "report_url": report_url,
        "report_year": report_year,
        "pdf_sha256": pdf_sha256,
        "matched_term": evidence.get("match_term"),
        "matched_term_normalized": resolution.match_term,
        "term_roles": list(resolution.term_roles),
        "candidate_python_esrs_keys": sorted({entry["python_esrs_key"] for entry in resolution.entries}),
        "candidate_ar16_topic_ids": sorted(
            {
                int(topic_id)
                for entry in resolution.entries
                for topic_id in entry.get("ar16_topic_ids", [])
            }
        ),
        "zone_id": zone.get("zone_id"),
        "zone_type": zone.get("zone_type"),
        "zone_confidence": zone.get("zone_confidence"),
        "evidence_items": [],
    }


def _blocked_evidence(evidence: Mapping[str, Any], reason: str) -> dict[str, Any]:
    return {
        "evidence_id": evidence.get("evidence_id"),
        "source_file": evidence.get("source_file"),
        "report_year": evidence.get("cohort_report_year"),
        "esrs_key": evidence.get("esrs_key"),
        "match_term": evidence.get("match_term"),
        "page_number": evidence.get("page_number"),
        "reason": reason,
    }


def _dedupe_entries(entries: Iterable[dict[str, Any]]) -> list[dict[str, Any]]:
    by_key: dict[str, dict[str, Any]] = {}
    for entry in entries:
        child = entry.get("entry", entry)
        key = str(child.get("python_esrs_key") or "")
        if key:
            by_key[key] = child
    return [by_key[key] for key in sorted(by_key)]


def _dedupe_matches(matches: Iterable[dict[str, Any]]) -> list[dict[str, Any]]:
    by_key: dict[tuple[str, str], dict[str, Any]] = {}
    for match in matches:
        entry = match["entry"]
        key = (str(match["role"]), str(entry.get("python_esrs_key") or ""))
        by_key[key] = match
    return [by_key[key] for key in sorted(by_key)]


def _jsonl_text(rows: Iterable[Mapping[str, Any]]) -> str:
    return "".join(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in rows)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build reviewed materiality dataset v5")
    parser.add_argument("--zones", required=True, type=Path)
    parser.add_argument("--evidence", required=True, type=Path)
    parser.add_argument("--mapping", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--reviewer-id", required=True)
    parser.add_argument("--reviewed-at", required=True)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--gold-rule-manifest-hash")
    parser.add_argument("--official-translations", type=Path, default=DEFAULT_OFFICIAL_TRANSLATIONS_PATH)
    parser.add_argument("--min-zone-confidence", type=float, default=DEFAULT_MIN_ZONE_CONFIDENCE)
    parser.add_argument(
        "--max-evidence-items-per-label",
        type=int,
        default=DEFAULT_MAX_EVIDENCE_ITEMS_PER_LABEL,
    )
    args = parser.parse_args(argv)

    result = build_reviewed_materiality_dataset_from_evidence(
        zones_path=args.zones,
        evidence_path=args.evidence,
        mapping_path=args.mapping,
        reviewer_id=args.reviewer_id,
        reviewed_at=args.reviewed_at,
        gold_rule_manifest_hash=args.gold_rule_manifest_hash,
        min_zone_confidence=args.min_zone_confidence,
        max_evidence_items_per_label=args.max_evidence_items_per_label,
        official_translations_path=args.official_translations,
    )
    manifest = write_reviewed_dataset(
        result=result,
        output_dir=args.output_dir,
        run_id=args.run_id,
        mapping_path=args.mapping,
        zones_path=args.zones,
        evidence_path=args.evidence,
        min_zone_confidence=args.min_zone_confidence,
    )
    print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
