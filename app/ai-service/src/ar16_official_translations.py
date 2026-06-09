"""Download and extract official AR16 topic translations from EU sources.

The source is the Publications Office download handler for consolidated
Delegated Regulation (EU) 2023/2772. EUR-Lex itself may return a WAF challenge
to non-browser clients; the Publications Office exposes the same Cellar files.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable
from xml.etree import ElementTree as ET


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
PROJECT_APP_ROOT = AI_SERVICE_ROOT.parent
OFFICIAL_PUBLICATION_ID = "3e901799-1124-11ef-a251-01aa75ed71a1"
OFFICIAL_DOWNLOAD_BASE = "https://op.europa.eu/o/opportal-service/download-handler"
OFFICIAL_EU_LANGUAGE_CODES = (
    "bg",
    "es",
    "cs",
    "da",
    "de",
    "et",
    "el",
    "en",
    "fr",
    "ga",
    "hr",
    "it",
    "lv",
    "lt",
    "hu",
    "mt",
    "nl",
    "pl",
    "pt",
    "ro",
    "sk",
    "sl",
    "fi",
    "sv",
)
DEFAULT_RAW_DIR = (
    AI_SERVICE_ROOT
    / "training_data"
    / "official_sources"
    / "eurlex_32023R2772_ar16"
    / "fmx4"
)
DEFAULT_OUTPUT_CSV = PROJECT_APP_ROOT / "contracts" / "mappings" / "ar16-official-topic-translations.csv"
DEFAULT_OUTPUT_MANIFEST = (
    PROJECT_APP_ROOT / "contracts" / "mappings" / "ar16-official-topic-translations.manifest.json"
)
DEFAULT_CANONICAL_INVENTORY = PROJECT_APP_ROOT / "contracts" / "mappings" / "web-ar16-topic-inventory.csv"


def build_download_url(language: str, file_format: str = "fmx4") -> str:
    return (
        f"{OFFICIAL_DOWNLOAD_BASE}?identifier={OFFICIAL_PUBLICATION_ID}"
        f"&format={file_format}&language={language}&productionSystem=cellar&part="
    )


def download_official_fmx4_languages(
    *,
    languages: Iterable[str] = OFFICIAL_EU_LANGUAGE_CODES,
    output_dir: Path = DEFAULT_RAW_DIR,
) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)
    rows: list[dict[str, Any]] = []
    for language in languages:
        language = language.lower()
        output_path = output_dir / f"32023R2772-{language}.fmx4"
        url = build_download_url(language, "fmx4")
        status = "downloaded"
        if output_path.exists() and output_path.stat().st_size > 0:
            status = "already_present"
        else:
            request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
            with urllib.request.urlopen(request, timeout=120) as response:  # noqa: S310 - trusted EU URL.
                payload = response.read()
            if len(payload) < 100_000:
                raise RuntimeError(f"Unexpectedly small EU FMX4 download for {language}: {len(payload)} bytes")
            output_path.write_bytes(payload)

        rows.append(
            {
                "language": language,
                "status": status,
                "path": str(output_path),
                "bytes": output_path.stat().st_size,
                "sha256": _sha256_file(output_path),
                "source_url": url,
            }
        )
    return {
        "downloaded_at": datetime.now(timezone.utc).isoformat(),
        "publication_id": OFFICIAL_PUBLICATION_ID,
        "source": "Publications Office of the European Union Cellar download handler",
        "language_count": len(rows),
        "files": rows,
    }


def parse_ar16_rows_from_fmx4(path: Path) -> list[dict[str, str]]:
    xml_text = _read_main_fmx_xml(path)
    table_rows = _extract_ar16_table_rows(xml_text)
    return flatten_ar16_table_rows(table_rows)


def flatten_ar16_table_rows(rows: Iterable[dict[int, list[str]]]) -> list[dict[str, str]]:
    flattened: list[dict[str, str]] = []
    current_esrs = ""
    current_theme = ""
    for row in rows:
        col1 = row.get(1, [])
        col2 = row.get(2, [])
        col3 = [_clean_item(value) for value in row.get(3, []) if _clean_item(value)]
        col4 = [_clean_item(value) for value in row.get(4, []) if _clean_item(value)]
        explicit_esrs = _extract_esrs_code(col1)
        if explicit_esrs:
            current_esrs = explicit_esrs
            if col2 and _clean_item(col2[0]):
                current_theme = _clean_item(col2[0])

        if not current_esrs or not col3:
            continue

        if current_esrs in {"E1", "E2", "E5"}:
            for subtheme in col3:
                flattened.append(_row(current_esrs, current_theme, subtheme, ""))
            continue

        if current_esrs == "E3" and explicit_esrs:
            _append_e3_rows(flattened, current_esrs, current_theme, col3, col4)
            continue

        if current_esrs == "E4":
            if explicit_esrs and col4:
                for subtopic in col4:
                    flattened.append(_row(current_esrs, current_theme, col3[0], subtopic))
            else:
                # In AR16 E4, later rows list examples in column 4. Those examples
                # are explanatory text, not extra AR16 sub-subtopics.
                flattened.append(_row(current_esrs, current_theme, col3[0], ""))
            continue

        if current_esrs in {"S1", "S2", "S3", "S4"}:
            for subtopic in col4:
                flattened.append(_row(current_esrs, current_theme, col3[0], subtopic))
            continue

        if current_esrs == "G1":
            if col4:
                for subtopic in col4:
                    flattened.append(_row(current_esrs, current_theme, col3[0], subtopic))
            else:
                for subtheme in col3:
                    flattened.append(_row(current_esrs, current_theme, subtheme, ""))

    return flattened


def parse_all_downloaded_languages(
    *,
    raw_dir: Path = DEFAULT_RAW_DIR,
    languages: Iterable[str] = OFFICIAL_EU_LANGUAGE_CODES,
) -> dict[str, list[dict[str, str]]]:
    translations: dict[str, list[dict[str, str]]] = {}
    for language in languages:
        path = raw_dir / f"32023R2772-{language}.fmx4"
        if not path.exists():
            raise FileNotFoundError(f"Missing official FMX4 file for {language}: {path}")
        translations[language] = parse_ar16_rows_from_fmx4(path)
    return translations


def load_canonical_inventory(path: Path = DEFAULT_CANONICAL_INVENTORY) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def write_official_translation_csv(
    *,
    translations_by_language: dict[str, list[dict[str, str]]],
    canonical_rows: list[dict[str, str]],
    output_path: Path = DEFAULT_OUTPUT_CSV,
) -> dict[str, Any]:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "ar16_index",
        "canonical_esrs",
        "language",
        "official_esrs",
        "official_theme",
        "official_subtheme",
        "official_subtopic",
        "source_publication_id",
        "source_url",
    ]
    rows: list[dict[str, str]] = []
    for language, translated_rows in sorted(translations_by_language.items()):
        if len(translated_rows) != len(canonical_rows):
            raise ValueError(
                f"Official AR16 row count mismatch for {language}: "
                f"{len(translated_rows)} != {len(canonical_rows)}"
            )
        for canonical, translated in zip(canonical_rows, translated_rows):
            canonical_esrs = str(canonical.get("esrs") or "")
            official_esrs = str(translated.get("esrs") or "")
            if canonical_esrs and official_esrs and canonical_esrs != official_esrs:
                raise ValueError(
                    f"ESRS sequence mismatch for {language} AR16 {canonical.get('ar16_index')}: "
                    f"{official_esrs} != {canonical_esrs}"
                )
            rows.append(
                {
                    "ar16_index": str(canonical.get("ar16_index") or ""),
                    "canonical_esrs": canonical_esrs,
                    "language": language,
                    "official_esrs": official_esrs,
                    "official_theme": str(translated.get("theme") or ""),
                    "official_subtheme": str(translated.get("subtheme") or ""),
                    "official_subtopic": str(translated.get("subtopic") or ""),
                    "source_publication_id": OFFICIAL_PUBLICATION_ID,
                    "source_url": build_download_url(language, "fmx4"),
                }
            )

    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    return {
        "output_path": str(output_path),
        "row_count": len(rows),
        "language_count": len(translations_by_language),
        "ar16_row_count": len(canonical_rows),
        "sha256": _sha256_file(output_path),
    }


def write_manifest(
    *,
    download_summary: dict[str, Any] | None,
    export_summary: dict[str, Any],
    output_path: Path = DEFAULT_OUTPUT_MANIFEST,
) -> dict[str, Any]:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema_version": "ar16-official-translations-v1",
        "created_at": datetime.now(timezone.utc).isoformat(),
        "publication_id": OFFICIAL_PUBLICATION_ID,
        "source": "Publications Office of the European Union Cellar download handler",
        "download_summary": download_summary,
        "export_summary": export_summary,
    }
    output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return {**payload, "manifest_path": str(output_path), "manifest_sha256": _sha256_file(output_path)}


def _read_main_fmx_xml(path: Path) -> str:
    with zipfile.ZipFile(path) as archive:
        xml_names = [
            name
            for name in archive.namelist()
            if name.lower().endswith(".xml") and ".doc." not in name.lower()
        ]
        if not xml_names:
            raise ValueError(f"No main FMX XML found in {path}")
        name = max(xml_names, key=lambda item: archive.getinfo(item).file_size)
        return archive.read(name).decode("utf-8", errors="replace")


def _extract_ar16_table_rows(xml_text: str) -> list[dict[int, list[str]]]:
    root = ET.fromstring(xml_text)
    for table in root.findall(".//TBL"):
        rows = [_cell_map(row) for row in table.findall(".//ROW")]
        if _looks_like_ar16_table(rows):
            return rows
    raise ValueError("AR16 sustainability matters table not found in official FMX XML")


def _looks_like_ar16_table(rows: list[dict[int, list[str]]]) -> bool:
    all_text = " ".join(" ".join(" ".join(values) for values in row.values()) for row in rows)
    has_headers = (
        "Sub-topic" in all_text
        or "Subtema" in all_text
        or "Sub-sub" in all_text
        or "Subsub" in all_text
    )
    standards = {_extract_esrs_code(row.get(1, [])) for row in rows}
    standards.discard("")
    has_all_topical_standards = (
        {"E1", "E2", "E3", "E4", "E5", "S1", "S2", "S3", "S4", "G1"} <= standards
    )
    has_topic_columns = any(row.get(3) for row in rows) and any(row.get(4) for row in rows)
    return has_all_topical_standards and has_topic_columns and (has_headers or 20 <= len(rows) <= 30)


def _cell_map(row: ET.Element) -> dict[int, list[str]]:
    cells: dict[int, list[str]] = {}
    for cell in row.findall("./CELL"):
        col = int(cell.get("COL", "0") or 0)
        cells[col] = _cell_items(cell)
    return cells


def _cell_items(cell: ET.Element) -> list[str]:
    items = [_element_text(item) for item in cell.findall(".//ITEM")]
    items = [item for item in items if item]
    if items:
        return items
    value = _element_text(cell)
    return [value] if value else []


def _element_text(element: ET.Element) -> str:
    return " ".join("".join(element.itertext()).split())


def _clean_item(value: str) -> str:
    return re.sub(r"^[-\u2013\u2014\s]+", "", value or "").strip()


def _extract_esrs_code(values: Iterable[str]) -> str:
    joined = " ".join(values)
    joined = joined.translate(str.maketrans({"Е": "E", "е": "e", "Ε": "E", "ε": "e"}))
    match = re.search(r"\b(?:ESRS|NEIS)?\s*(E[1-5]|S[1-4]|G1)\b", joined)
    return match.group(1) if match else ""


def _append_e3_rows(
    rows: list[dict[str, str]],
    esrs: str,
    theme: str,
    subthemes: list[str],
    subtopics: list[str],
) -> None:
    if len(subthemes) < 2 or len(subtopics) < 5:
        raise ValueError("Unexpected E3 AR16 official table shape")
    for subtopic in subtopics[:4]:
        rows.append(_row(esrs, theme, subthemes[0], subtopic))
    rows.append(_row(esrs, theme, subthemes[1], subtopics[4]))


def _row(esrs: str, theme: str, subtheme: str, subtopic: str) -> dict[str, str]:
    return {
        "esrs": esrs,
        "theme": theme,
        "subtheme": subtheme,
        "subtopic": subtopic,
    }


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Download/export official EU AR16 topic translations.")
    parser.add_argument("--raw-dir", type=Path, default=DEFAULT_RAW_DIR)
    parser.add_argument("--canonical-inventory", type=Path, default=DEFAULT_CANONICAL_INVENTORY)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT_CSV)
    parser.add_argument("--manifest", type=Path, default=DEFAULT_OUTPUT_MANIFEST)
    parser.add_argument("--languages", nargs="*", default=list(OFFICIAL_EU_LANGUAGE_CODES))
    parser.add_argument("--download", action="store_true")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    download_summary = None
    if args.download:
        download_summary = download_official_fmx4_languages(
            languages=args.languages,
            output_dir=args.raw_dir,
        )
    translations = parse_all_downloaded_languages(raw_dir=args.raw_dir, languages=args.languages)
    canonical_rows = load_canonical_inventory(args.canonical_inventory)
    export_summary = write_official_translation_csv(
        translations_by_language=translations,
        canonical_rows=canonical_rows,
        output_path=args.output,
    )
    manifest = write_manifest(
        download_summary=download_summary,
        export_summary=export_summary,
        output_path=args.manifest,
    )
    print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
