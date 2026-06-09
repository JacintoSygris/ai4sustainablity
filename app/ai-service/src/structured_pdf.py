"""Structured PDF artifacts for materiality extraction."""

from __future__ import annotations

import hashlib
from pathlib import Path
from typing import Any


def build_structured_pdf_artifact(
    *,
    pdf_path: Path,
    source_file: str,
    report_url: str,
    report_year: int | None = None,
    max_pages: int = 0,
) -> dict[str, Any]:
    pages, page_count = _read_pdf_pages(pdf_path=pdf_path, max_pages=max_pages)
    return {
        "pdf_path": str(pdf_path),
        "pdf_sha256": _sha256_file(pdf_path),
        "source_file": source_file,
        "report_url": report_url,
        "report_year": report_year,
        "page_count": page_count,
        "pages_extracted": len(pages),
        "pages": pages,
    }


def extract_structured_pdf_pages(
    *,
    pdf_path: Path,
    max_pages: int = 0,
) -> list[dict[str, Any]]:
    pages, _page_count = _read_pdf_pages(pdf_path=pdf_path, max_pages=max_pages)
    return pages


def _read_pdf_pages(*, pdf_path: Path, max_pages: int) -> tuple[list[dict[str, Any]], int]:
    import fitz

    pages: list[dict[str, Any]] = []
    with fitz.open(pdf_path) as document:
        page_count = document.page_count
        limit = page_count if max_pages <= 0 else min(max_pages, page_count)
        for page_index in range(limit):
            page = document.load_page(page_index)
            text = page.get_text("text")
            pages.append(
                {
                    "page_number": page_index + 1,
                    "text": text,
                    "text_yield_chars": len(text.strip()),
                    "blocks": _extract_text_blocks(page),
                }
            )
    return pages, page_count


def _extract_text_blocks(page) -> list[dict[str, Any]]:
    blocks: list[dict[str, Any]] = []
    for raw_block in page.get_text("blocks"):
        x0, y0, x1, y1, text, *_rest = raw_block
        if not str(text).strip():
            continue
        blocks.append(
            {
                "bbox": {
                    "x0": round(float(x0), 2),
                    "y0": round(float(y0), 2),
                    "x1": round(float(x1), 2),
                    "y1": round(float(y1), 2),
                },
                "text": str(text),
            }
        )
    return blocks


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()
