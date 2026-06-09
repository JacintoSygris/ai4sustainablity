"""Build review queues for materiality zones detected in report PDFs."""

from __future__ import annotations

import hashlib
import json
from collections.abc import Iterable, Mapping
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from materiality_extraction import detect_materiality_zones
from structured_pdf import build_structured_pdf_artifact


MATERIALITY_REVIEW_QUEUE_SCHEMA_VERSION = "materiality-review-queue-v1"


def build_materiality_zone_review_queue(
    *,
    reports: Iterable[Mapping[str, Any]],
    output_jsonl: Path,
    max_pages: int = 0,
    run_id: str | None = None,
    created_at: str | None = None,
) -> dict[str, int | str]:
    output_jsonl.parent.mkdir(parents=True, exist_ok=True)
    failures_path = output_jsonl.with_suffix(".failures.json")
    provenance_path = output_jsonl.with_suffix(".provenance.json")

    rows: list[dict[str, Any]] = []
    failures: list[dict[str, Any]] = []
    reports_processed = 0
    for report in reports:
        reports_processed += 1
        try:
            pdf_path = Path(report["pdf_path"])
            artifact = build_structured_pdf_artifact(
                pdf_path=pdf_path,
                source_file=str(report.get("source_file") or pdf_path.name),
                report_url=str(report.get("report_url") or f"file:{pdf_path.name}"),
                report_year=report.get("report_year"),
                max_pages=max_pages,
            )
            zones = detect_materiality_zones(artifact["pages"])
            for zone in zones:
                rows.append(_zone_review_row(report=report, artifact=artifact, zone=zone))
        except Exception as exc:  # noqa: BLE001 - batch resilience requires durable per-report failures.
            failures.append(_failure_row(report=report, error=exc))

    output_text = "".join(
        json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n" for row in rows
    )
    output_jsonl.write_text(output_text, encoding="utf-8")
    output_sha256 = _sha256_text(output_text)

    failures_payload = {
        "schema_version": MATERIALITY_REVIEW_QUEUE_SCHEMA_VERSION,
        "run_id": run_id or output_jsonl.stem,
        "created_at": created_at or datetime.now(timezone.utc).isoformat(),
        "failures": failures,
    }
    failures_text = json.dumps(
        failures_payload,
        ensure_ascii=False,
        indent=2,
        sort_keys=True,
    )
    failures_path.write_text(failures_text, encoding="utf-8")
    failures_sha256 = _sha256_text(failures_text)

    provenance = {
        "schema_version": MATERIALITY_REVIEW_QUEUE_SCHEMA_VERSION,
        "run_id": run_id or output_jsonl.stem,
        "created_at": failures_payload["created_at"],
        "report_count": reports_processed,
        "zones_count": len(rows),
        "failures_count": len(failures),
        "max_pages": max_pages,
        "output_jsonl": str(output_jsonl),
        "output_jsonl_sha256": output_sha256,
        "failures_path": str(failures_path),
        "failures_sha256": failures_sha256,
    }
    provenance_path.write_text(
        json.dumps(provenance, ensure_ascii=False, indent=2, sort_keys=True),
        encoding="utf-8",
    )

    return {
        "reports_processed": reports_processed,
        "zones_written": len(rows),
        "failures_count": len(failures),
        "output_jsonl": str(output_jsonl),
        "output_jsonl_sha256": output_sha256,
        "failures_path": str(failures_path),
        "provenance_path": str(provenance_path),
    }


def _zone_review_row(
    *,
    report: Mapping[str, Any],
    artifact: Mapping[str, Any],
    zone: Mapping[str, Any],
) -> dict[str, Any]:
    row_seed = "|".join(
        [
            str(artifact["pdf_sha256"]),
            str(zone.get("zone_id")),
            str(zone.get("page_number")),
            str(zone.get("zone_type")),
            str(artifact.get("source_file", "")),
            str(artifact.get("report_url", "")),
            str(artifact.get("report_year", "")),
        ]
    )
    return {
        "review_row_id": hashlib.sha1(row_seed.encode("utf-8")).hexdigest(),
        "review_status": "needs_review",
        "company_name": report.get("company_name", ""),
        "source_file": artifact["source_file"],
        "report_url": artifact["report_url"],
        "report_year": artifact.get("report_year"),
        "pdf_sha256": artifact["pdf_sha256"],
        "page_number": zone.get("page_number"),
        "zone_id": zone.get("zone_id"),
        "zone_type": zone.get("zone_type"),
        "zone_confidence": zone.get("zone_confidence"),
        "zone_detection_reason": zone.get("zone_detection_reason"),
        "blockers": list(zone.get("blockers", [])),
        "excerpt": _excerpt(str(zone.get("text", ""))),
    }


def _excerpt(text: str, width: int = 600) -> str:
    compact = " ".join(text.split())
    return compact[:width]


def _failure_row(*, report: Mapping[str, Any], error: Exception) -> dict[str, Any]:
    pdf_path = report.get("pdf_path")
    source_file = report.get("source_file") or (Path(pdf_path).name if pdf_path else "")
    report_url = report.get("report_url") or (f"file:{source_file}" if source_file else "")
    return {
        "company_name": report.get("company_name", ""),
        "source_file": str(source_file),
        "report_url": str(report_url),
        "report_year": report.get("report_year"),
        "error_type": type(error).__name__,
        "error_message": _public_error_message(error=error, source_file=str(source_file)),
    }


def _sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _public_error_message(*, error: Exception, source_file: str) -> str:
    target = source_file or "report"
    return f"{type(error).__name__}: failed to process {target}"
