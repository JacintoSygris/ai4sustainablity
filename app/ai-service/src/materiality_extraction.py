"""Deterministic materiality-zone detection primitives.

The full extractor will add structured PDF parsing and bounded LLM/vision
stages. This first slice is intentionally conservative: it finds candidate
zones and prompt-injection blockers without assigning materiality labels.
"""

from __future__ import annotations

import re
import unicodedata
from collections.abc import Iterable, Mapping
from typing import Any


PROMPT_INJECTION_PATTERNS = (
    re.compile(r"\bignore\s+(all\s+)?previous\s+instructions\b", re.IGNORECASE),
    re.compile(r"\bdisregard\s+(all\s+)?previous\s+instructions\b", re.IGNORECASE),
    re.compile(r"\bmark\s+all\b.+\b(material|materiality)\b", re.IGNORECASE),
    re.compile(r"\bclassify\s+all\b.+\b(material|not material)\b", re.IGNORECASE),
)

MATERIALITY_SIGNALS: tuple[tuple[str, str, float], ...] = (
    ("double materiality assessment", "dma_table_or_section", 0.9),
    ("doble materialidad", "dma_table_or_section", 0.9),
    ("double materiality", "dma_table_or_section", 0.82),
    ("material impacts, risks, and opportunities", "dma_table_or_section", 0.82),
    ("material impacts, risks and opportunities", "dma_table_or_section", 0.82),
    ("impactos, riesgos y oportunidades", "dma_table_or_section", 0.82),
    ("temas materiales", "dma_table_or_section", 0.8),
    ("impacts, risks, and opportunities", "dma_table_or_section", 0.78),
    ("impacts, risks and opportunities", "dma_table_or_section", 0.78),
    ("material topics", "dma_table_or_section", 0.78),
    ("matriz de materialidad", "materiality_matrix", 0.82),
    ("materiality matrix", "materiality_matrix", 0.82),
    ("materiality assessment", "materiality_section", 0.7),
    ("iro register", "iro_register", 0.78),
)

ESRS_STANDARD_CODE_PATTERN = re.compile(r"\b(?:ESRS\s+)?(?:E[1-5]|S[1-4]|G1)\b", re.IGNORECASE)
ESRS_DISCLOSURE_REQUIREMENT_PATTERN = re.compile(
    r"\b(?:E[1-5]|S[1-4]|G1)[\s\-\u2010\u2011\u2012\u2013\u2014]*\d+\b",
    re.IGNORECASE,
)
ESRS_DISCLOSURE_INDEX_PATTERNS = (
    re.compile(r"\bdisclosure requirements?\s+in\s+esrs\s+covered\b", re.IGNORECASE),
    re.compile(r"\besrs\s+covered\s+by\s+the\s+sustainability\s+statement\b", re.IGNORECASE),
    re.compile(r"\bdatapoints?\s+derived\s+from\s+other\s+eu\s+legislation\b", re.IGNORECASE),
)
ESRS_IRO_CONTEXT_PATTERN = re.compile(
    r"\b(iro|impact(?:s)?|risk(?:s)?|opportunit(?:y|ies)|material(?:ity| matters?| topics?)?)\b",
    re.IGNORECASE,
)
LOCALIZED_DOUBLE_MATERIALITY_PATTERN = re.compile(
    r"\b(?:double|doble|doppia|doppelte|dubbele|dupla|dual)\b.{0,80}"
    r"\b(?:materialit(?:y|e)?|materialidad|materialita|materialiteit|wesentlichkeit(?:sanalyse)?)\b",
    re.IGNORECASE,
)
LOCALIZED_IRO_PATTERNS = (
    re.compile(r"\bimpacts?\b.{0,50}\brisks?\b.{0,50}\bopportunit", re.IGNORECASE),
    re.compile(r"\bimpactos?\b.{0,50}\briesgos?\b.{0,50}\boportunidades?\b", re.IGNORECASE),
    re.compile(r"\bincidences?\b.{0,50}\brisques?\b.{0,50}\bopportunit", re.IGNORECASE),
    re.compile(r"\bauswirkungen?\b.{0,60}\brisiken?\b.{0,60}\bchancen\b", re.IGNORECASE),
    re.compile(r"\bimpatti?\b.{0,50}\brischi?\b.{0,50}\bopportunit", re.IGNORECASE),
    re.compile(r"\bimpact\b.{0,50}\brisico\b.{0,50}\bkansen\b", re.IGNORECASE),
)
LOCALIZED_SUSTAINABILITY_STATEMENT_PATTERN = re.compile(
    r"\b(?:sustainability statement|etat de durabilite|declaration de durabilite|"
    r"informe de sostenibilidad|declaracion de sostenibilidad|nachhaltigkeitserklarung|"
    r"dichiarazione di sostenibilita)\b",
    re.IGNORECASE,
)
LOCALIZED_MATERIALITY_PATTERN = re.compile(
    r"\b(?:materialit(?:y|e)?|materialidad|materialita|materialiteit|wesentlichkeit)\b",
    re.IGNORECASE,
)
CONTINUATION_TOPIC_TERMS = (
    "climate change",
    "pollution",
    "water and marine resources",
    "biodiversity",
    "resource use and circular economy",
    "circular economy",
    "own workforce",
    "workers in the value chain",
    "affected communities",
    "consumers and end-users",
    "business conduct",
)
CONTINUATION_CONTEXT_PATTERN = re.compile(
    r"\b("
    r"specific disclosures|disclosures broken down by scope|iro type|description of kpi|"
    r"material issues?|material sustainability matters?|sustainability matters?"
    r")\b",
    re.IGNORECASE,
)
CONTINUATION_SOURCE_ZONE_TYPES = {
    "dma_table_or_section",
    "materiality_matrix",
    "materiality_section",
    "iro_register",
}


