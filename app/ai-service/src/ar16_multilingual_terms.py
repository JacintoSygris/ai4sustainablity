"""Shared loader for official AR16 topic translations.

The generated CSV keeps the official Publications Office text. This helper
adds matching-safe variants, for example removing layout hyphens introduced by
FMX extraction, while preserving the raw official display string for evidence.
"""

from __future__ import annotations

import csv
import re
import unicodedata
from pathlib import Path
from typing import Any, Iterable, Mapping


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
PROJECT_APP_ROOT = AI_SERVICE_ROOT.parent
DEFAULT_OFFICIAL_TRANSLATIONS_PATH = (
    PROJECT_APP_ROOT / "contracts" / "mappings" / "ar16-official-topic-translations.csv"
)

OFFICIAL_TEXT_FIELDS = (
    ("official_theme", "official_theme"),
    ("official_subtheme", "official_subtheme"),
    ("official_subtopic", "official_subtopic"),
)

KNOWN_OFFICIAL_LAYOUT_VARIANTS = {
    "ncidente": ["incidente"],
    "și depistarea, inclusiv formarea prevenirea": [
        "prevenirea și depistarea, inclusiv formarea",
    ],
}


def load_official_terms_by_ar16_id(
    path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> dict[int, list[dict[str, Any]]]:
    if not path.is_file():
        return {}

    terms_by_id: dict[int, list[dict[str, Any]]] = {}
    seen: set[tuple[int, str, str, str]] = set()
    with path.open(encoding="utf-8-sig", newline="") as handle:
        for row in csv.DictReader(handle):
            try:
                ar16_index = int(str(row.get("ar16_index") or "").strip())
            except ValueError:
                continue
            language = str(row.get("language") or "").strip().lower()
            has_subtopic = bool(str(row.get("official_subtopic") or "").strip())
            for csv_field, source_role in OFFICIAL_TEXT_FIELDS:
                raw_display = _clean_display(row.get(csv_field))
                if not raw_display:
                    continue
                for normalized in term_variants(raw_display):
                    display = raw_display if normalized == normalize_term(raw_display) else normalized
                    key = (ar16_index, language, source_role, normalized)
                    if key in seen:
                        continue
                    seen.add(key)
                    terms_by_id.setdefault(ar16_index, []).append(
                        {
                            "ar16_index": ar16_index,
                            "language": language,
                            "source_role": source_role,
                            "role": source_role,
                            "display": display,
                            "official_display": raw_display,
                            "normalized": normalized,
                            "ar16_has_subtopic": has_subtopic,
                        }
                    )
    return terms_by_id


def official_terms_for_mapping_row(
    row: Mapping[str, Any],
    *,
    official_terms_by_ar16_id: Mapping[int, list[dict[str, Any]]] | None = None,
    include_parent: bool = True,
    include_child: bool = True,
) -> list[dict[str, Any]]:
    terms_by_id = official_terms_by_ar16_id
    if terms_by_id is None:
        terms_by_id = load_official_terms_by_ar16_id()

    output: list[dict[str, Any]] = []
    seen: set[tuple[str, str]] = set()
    for ar16_index in _ar16_topic_ids(row):
        for term in terms_by_id.get(ar16_index, []):
            role = _mapping_role_for_official_term(term)
            is_child = role.endswith("_child")
            is_parent = role.endswith("_parent")
            if (is_child and not include_child) or (is_parent and not include_parent):
                continue
            key = (str(term.get("normalized") or ""), role)
            if not key[0] or key in seen:
                continue
            seen.add(key)
            output.append({**term, "role": role})
    return output


def term_variants(value: str) -> list[str]:
    base = normalize_term(value)
    if not base:
        return []
    variants = [base]
    dehyphenated = re.sub(r"(?<=\w)-\s*(?=\w)", "", base)
    spaced = re.sub(r"(?<=\w)-\s*(?=\w)", " ", base)
    ascii_folded = _ascii_fold(base)
    layout_repairs = KNOWN_OFFICIAL_LAYOUT_VARIANTS.get(base, [])
    for candidate in (
        dehyphenated,
        spaced,
        ascii_folded,
        _ascii_fold(dehyphenated),
        _ascii_fold(spaced),
        *layout_repairs,
    ):
        candidate = normalize_term(candidate)
        if candidate and candidate not in variants:
            variants.append(candidate)
    return variants


def normalize_term(value: Any) -> str:
    text = unicodedata.normalize("NFKC", str(value or ""))
    text = text.replace("\u00ad", "")
    text = re.sub(r"[\u2010\u2011\u2012\u2013\u2014\u2212]", "-", text)
    text = re.sub(r"\s*-\s*", "-", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip().lower()


def _mapping_role_for_official_term(term: Mapping[str, Any]) -> str:
    source_role = str(term.get("source_role") or "")
    if source_role == "official_theme":
        return "official_theme_parent"
    if source_role == "official_subtopic":
        return "official_subtopic_child"
    if source_role == "official_subtheme" and term.get("ar16_has_subtopic"):
        return "official_subtheme_parent"
    if source_role == "official_subtheme":
        return "official_subtheme_child"
    return source_role


def _ar16_topic_ids(row: Mapping[str, Any]) -> list[int]:
    ids = row.get("ar16_topic_ids") or []
    if isinstance(ids, str):
        ids = [item.strip() for item in ids.split(",") if item.strip()]
    output: list[int] = []
    for value in ids:
        try:
            output.append(int(value))
        except (TypeError, ValueError):
            continue
    return output


def _clean_display(value: Any) -> str:
    return re.sub(r"\s+", " ", str(value or "").strip())


def _ascii_fold(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value)
    return normalized.encode("ascii", "ignore").decode("ascii")
