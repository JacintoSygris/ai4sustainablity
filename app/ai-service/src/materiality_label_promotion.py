"""Promote reviewed materiality evidence into training-ready labels.

This module is intentionally conservative. It promotes an ESRS/AR16 label only
when a topic-specific evidence hit appears on a detected materiality/DMA page.
Generic parent terms such as "Working conditions" are kept out of the promoted
set because they fan out to many subtopics.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import unicodedata
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping


DEFAULT_MIN_ZONE_CONFIDENCE = 0.78
DEFAULT_MAX_EVIDENCE_ITEMS_PER_LABEL = 3

NEGATIVE_MATERIALITY_PATTERN = re.compile(
    r"\b(not|non)\s*-?\s*material\b|\bnot\s+assessed\s+as\s+material\b",
    re.IGNORECASE,
)

BROAD_MAPPING_TERMS = {
    "climate change",
}


@dataclass(frozen=True)
class PromotionResult:
    labels: list[dict[str, Any]]
    blocked: list[dict[str, Any]]

    @property
    def blocked_count(self) -> int:
        return len(self.blocked)


def build_materiality_labels_from_evidence(
    *,
    zones_path: Path,
    evidence_path: Path,
    mapping_path: Path,
    reviewer_id: str,
    reviewed_at: str,
    gold_rule_manifest_hash: str | None = None,
    min_zone_confidence: float = DEFAULT_MIN_ZONE_CONFIDENCE,
    max_evidence_items_per_label: int = DEFAULT_MAX_EVIDENCE_ITEMS_PER_LABEL,
) -> PromotionResult:
    mapping = _load_specific_mapping(mapping_path)
    zone_pages = _load_zone_pages(zones_path, min_zone_confidence=min_zone_confidence)
    labels_by_key: dict[tuple[Any, ...], dict[str, Any]] = {}
    blocked: list[dict[str, Any]] = []

    for evidence in _iter_jsonl(evidence_path):
        esrs_key = str(evidence.get("esrs_key") or "")
        mapping_entry = mapping.get(esrs_key)
        if mapping_entry is None:
            continue

        match_term = _normalize_text(evidence.get("match_term"))
        if match_term not in mapping_entry["specific_terms"]:
            blocked.append(_blocked_evidence(evidence, "not_specific_mapping_term"))
            continue
        if match_term in mapping_entry["ambiguous_terms"]:
            blocked.append(_blocked_evidence(evidence, "ambiguous_mapping_term"))
            continue
        if _content_token_count(match_term) < 2:
            blocked.append(_blocked_evidence(evidence, "single_token_mapping_term"))
            continue
        if match_term in BROAD_MAPPING_TERMS:
            blocked.append(_blocked_evidence(evidence, "broad_mapping_term"))
            continue

        zone = zone_pages.get(_page_key_from_evidence(evidence))
        if zone is None:
            continue

        excerpt = str(evidence.get("excerpt") or "")
        if NEGATIVE_MATERIALITY_PATTERN.search(excerpt):
            blocked.append(_blocked_evidence(evidence, "negative_materiality_context"))
            continue

        for ar16_topic_id in mapping_entry["ar16_topic_ids"]:
            label_key = (
                evidence.get("cohort_report_year"),
                evidence.get("source_file"),
                evidence.get("report_url"),
                evidence.get("pdf_sha256"),
                int(ar16_topic_id),
                esrs_key,
            )
            label = labels_by_key.get(label_key)
            if label is None:
                label = _new_label(
                    evidence=evidence,
                    zone=zone,
                    esrs_key=esrs_key,
                    ar16_topic_id=int(ar16_topic_id),
                    reviewer_id=reviewer_id,
                    reviewed_at=reviewed_at,
                    gold_rule_manifest_hash=gold_rule_manifest_hash,
                )
                labels_by_key[label_key] = label

            if len(label["evidence_items"]) < max_evidence_items_per_label:
                label["evidence_items"].append(_evidence_item(evidence=evidence, zone=zone))

    return PromotionResult(
        labels=sorted(labels_by_key.values(), key=lambda row: row["label_id"]),
        blocked=blocked,
    )


def write_materiality_training_csvs(
    *,
    labels_path: Path,
    targets_path: Path,
    source_companies_path: Path,
    esrs_columns: list[str],
    output_companies_path: Path,
    output_esrs_path: Path,
    blocked_path: Path,
) -> dict[str, Any]:
    labels = list(_iter_jsonl(labels_path))
    targets = json.loads(targets_path.read_text(encoding="utf-8"))
    companies = _read_semicolon_csv(source_companies_path)
    company_fieldnames = list(companies[0].keys()) if companies else ["file"]
    companies_by_file = {row.get("file", ""): row for row in companies}
    companies_by_name = {
        _company_key(row.get("company_data_company_name", "")): row
        for row in companies
        if row.get("company_data_company_name")
    }
    labels_by_report: dict[tuple[Any, ...], set[str]] = defaultdict(set)
    for label in labels:
        labels_by_report[_report_key(label)].add(str(label.get("python_esrs_key") or ""))

    output_company_rows: list[dict[str, Any]] = []
    output_esrs_rows: list[dict[str, Any]] = []
    blocked: list[dict[str, Any]] = []
    emitted_report_ids: set[str] = set()

    for target in targets:
        report_key = _report_key(target)
        positive_keys = {key for key in labels_by_report.get(report_key, set()) if key in esrs_columns}
        if not positive_keys:
            continue

        profile = companies_by_file.get(str(target.get("source_file") or ""))
        if profile is None:
            profile = companies_by_name.get(_company_key(target.get("company_name", "")))
        if profile is None:
            blocked.append(
                {
                    "report_year": target.get("report_year"),
                    "source_file": target.get("source_file"),
                    "company_name": target.get("company_name"),
                    "reason": "company_profile_missing",
                }
            )
            continue

        report_id = _training_report_id(target)
        if report_id in emitted_report_ids:
            blocked.append(
                {
                    "report_year": target.get("report_year"),
                    "source_file": target.get("source_file"),
                    "company_name": target.get("company_name"),
                    "reason": "duplicate_training_report_id",
                }
            )
            continue
        emitted_report_ids.add(report_id)

        company_row = {field: profile.get(field, "") for field in company_fieldnames}
        company_row["file"] = report_id
        output_company_rows.append(company_row)

        esrs_row = {"file": report_id}
        for column in esrs_columns:
            esrs_row[column] = 1 if column in positive_keys else 0
        output_esrs_rows.append(esrs_row)

    output_companies_path.parent.mkdir(parents=True, exist_ok=True)
    output_esrs_path.parent.mkdir(parents=True, exist_ok=True)
    blocked_path.parent.mkdir(parents=True, exist_ok=True)
    _write_semicolon_csv(output_companies_path, company_fieldnames, output_company_rows)
    _write_semicolon_csv(output_esrs_path, ["file", *esrs_columns], output_esrs_rows)
    blocked_text = "".join(
        json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in blocked
    )
    blocked_path.write_text(blocked_text, encoding="utf-8")

    return {
        "training_row_count": len(output_company_rows),
        "blocked_count": len(blocked),
        "company_csv": str(output_companies_path),
        "esrs_csv": str(output_esrs_path),
        "blocked_path": str(blocked_path),
        "company_csv_sha256": _sha256_text(output_companies_path.read_text(encoding="utf-8")),
        "esrs_csv_sha256": _sha256_text(output_esrs_path.read_text(encoding="utf-8")),
        "blocked_sha256": _sha256_text(blocked_text),
    }


def write_labels_and_blocked(
    *,
    result: PromotionResult,
    labels_path: Path,
    blocked_path: Path,
) -> dict[str, Any]:
    labels_path.parent.mkdir(parents=True, exist_ok=True)
    blocked_path.parent.mkdir(parents=True, exist_ok=True)
    labels_text = "".join(
        json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in result.labels
    )
    blocked_text = "".join(
        json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in result.blocked
    )
    labels_path.write_text(labels_text, encoding="utf-8")
    blocked_path.write_text(blocked_text, encoding="utf-8")
    return {
        "label_count": len(result.labels),
        "blocked_count": len(result.blocked),
        "labels_path": str(labels_path),
        "blocked_path": str(blocked_path),
        "labels_sha256": _sha256_text(labels_text),
        "blocked_sha256": _sha256_text(blocked_text),
    }


def load_esrs_columns(path: Path) -> list[str]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter=";")
        header = next(reader)
    return [column for column in header if column.startswith("esrs_")]


def _load_zone_pages(path: Path, *, min_zone_confidence: float) -> dict[tuple[Any, ...], dict[str, Any]]:
    zones: dict[tuple[Any, ...], dict[str, Any]] = {}
    for zone in _iter_jsonl(path):
        if zone.get("blockers"):
            continue
        if float(zone.get("zone_confidence") or 0) < min_zone_confidence:
            continue
        key = _page_key_from_zone(zone)
        existing = zones.get(key)
        if existing is None or float(zone.get("zone_confidence") or 0) > float(
            existing.get("zone_confidence") or 0
        ):
            zones[key] = zone
    return zones


def _load_specific_mapping(path: Path) -> dict[str, dict[str, Any]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    raw_entries: list[dict[str, Any]] = []
    term_to_keys: dict[str, set[str]] = defaultdict(set)
    for row in payload.get("keys", []):
        if row.get("status") != "approved" or not row.get("ar16_topic_ids"):
            continue
        esrs_key = str(row.get("python_esrs_key") or "")
        terms = {
            _normalize_text(row.get("web_label_en")),
            _normalize_text(row.get("web_subtopic_en")),
            _normalize_text(_phrase_from_key(esrs_key)),
        }
        if not row.get("web_subtopic_en"):
            terms.add(_normalize_text(row.get("web_subtheme_en")))
        specific_terms = {term for term in terms if len(term) >= 4}
        raw_entries.append(
            {
                "esrs_key": esrs_key,
                "ar16_topic_ids": [int(value) for value in row.get("ar16_topic_ids")],
                "specific_terms": specific_terms,
            }
        )
        for term in specific_terms:
            term_to_keys[term].add(esrs_key)

    mapping: dict[str, dict[str, Any]] = {}
    for entry in raw_entries:
        mapping[entry["esrs_key"]] = {
            "ar16_topic_ids": entry["ar16_topic_ids"],
            "specific_terms": entry["specific_terms"],
            "ambiguous_terms": {
                term for term in entry["specific_terms"] if len(term_to_keys[term]) > 1
            },
        }
    return mapping


def _new_label(
    *,
    evidence: Mapping[str, Any],
    zone: Mapping[str, Any],
    esrs_key: str,
    ar16_topic_id: int,
    reviewer_id: str,
    reviewed_at: str,
    gold_rule_manifest_hash: str | None,
) -> dict[str, Any]:
    report_year = evidence.get("cohort_report_year")
    source_file = evidence.get("source_file")
    report_url = evidence.get("report_url")
    pdf_sha256 = evidence.get("pdf_sha256")
    seed = f"{report_year}|{source_file}|{report_url}|{pdf_sha256}|{ar16_topic_id}|{esrs_key}"
    label = {
        "label_id": hashlib.sha1(seed.encode("utf-8")).hexdigest(),
        "report_id": f"{report_year}::{source_file}",
        "company_id": _company_key(evidence.get("company_name", "")),
        "company_name": evidence.get("company_name", ""),
        "report_url": report_url,
        "source_file": source_file,
        "report_year": report_year,
        "python_esrs_key": esrs_key,
        "matched_topic_id": ar16_topic_id,
        "primary_status": "explicit_material",
        "review_status": "gold_promoted",
        "topic_resolution_method": "registry_exact_match",
        "topic_resolution_state": "resolved_to_registry_topic",
        "blocker_status": "not_applicable",
        "report_blockers": [],
        "page_blockers": [],
        "topic_blockers": [],
        "blocker_resolution_notes": "",
        "blocker_exception_reason": "",
        "reviewer_id": reviewer_id,
        "reviewed_at": reviewed_at,
        "evidence_items": [],
    }
    if gold_rule_manifest_hash:
        label["gold_rule_manifest_hash"] = gold_rule_manifest_hash
    return label


def _evidence_item(*, evidence: Mapping[str, Any], zone: Mapping[str, Any]) -> dict[str, Any]:
    return {
        "evidence_id": evidence.get("evidence_id"),
        "evidence_type": _evidence_type_for_zone(zone),
        "evidence_strength": "direct"
        if zone.get("zone_type") in {"materiality_matrix", "dma_table_or_section"}
        else "strong",
        "page_number": int(evidence.get("page_number")),
        "bbox": evidence.get("bbox") or {},
        "structured_locator": f"zone_id={zone.get('zone_id')};match_term={evidence.get('match_term')}",
        "excerpt": evidence.get("excerpt", ""),
        "scope": "group",
        "extractor_method": "deterministic",
        "source_text_trusted": False,
    }


def _evidence_type_for_zone(zone: Mapping[str, Any]) -> str:
    zone_type = zone.get("zone_type")
    if zone_type == "materiality_matrix":
        return "materiality_matrix"
    if zone_type == "iro_register":
        return "iro_register"
    return "dma_table"


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


def _iter_jsonl(path: Path) -> Iterable[dict[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                yield json.loads(line)


def _read_semicolon_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle, delimiter=";"))


def _write_semicolon_csv(path: Path, fieldnames: list[str], rows: Iterable[Mapping[str, Any]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, delimiter=";", lineterminator="\n")
        writer.writeheader()
        for row in rows:
            writer.writerow(row)


def _page_key_from_zone(zone: Mapping[str, Any]) -> tuple[Any, ...]:
    return (
        zone.get("pdf_sha256"),
        zone.get("report_year"),
        zone.get("source_file"),
        zone.get("report_url"),
        zone.get("page_number"),
    )


def _page_key_from_evidence(evidence: Mapping[str, Any]) -> tuple[Any, ...]:
    return (
        evidence.get("pdf_sha256"),
        evidence.get("cohort_report_year"),
        evidence.get("source_file"),
        evidence.get("report_url"),
        evidence.get("page_number"),
    )


def _report_key(row: Mapping[str, Any]) -> tuple[Any, ...]:
    year = row.get("report_year", row.get("cohort_report_year"))
    return (year, row.get("source_file"), row.get("report_url"))


def _training_report_id(target: Mapping[str, Any]) -> str:
    return f"{target.get('report_year')}::{target.get('source_file')}"


def _phrase_from_key(key: str) -> str:
    return re.sub(r"^esrs_[a-z][0-9]_", "", key).replace("_", " ").strip()


def _normalize_text(value: Any) -> str:
    text = unicodedata.normalize("NFKC", str(value or ""))
    text = text.replace("\u00ad", "")
    text = re.sub(r"[\u2010\u2011\u2012\u2013\u2014\u2212]", "-", text)
    text = re.sub(r"\s*-\s*", "-", text)
    return re.sub(r"\s+", " ", text).strip().lower()


def _content_token_count(value: str) -> int:
    return len([token for token in re.findall(r"[a-z0-9]+", value.lower()) if len(token) > 1])


def _company_key(value: Any) -> str:
    normalized = unicodedata.normalize("NFKD", str(value or ""))
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii").lower()
    return " ".join(re.findall(r"[a-z0-9]+", ascii_text))


def _sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Promote materiality evidence into training labels")
    subparsers = parser.add_subparsers(dest="command", required=True)

    promote = subparsers.add_parser("promote")
    promote.add_argument("--zones", required=True, type=Path)
    promote.add_argument("--evidence", required=True, type=Path)
    promote.add_argument("--mapping", required=True, type=Path)
    promote.add_argument("--output-labels", required=True, type=Path)
    promote.add_argument("--blocked", required=True, type=Path)
    promote.add_argument("--reviewer-id", required=True)
    promote.add_argument("--reviewed-at", required=True)
    promote.add_argument("--gold-rule-manifest-hash")
    promote.add_argument("--min-zone-confidence", type=float, default=DEFAULT_MIN_ZONE_CONFIDENCE)

    csv_parser = subparsers.add_parser("csv")
    csv_parser.add_argument("--labels", required=True, type=Path)
    csv_parser.add_argument("--targets", required=True, type=Path)
    csv_parser.add_argument("--source-companies", required=True, type=Path)
    csv_parser.add_argument("--source-esrs", required=True, type=Path)
    csv_parser.add_argument("--output-companies", required=True, type=Path)
    csv_parser.add_argument("--output-esrs", required=True, type=Path)
    csv_parser.add_argument("--blocked", required=True, type=Path)

    args = parser.parse_args(argv)
    if args.command == "promote":
        result = build_materiality_labels_from_evidence(
            zones_path=args.zones,
            evidence_path=args.evidence,
            mapping_path=args.mapping,
            reviewer_id=args.reviewer_id,
            reviewed_at=args.reviewed_at,
            gold_rule_manifest_hash=args.gold_rule_manifest_hash,
            min_zone_confidence=args.min_zone_confidence,
        )
        summary = write_labels_and_blocked(
            result=result,
            labels_path=args.output_labels,
            blocked_path=args.blocked,
        )
    else:
        summary = write_materiality_training_csvs(
            labels_path=args.labels,
            targets_path=args.targets,
            source_companies_path=args.source_companies,
            esrs_columns=load_esrs_columns(args.source_esrs),
            output_companies_path=args.output_companies,
            output_esrs_path=args.output_esrs,
            blocked_path=args.blocked,
        )
    print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