def detect_materiality_zones(pages: Iterable[Mapping[str, Any]]) -> list[dict[str, Any]]:
    zones: list[dict[str, Any]] = []
    last_strong_materiality_zone: dict[str, Any] | None = None
    for page in pages:
        text = str(page.get("text", ""))
        if not text.strip() or _looks_like_contents_page(text):
            continue

        lowered = _normalize_text(text)
        signal = _best_signal(lowered, text)
        if signal is None:
            signal = _continuation_signal(
                normalized_text=lowered,
                raw_text=text,
                page_number=page.get("page_number"),
                previous_zone=last_strong_materiality_zone,
            )
        if signal is None:
            continue

        reason, zone_type, confidence = signal
        zone = {
            "zone_id": f"page-{page.get('page_number')}-{len(zones) + 1}",
            "page_number": page.get("page_number"),
            "zone_type": zone_type,
            "zone_confidence": confidence,
            "zone_detection_reason": reason,
            "text": text,
            "blockers": detect_prompt_injection_markers(text),
        }
        zones.append(zone)
        if zone_type in CONTINUATION_SOURCE_ZONE_TYPES and confidence >= 0.82:
            last_strong_materiality_zone = zone
    return zones


def detect_prompt_injection_markers(text: str) -> list[str]:
    if any(pattern.search(text) for pattern in PROMPT_INJECTION_PATTERNS):
        return ["prompt_injection_detected"]
    return []


def _best_signal(normalized_text: str, raw_text: str) -> tuple[str, str, float] | None:
    matches = [
        (f"matched phrase: {phrase}", zone_type, confidence)
        for phrase, zone_type, confidence in MATERIALITY_SIGNALS
        if phrase in normalized_text
    ]
    localized_signal = _localized_materiality_signal(raw_text)
    if localized_signal is not None:
        matches.append(localized_signal)
    deep_signal = _deep_esrs_signal(raw_text)
    if deep_signal is not None:
        matches.append(deep_signal)
    if not matches:
        return None
    return max(matches, key=lambda item: item[2])


