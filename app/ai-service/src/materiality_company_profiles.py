"""Augment company profile CSVs for reviewed materiality training exports.

This helper keeps missing profile handling explicit. It first reuses an
existing company profile when the normalized company name matches; otherwise it
adds a placeholder row marked with ``company_data_profile_quality`` so the
training run can include the report without pretending the company profile was
fully characterized.
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import unicodedata
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping

from materiality_label_promotion import _sha256_text


PROFILE_QUALITY_FIELD = "company_data_profile_quality"
PROFILE_SOURCE_FIELD = "company_data_profile_source"


@dataclass(frozen=True)
class CompanyProfileAugmentationResult:
    rows: list[dict[str, Any]]
    fieldnames: list[str]
    added_count: int
    copied_profile_count: int
    placeholder_profile_count: int


def augment_company_profiles_for_missing_reports(
    *,
    source_companies: Iterable[Mapping[str, Any]],
    missing_reports: Iterable[Mapping[str, Any]],
    profile_source: str = "training_csv_blocked",
) -> CompanyProfileAugmentationResult:
    source_rows = [dict(row) for row in source_companies]
    fieldnames = _fieldnames(source_rows)
    for field in (PROFILE_QUALITY_FIELD, PROFILE_SOURCE_FIELD):
        if field not in fieldnames:
            fieldnames.append(field)

    rows = [_with_profile_metadata(row, quality="source_profile", source="source_csv") for row in source_rows]
    existing_files = {str(row.get("file") or "") for row in rows}
    profile_by_company = {
        _company_key(row.get("company_data_company_name")): row
        for row in source_rows
        if row.get("company_data_company_name")
    }

    added_count = 0
    copied_profile_count = 0
    placeholder_profile_count = 0
    for missing in missing_reports:
        if missing.get("reason") not in (None, "", "company_profile_missing"):
            continue
        source_file = str(missing.get("source_file") or "")
        company_name = str(missing.get("company_name") or "")
        if not source_file or source_file in existing_files:
            continue

        existing_profile = profile_by_company.get(_company_key(company_name))
        if existing_profile is not None:
            row = _copy_existing_profile(
                existing_profile,
                source_file=source_file,
                company_name=company_name,
                profile_source=profile_source,
            )
            copied_profile_count += 1
        else:
            row = _placeholder_profile(
                fieldnames=fieldnames,
                source_file=source_file,
                company_name=company_name,
                profile_source=profile_source,
            )
            placeholder_profile_count += 1

        rows.append(row)
        existing_files.add(source_file)
        added_count += 1

    return CompanyProfileAugmentationResult(
        rows=[{field: row.get(field, "") for field in fieldnames} for row in rows],
        fieldnames=fieldnames,
        added_count=added_count,
        copied_profile_count=copied_profile_count,
        placeholder_profile_count=placeholder_profile_count,
    )


def augment_company_profiles_for_missing_reports_from_files(
    *,
    source_companies_path: Path,
    missing_reports_path: Path,
    output_companies_path: Path,
    summary_path: Path | None = None,
    profile_source: str | None = None,
) -> dict[str, Any]:
    source_rows = _read_semicolon_csv(source_companies_path)
    missing_rows = list(_iter_jsonl(missing_reports_path))
    result = augment_company_profiles_for_missing_reports(
        source_companies=source_rows,
        missing_reports=missing_rows,
        profile_source=profile_source or str(missing_reports_path),
    )
    output_companies_path.parent.mkdir(parents=True, exist_ok=True)
    _write_semicolon_csv(output_companies_path, result.fieldnames, result.rows)
    output_text = output_companies_path.read_text(encoding="utf-8")
    summary = {
        "source_companies_path": str(source_companies_path),
        "missing_reports_path": str(missing_reports_path),
        "output_companies_path": str(output_companies_path),
        "source_row_count": len(source_rows),
        "missing_report_count": len(missing_rows),
        "output_row_count": len(result.rows),
        "added_count": result.added_count,
        "copied_profile_count": result.copied_profile_count,
        "placeholder_profile_count": result.placeholder_profile_count,
        "output_sha256": _sha256_text(output_text),
    }
    if summary_path is not None:
        summary_path.parent.mkdir(parents=True, exist_ok=True)
        summary_text = json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        summary_path.write_text(summary_text, encoding="utf-8")
        summary["summary_path"] = str(summary_path)
        summary["summary_sha256"] = _sha256_text(summary_text)
    return summary


def _with_profile_metadata(row: Mapping[str, Any], *, quality: str, source: str) -> dict[str, Any]:
    result = dict(row)
    result.setdefault(PROFILE_QUALITY_FIELD, quality)
    result.setdefault(PROFILE_SOURCE_FIELD, source)
    return result


def _copy_existing_profile(
    profile: Mapping[str, Any],
    *,
    source_file: str,
    company_name: str,
    profile_source: str,
) -> dict[str, Any]:
    row = dict(profile)
    row["file"] = source_file
    row["company_data_company_name"] = company_name or profile.get("company_data_company_name", "")
    row[PROFILE_QUALITY_FIELD] = "copied_from_existing_company_profile"
    row[PROFILE_SOURCE_FIELD] = profile_source
    return row


def _placeholder_profile(
    *,
    fieldnames: Iterable[str],
    source_file: str,
    company_name: str,
    profile_source: str,
) -> dict[str, Any]:
    row = {field: "" for field in fieldnames}
    row.update(
        {
            "file": source_file,
            "company_data_company_name": company_name,
            "company_data_company_size": "UNKNOWN",
            "company_data_stock_listed": "",
            PROFILE_QUALITY_FIELD: "placeholder_missing_profile",
            PROFILE_SOURCE_FIELD: profile_source,
        }
    )
    return row


def _fieldnames(rows: list[Mapping[str, Any]]) -> list[str]:
    fieldnames: list[str] = []
    for row in rows:
        for field in row.keys():
            if field not in fieldnames:
                fieldnames.append(field)
    return fieldnames or ["file", "company_data_company_name"]


def _company_key(value: Any) -> str:
    normalized = unicodedata.normalize("NFKD", str(value or ""))
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii").lower()
    return " ".join(re.findall(r"[a-z0-9]+", ascii_text))


def _iter_jsonl(path: Path) -> Iterable[dict[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if line.strip():
                yield json.loads(line)


def _read_semicolon_csv(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle, delimiter=";"))


def _write_semicolon_csv(
    path: Path,
    fieldnames: list[str],
    rows: Iterable[Mapping[str, Any]],
) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, delimiter=";", lineterminator="\n")
        writer.writeheader()
        for row in rows:
            writer.writerow(row)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Augment company profiles for missing training reports")
    parser.add_argument("--source-companies", required=True, type=Path)
    parser.add_argument("--missing-reports", required=True, type=Path)
    parser.add_argument("--output-companies", required=True, type=Path)
    parser.add_argument("--summary", type=Path)
    parser.add_argument("--profile-source")
    args = parser.parse_args(argv)

    summary = augment_company_profiles_for_missing_reports_from_files(
        source_companies_path=args.source_companies,
        missing_reports_path=args.missing_reports,
        output_companies_path=args.output_companies,
        summary_path=args.summary,
        profile_source=args.profile_source,
    )
    print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