def _localized_materiality_signal(text: str) -> tuple[str, str, float] | None:
    folded = _fold_text(text)
    standard_codes = ESRS_STANDARD_CODE_PATTERN.findall(folded)
    unique_standard_codes = {code.upper().replace("ESRS ", "") for code in standard_codes}
    has_iro = any(pattern.search(folded) for pattern in LOCALIZED_IRO_PATTERNS)

    if LOCALIZED_DOUBLE_MATERIALITY_PATTERN.search(folded) and (
        has_iro
        or len(unique_standard_codes) >= 2
        or LOCALIZED_SUSTAINABILITY_STATEMENT_PATTERN.search(folded)
    ):
        return (
            "localized double materiality or IRO language with sustainability context",
            "dma_table_or_section",
            0.86,
        )

    if has_iro and len(unique_standard_codes) >= 2:
        return (
            "localized IRO language with ESRS code density",
            "iro_register",
            0.8,
        )

    if (
        LOCALIZED_MATERIALITY_PATTERN.search(folded)
        and LOCALIZED_SUSTAINABILITY_STATEMENT_PATTERN.search(folded)
        and len(unique_standard_codes) >= 2
    ):
        return (
            "localized materiality language in sustainability statement",
            "materiality_section",
            0.76,
        )

    return None


def _continuation_signal(
    *,
    normalized_text: str,
    raw_text: str,
    page_number: Any,
    previous_zone: Mapping[str, Any] | None,
) -> tuple[str, str, float] | None:
    if previous_zone is None:
        return None
    try:
        current_page = int(page_number)
        previous_page = int(previous_zone.get("page_number"))
    except (TypeError, ValueError):
        return None
    if current_page <= previous_page or current_page - previous_page > 3:
        return None

    topic_count = sum(1 for term in CONTINUATION_TOPIC_TERMS if term in normalized_text)
    if topic_count < 3:
        return None
    if not CONTINUATION_CONTEXT_PATTERN.search(raw_text) and "esrs" not in normalized_text:
        return None

    return (
        "continuation of preceding double-materiality topic list",
        "dma_continuation",
        0.79,
    )


def _deep_esrs_signal(text: str) -> tuple[str, str, float] | None:
    standard_codes = ESRS_STANDARD_CODE_PATTERN.findall(text)
    disclosure_requirements = ESRS_DISCLOSURE_REQUIREMENT_PATTERN.findall(text)
    unique_standard_codes = {code.upper().replace("ESRS ", "") for code in standard_codes}

    if any(pattern.search(text) for pattern in ESRS_DISCLOSURE_INDEX_PATTERNS):
        if len(unique_standard_codes) >= 2 or len(disclosure_requirements) >= 2:
            return (
                "disclosure requirements covered table with ESRS code density",
                "esrs_disclosure_index",
                0.83,
            )

    if len(unique_standard_codes) >= 3 and ESRS_IRO_CONTEXT_PATTERN.search(text):
        return (
            "esrs topic-code density in IRO/materiality context",
            "iro_register",
            0.84,
        )

    if len(unique_standard_codes) >= 5 and len(disclosure_requirements) >= 3:
        return (
            "esrs topic-code density with disclosure-requirement references",
            "esrs_disclosure_index",
            0.8,
        )

    return None


def _looks_like_contents_page(text: str) -> bool:
    head_raw = text[:500]
    head = _normalize_text(head_raw)
    head_lines = [
        _normalize_text(line)
        for line in head_raw.splitlines()
        if _normalize_text(line)
    ]
    contents_markers = {
        "contents",
        "table of contents",
        "indice",
        "indice de contenidos",
    }
    has_contents_marker = (
        "table of contents" in head
        or bool(re.search(r"^(?:[ivx]+\s+)?contents\b", head))
    ) or any(
        line in contents_markers for line in head_lines[:5]
    )
    if not has_contents_marker:
        return False
    dotted_leaders = len(re.findall(r"\.{3,}\s*\d+", text))
    if dotted_leaders > 0:
        return True
    if "click on the text to go to the page" in head:
        return True
    numbered_entries = len(re.findall(r"\b\d{1,3}\s+[A-Z][A-Za-z,& -]{3,}", head_raw))
    return numbered_entries >= 5


def _normalize_text(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip().lower()


def _fold_text(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text or "")
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii")
    return _normalize_text(ascii_text)
