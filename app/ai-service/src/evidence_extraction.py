import argparse
import csv
import hashlib
import json
import re
import unicodedata
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Iterable

from ar16_multilingual_terms import (
    DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
    load_official_terms_by_ar16_id,
    official_terms_for_mapping_row,
)


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_2024_COMPANIES = AI_SERVICE_ROOT / "training_data" / "new_format" / "gpt41" / "companies_gpt41_clean.csv"
DEFAULT_2024_ESRS = AI_SERVICE_ROOT / "training_data" / "new_format" / "gpt41" / "esrs_gpt41.csv"
DEFAULT_SOURCE_REPORTS_ROOT = AI_SERVICE_ROOT / "training_data" / "source_reports"
DEFAULT_REFRESH_ROOT = AI_SERVICE_ROOT / "training_data" / "refresh_2025"
DEFAULT_2024_CATALOGS = [DEFAULT_REFRESH_ROOT / "catalog" / "efrag-2024-api-companies.json"]
DEFAULT_2024_DOWNLOAD_LOG_ROOT = DEFAULT_SOURCE_REPORTS_ROOT / "efrag_2024"
DEFAULT_MAPPING_PATH = AI_SERVICE_ROOT.parent / "web" / "data" / "ar16_to_python_esrs_mapping_new_format_732_v1.json"
DEFAULT_EVIDENCE_ROOT = AI_SERVICE_ROOT / "training_data" / "evidence"
DEFAULT_2024_URL_OVERRIDES = [DEFAULT_EVIDENCE_ROOT / "validated-source-urls-2024.json"]
LEGAL_TOKENS = {
    "a",
    "ab",
    "ag",
    "as",
    "asa",
    "bv",
    "b",
    "co",
    "company",
    "corp",
    "corporation",
    "group",
    "holding",
    "holdings",
    "inc",
    "kgaa",
    "limited",
    "ltd",
    "n",
    "nv",
    "oyj",
    "plc",
    "s",
    "sa",
    "se",
    "spa",
    "v",
}


@dataclass(frozen=True)
class EvidenceTarget:
    report_year: int
    company_name: str
    source_file: str
    pdf_path: str
    report_url: str
    positive_esrs_keys: list[str]
    cohort: str


def read_csv_rows(path: Path) -> list[dict]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle, delimiter=";"))


def load_positive_labels(esrs_path: Path) -> dict[str, list[str]]:
    rows = read_csv_rows(esrs_path)
    labels: dict[str, list[str]] = {}
    for row in rows:
        source_file = (row.get("file") or "").strip()
        if not source_file:
            continue
        labels[source_file] = [
            key
            for key, value in row.items()
            if key.startswith("esrs_") and str(value).strip() in {"1", "1.0", "true", "True"}
        ]
    return labels


def load_esrs_keys(esrs_path: Path = DEFAULT_2024_ESRS) -> list[str]:
    with esrs_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.reader(handle, delimiter=";")
        header = next(reader)
    return [column for column in header if column.startswith("esrs_")]


def normalize_path_key(path: str | Path) -> str:
    try:
        return str(Path(path).resolve()).lower()
    except Exception:
        return str(path).replace("\\", "/").lower()


def is_public_url(value: str | None) -> bool:
    return bool(value and value.startswith(("http://", "https://")))


def clean_reference_value(value: str | None) -> str:
    return " ".join(str(value or "").split())


def fallback_report_reference(company_name: str | None, source_file: str | None) -> str:
    company = clean_reference_value(company_name)
    if company:
        return f"company:{company}"
    filename = clean_reference_value(Path(source_file or "").name)
    if filename:
        return f"file:{filename}"
    return ""


def is_report_reference(value: str | None) -> bool:
    if not value:
        return False
    if value.startswith("company:"):
        return bool(clean_reference_value(value.removeprefix("company:")))
    if value.startswith("file:") and not value.startswith("file://"):
        filename = clean_reference_value(value.removeprefix("file:"))
        return bool(filename and Path(filename).name == filename)
    return False


def is_report_locator(value: str | None) -> bool:
    return is_public_url(value) or is_report_reference(value)


def company_key(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value or "")
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii").lower()
    ascii_text = re.sub(r"[/&+]", " ", ascii_text)
    tokens = re.findall(r"[a-z0-9]+", ascii_text)
    filtered = [token for token in tokens if token not in LEGAL_TOKENS]
    return " ".join(filtered or tokens)


def company_keys(company_name: str, source_file: str) -> set[str]:
    keys = {
        company_key(company_name),
        company_key(Path(source_file).stem.replace("_", " ")),
    }
    return {key for key in keys if key}


def load_metadata_url_index(metadata_paths: Iterable[Path]) -> tuple[dict[str, str], dict[str, str]]:
    by_path: dict[str, str] = {}
    by_name: dict[str, str] = {}
    name_counts: dict[str, int] = {}
    raw_name_urls: dict[str, str] = {}

    for metadata_path in metadata_paths:
        if not metadata_path.is_file():
            continue
        data = json.loads(metadata_path.read_text(encoding="utf-8"))
        for url, info in (data.get("urls") or {}).items():
            if not is_public_url(url):
                continue
            raw_path = info.get("path")
            if raw_path:
                by_path[normalize_path_key(raw_path)] = url
                name = Path(raw_path).name.lower()
                name_counts[name] = name_counts.get(name, 0) + 1
                raw_name_urls[name] = url

    for name, count in name_counts.items():
        if count == 1:
            by_name[name] = raw_name_urls[name]

    return by_path, by_name


def load_catalog_url_index(catalog_paths: Iterable[Path]) -> dict[str, str]:
    urls: dict[str, str] = {}
    for catalog_path in catalog_paths:
        if not catalog_path.is_file():
            continue
        data = json.loads(catalog_path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            items = data.get("companies") or []
        elif isinstance(data, list):
            items = data
        else:
            items = []
        for item in items:
            if not isinstance(item, dict) or item.get("correct_report") is False:
                continue
            name = item.get("name") or item.get("company") or item.get("company_name") or ""
            report_url = item.get("report_url") or item.get("documentUrl") or item.get("url") or ""
            key = company_key(name)
            if key and is_public_url(report_url) and key not in urls:
                urls[key] = report_url
    return urls


def iter_download_log_rows(log_path: Path) -> Iterable[dict]:
    if not log_path.is_file():
        return
    if log_path.suffix.lower() == ".jsonl":
        for line in log_path.read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                continue
            if isinstance(row, dict):
                yield row
        return

    data = json.loads(log_path.read_text(encoding="utf-8"))
    if isinstance(data, dict):
        rows = data.get("results") or data.get("urls") or []
    elif isinstance(data, list):
        rows = data
    else:
        rows = []
    for row in rows:
        if isinstance(row, dict):
            yield row


def is_reliable_pdf_download_row(row: dict) -> bool:
    status = str(row.get("status") or row.get("result") or "").lower()
    content_type = str(row.get("content_type") or "").lower()
    path_name = Path(str(row.get("path") or row.get("final_path") or "")).name.lower()
    expected_name = str(row.get("expected") or row.get("expected_filename") or "").lower()
    has_pdf_name = path_name.endswith(".pdf") or expected_name.endswith(".pdf")
    has_pdf_signal = (
        row.get("pdf_magic") is True
        or status in {"downloaded_pdf", "installed"}
        or "application/pdf" in content_type
    )
    return has_pdf_name and has_pdf_signal


def load_download_log_url_index(log_paths: Iterable[Path]) -> dict[str, str]:
    by_name: dict[str, str] = {}
    for log_path in log_paths:
        for row in iter_download_log_rows(log_path):
            if not is_reliable_pdf_download_row(row):
                continue
            report_url = row.get("final_url") or row.get("url") or row.get("target_url") or row.get("source_url")
            if not is_public_url(report_url):
                continue
            names = [
                row.get("expected"),
                row.get("expected_filename"),
                Path(str(row.get("path") or "")).name,
                Path(str(row.get("final_path") or "")).name,
            ]
            for name in names:
                name = str(name or "").strip().lower()
                if name.endswith(".pdf") and name not in by_name:
                    by_name[name] = report_url
    return by_name


def load_url_override_index(override_paths: Iterable[Path]) -> dict[str, str]:
    urls: dict[str, str] = {}
    allowed_validations = {"sha256_match", "text_fingerprint_match", "manual_verified"}
    for override_path in override_paths:
        if not override_path.is_file():
            continue
        data = json.loads(override_path.read_text(encoding="utf-8"))
        if isinstance(data, dict) and isinstance(data.get("urls"), list):
            rows = data["urls"]
        elif isinstance(data, list):
            rows = data
        elif isinstance(data, dict):
            rows = [
                {"source_file": source_file, "report_url": report_url, "validation": "manual_verified"}
                for source_file, report_url in data.items()
            ]
        else:
            rows = []

        for row in rows:
            if not isinstance(row, dict):
                continue
            source_file = str(row.get("source_file") or row.get("file") or "").strip().lower()
            report_url = row.get("report_url") or row.get("url") or row.get("final_url")
            validation = str(row.get("validation") or "").strip()
            if source_file and is_public_url(report_url) and validation in allowed_validations and source_file not in urls:
                urls[source_file] = report_url
    return urls


def resolve_report_url(pdf_path: Path, by_path: dict[str, str], by_name: dict[str, str]) -> str:
    return by_path.get(normalize_path_key(pdf_path)) or by_name.get(pdf_path.name.lower()) or ""


def scan_pdf_matches(expected_files: list[str], source_roots: Iterable[Path]) -> dict[str, Path]:
    expected = {filename.lower(): filename for filename in expected_files}
    matches: dict[str, Path] = {}
    for source_root in source_roots:
        if not source_root.exists():
            continue
        for pdf_path in source_root.rglob("*.pdf"):
            expected_name = expected.get(pdf_path.name.lower())
            if expected_name and expected_name not in matches:
                matches[expected_name] = pdf_path
    return matches


def build_targets_2024(
    companies_path: Path = DEFAULT_2024_COMPANIES,
    esrs_path: Path = DEFAULT_2024_ESRS,
    source_roots: list[Path] | None = None,
    metadata_paths: list[Path] | None = None,
    catalog_paths: list[Path] | None = None,
    download_log_paths: list[Path] | None = None,
    url_override_paths: list[Path] | None = None,
) -> list[EvidenceTarget]:
    companies = read_csv_rows(companies_path)
    positive_labels = load_positive_labels(esrs_path)
    source_roots = source_roots or [
        path
        for path in sorted(DEFAULT_SOURCE_REPORTS_ROOT.iterdir(), key=lambda item: item.name.lower())
        if path.is_dir() and not path.name.lower().startswith("fy2025")
    ]
    metadata_paths = metadata_paths or list(DEFAULT_SOURCE_REPORTS_ROOT.rglob("metadata.json"))
    by_path, by_name = load_metadata_url_index(metadata_paths)
    if download_log_paths is None and DEFAULT_2024_DOWNLOAD_LOG_ROOT.exists():
        download_log_paths = [
            path
            for path in sorted(DEFAULT_2024_DOWNLOAD_LOG_ROOT.glob("targeted*"), key=lambda item: item.name.lower())
            if path.suffix.lower() in {".json", ".jsonl"}
        ]
    urls_by_download_name = load_download_log_url_index(download_log_paths or [])
    urls_by_source_file = load_url_override_index(url_override_paths or DEFAULT_2024_URL_OVERRIDES)
    urls_by_company = load_catalog_url_index(catalog_paths or DEFAULT_2024_CATALOGS)
    matches = scan_pdf_matches([row["file"] for row in companies if row.get("file")], source_roots)

    targets: list[EvidenceTarget] = []
    for row in companies:
        source_file = (row.get("file") or "").strip()
        company_name = (row.get("company_data_company_name") or "").strip()
        pdf_path = matches.get(source_file)
        if not source_file or pdf_path is None:
            continue
        report_url = resolve_report_url(pdf_path, by_path, by_name)
        if not report_url:
            report_url = urls_by_source_file.get(source_file.lower(), "")
        if not report_url:
            report_url = urls_by_download_name.get(source_file.lower()) or urls_by_download_name.get(pdf_path.name.lower(), "")
        if not report_url:
            for key in company_keys(company_name or source_file, source_file):
                report_url = urls_by_company.get(key, "")
                if report_url:
                    break
        if not report_url:
            report_url = fallback_report_reference(company_name, source_file)
        targets.append(
            EvidenceTarget(
                report_year=2024,
                company_name=company_name or source_file,
                source_file=source_file,
                pdf_path=str(pdf_path),
                report_url=report_url,
                positive_esrs_keys=positive_labels.get(source_file, []),
                cohort="base_2024",
            )
        )
    return targets


def load_installed_attempts(logs_dir: Path) -> dict[str, dict]:
    installed: dict[str, dict] = {}
    if not logs_dir.exists():
        return installed
    for log_path in sorted(logs_dir.glob("*.jsonl")):
        for line in log_path.read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                continue
            if row.get("result") != "installed":
                continue
            raw_path = row.get("path") or row.get("expected_filename")
            if not raw_path:
                continue
            path = Path(raw_path)
            if not path.is_absolute():
                path = AI_SERVICE_ROOT / path
            installed[normalize_path_key(path)] = row
            installed[path.name.lower()] = row
    return installed


def build_targets_2025(
    reports_dirs: list[Path] | None = None,
    logs_dir: Path = DEFAULT_REFRESH_ROOT / "logs",
    label_keys: list[str] | None = None,
) -> list[EvidenceTarget]:
    reports_dirs = reports_dirs or [
        DEFAULT_REFRESH_ROOT / "source_reports_2025",
        DEFAULT_REFRESH_ROOT / "new_company_reports_2025",
    ]
    label_keys = label_keys or load_esrs_keys()
    installed = load_installed_attempts(logs_dir)
    targets: list[EvidenceTarget] = []

    for reports_dir in reports_dirs:
        if not reports_dir.exists():
            continue
        cohort = "base_2025" if reports_dir.name == "source_reports_2025" else "new_company_2025"
        for pdf_path in sorted(reports_dir.glob("*.pdf")):
            attempt = installed.get(normalize_path_key(pdf_path)) or installed.get(pdf_path.name.lower()) or {}
            report_url = attempt.get("final_url") if is_public_url(attempt.get("final_url")) else attempt.get("url")
            if not is_public_url(report_url):
                continue
            targets.append(
                EvidenceTarget(
                    report_year=2025,
                    company_name=attempt.get("company_name") or pdf_path.stem,
                    source_file=attempt.get("source_file") or pdf_path.name,
                    pdf_path=str(pdf_path),
                    report_url=report_url,
                    positive_esrs_keys=list(label_keys),
                    cohort=cohort,
                )
            )
    return targets


def load_mapping_terms(
    mapping_path: Path = DEFAULT_MAPPING_PATH,
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> dict[str, list[str]]:
    if not mapping_path.is_file():
        return {}
    data = json.loads(mapping_path.read_text(encoding="utf-8"))
    official_terms_by_id = (
        load_official_terms_by_ar16_id(official_translations_path)
        if official_translations_path
        else {}
    )
    terms: dict[str, list[str]] = {}
    for entry in data.get("keys") or []:
        key = entry.get("python_esrs_key")
        if not key:
            continue
        values = [
            entry.get("web_label_en"),
            entry.get("web_theme_en"),
            entry.get("web_subtheme_en"),
            entry.get("web_subtopic_en"),
        ]
        values.extend(
            term["display"]
            for term in official_terms_for_mapping_row(
                entry,
                official_terms_by_ar16_id=official_terms_by_id,
                include_parent=True,
                include_child=True,
            )
            if isinstance(term.get("display"), str)
        )
        terms[key] = [value for value in values if isinstance(value, str) and value.strip()]
    return terms


def phrase_from_key(key: str) -> str:
    value = re.sub(r"^esrs_[a-z][0-9]_", "", key)
    value = value.replace("_", " ")
    return value.strip()


def build_keyword_catalog(
    esrs_keys: list[str],
    mapping_path: Path = DEFAULT_MAPPING_PATH,
    official_translations_path: Path = DEFAULT_OFFICIAL_TRANSLATIONS_PATH,
) -> dict[str, list[str]]:
    mapped_terms = load_mapping_terms(mapping_path, official_translations_path=official_translations_path)
    catalog: dict[str, list[str]] = {}
    for key in esrs_keys:
        values = [phrase_from_key(key), *mapped_terms.get(key, [])]
        unique: list[str] = []
        seen = set()
        for value in values:
            normalized = " ".join(value.split())
            if len(normalized) < 4:
                continue
            lowered = normalized.lower()
            if lowered in seen:
                continue
            seen.add(lowered)
            unique.append(normalized)
        catalog[key] = unique
    return catalog


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def make_excerpt(page_text: str, term: str, width: int = 180) -> str:
    match = re.search(re.escape(term), page_text, flags=re.IGNORECASE)
    if not match:
        compact = " ".join(page_text.split())
        return compact[:width]
    start = max(match.start() - width // 2, 0)
    end = min(match.end() + width // 2, len(page_text))
    return " ".join(page_text[start:end].split())


def evidence_id(
    pdf_hash: str,
    report_year: int,
    report_url: str,
    source_file: str,
    esrs_key: str,
    page_number: int,
    term: str,
    rect,
) -> str:
    raw = (
        f"{pdf_hash}|{report_year}|{report_url}|{source_file}|{esrs_key}|{page_number}|{term}|"
        f"{rect.x0:.2f}|{rect.y0:.2f}|{rect.x1:.2f}|{rect.y1:.2f}"
    )
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()


def extract_pdf_evidence(
    pdf_path: Path,
    report_url: str,
    report_year: int,
    company_name: str,
    source_file: str,
    esrs_keys: list[str],
    keyword_catalog: dict[str, list[str]],
    max_pages: int = 0,
    max_hits_per_key: int = 3,
) -> list[dict]:
    import fitz

    if not is_report_locator(report_url):
        raise ValueError("report_url must be a public http(s) URL or a company:/file: reference.")

    pdf_hash = file_sha256(pdf_path)
    rows: list[dict] = []
    hits_by_key = {key: 0 for key in esrs_keys}

    with fitz.open(pdf_path) as document:
        page_count = document.page_count if max_pages <= 0 else min(max_pages, document.page_count)
        for page_index in range(page_count):
            page = document.load_page(page_index)
            page_text = page.get_text("text")
            page_text_lower = page_text.lower()
            for esrs_key in esrs_keys:
                if hits_by_key[esrs_key] >= max_hits_per_key:
                    continue
                for term in keyword_catalog.get(esrs_key, []):
                    if hits_by_key[esrs_key] >= max_hits_per_key:
                        break
                    term_tokens = [token for token in re.findall(r"\w+", term.lower()) if len(token) > 2]
                    if term_tokens and not all(token in page_text_lower for token in term_tokens):
                        continue
                    for rect in page.search_for(term):
                        rows.append(
                            {
                                "evidence_id": evidence_id(
                                    pdf_hash,
                                    report_year,
                                    report_url,
                                    source_file,
                                    esrs_key,
                                    page_index + 1,
                                    term,
                                    rect,
                                ),
                                "cohort_report_year": report_year,
                                "company_name": company_name,
                                "source_file": source_file,
                                "report_url": report_url,
                                "local_pdf_path": str(pdf_path),
                                "pdf_sha256": pdf_hash,
                                "esrs_key": esrs_key,
                                "match_term": term,
                                "page_number": page_index + 1,
                                "bbox": {
                                    "x0": round(rect.x0, 2),
                                    "y0": round(rect.y0, 2),
                                    "x1": round(rect.x1, 2),
                                    "y1": round(rect.y1, 2),
                                },
                                "excerpt": make_excerpt(page_text, term),
                                "extraction_method": "pymupdf.search_for",
                                "review_status": "pending",
                            }
                        )
                        hits_by_key[esrs_key] += 1
                        if hits_by_key[esrs_key] >= max_hits_per_key:
                            break
    return rows


def write_jsonl(rows: Iterable[dict], output_path: Path) -> int:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with output_path.open("w", encoding="utf-8", newline="\n") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")
            count += 1
    return count


def write_review_csv(rows: Iterable[dict], output_path: Path) -> int:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "evidence_id",
        "cohort_report_year",
        "company_name",
        "source_file",
        "report_url",
        "esrs_key",
        "match_term",
        "page_number",
        "bbox",
        "excerpt",
        "review_status",
    ]
    count = 0
    with output_path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            serialized = dict(row)
            serialized["bbox"] = json.dumps(serialized.get("bbox") or {}, ensure_ascii=False)
            writer.writerow(serialized)
            count += 1
    return count


def load_targets(path: Path) -> list[EvidenceTarget]:
    data = json.loads(path.read_text(encoding="utf-8"))
    return [EvidenceTarget(**row) for row in data]


def dump_targets(targets: list[EvidenceTarget], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps([asdict(target) for target in targets], ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def extract_targets(
    targets: list[EvidenceTarget],
    output_jsonl: Path,
    review_csv: Path,
    max_pages: int,
    max_hits_per_key: int,
) -> dict:
    all_keys = sorted({key for target in targets for key in target.positive_esrs_keys})
    keyword_catalog = build_keyword_catalog(all_keys)
    failures = []
    output_jsonl.parent.mkdir(parents=True, exist_ok=True)
    review_csv.parent.mkdir(parents=True, exist_ok=True)
    progress_path = output_jsonl.with_suffix(".progress.json")
    jsonl_count = 0
    csv_count = 0
    processed = 0

    fieldnames = [
        "evidence_id",
        "cohort_report_year",
        "company_name",
        "source_file",
        "report_url",
        "esrs_key",
        "match_term",
        "page_number",
        "bbox",
        "excerpt",
        "review_status",
    ]
    with output_jsonl.open("w", encoding="utf-8", newline="\n") as jsonl_handle, review_csv.open(
        "w", encoding="utf-8", newline=""
    ) as csv_handle:
        writer = csv.DictWriter(csv_handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()

        for target in targets:
            try:
                rows = extract_pdf_evidence(
                    pdf_path=Path(target.pdf_path),
                    report_url=target.report_url,
                    report_year=target.report_year,
                    company_name=target.company_name,
                    source_file=target.source_file,
                    esrs_keys=target.positive_esrs_keys,
                    keyword_catalog=keyword_catalog,
                    max_pages=max_pages,
                    max_hits_per_key=max_hits_per_key,
                )
                for row in rows:
                    jsonl_handle.write(json.dumps(row, ensure_ascii=False) + "\n")
                    serialized = dict(row)
                    serialized["bbox"] = json.dumps(serialized.get("bbox") or {}, ensure_ascii=False)
                    writer.writerow(serialized)
                    jsonl_count += 1
                    csv_count += 1
            except Exception as exc:
                failures.append(
                    {
                        "company_name": target.company_name,
                        "source_file": target.source_file,
                        "report_url": target.report_url,
                        "error": f"{type(exc).__name__}: {exc}",
                    }
                )

            processed += 1
            if processed % 10 == 0 or processed == len(targets):
                jsonl_handle.flush()
                csv_handle.flush()
                progress_path.write_text(
                    json.dumps(
                        {
                            "targets": len(targets),
                            "processed": processed,
                            "evidence_rows": jsonl_count,
                            "failures": len(failures),
                        },
                        ensure_ascii=False,
                        indent=2,
                    ),
                    encoding="utf-8",
                )

    failures_path = output_jsonl.with_suffix(".failures.json")
    failures_path.write_text(json.dumps(failures, ensure_ascii=False, indent=2), encoding="utf-8")
    return {
        "targets": len(targets),
        "evidence_rows": jsonl_count,
        "review_rows": csv_count,
        "failures": len(failures),
        "failures_path": str(failures_path),
        "progress_path": str(progress_path),
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build report URL evidence with page/bbox for ESRS labels.")
    subparsers = parser.add_subparsers(dest="command", required=True)

    targets = subparsers.add_parser("build-targets")
    targets.add_argument("--include-2024", action="store_true")
    targets.add_argument("--include-2025", action="store_true")
    targets.add_argument("--output", type=Path, default=DEFAULT_EVIDENCE_ROOT / "targets-2024-2025.json")

    extract = subparsers.add_parser("extract")
    extract.add_argument("--targets", type=Path, default=DEFAULT_EVIDENCE_ROOT / "targets-2024-2025.json")
    extract.add_argument("--output-jsonl", type=Path, default=DEFAULT_EVIDENCE_ROOT / "report-evidence-2024-2025.jsonl")
    extract.add_argument("--review-csv", type=Path, default=DEFAULT_EVIDENCE_ROOT / "review-queue-2024-2025.csv")
    extract.add_argument("--max-pages", type=int, default=0, help="0 means all pages.")
    extract.add_argument("--max-hits-per-key", type=int, default=3)

    run_all = subparsers.add_parser("run-all")
    run_all.add_argument("--max-pages", type=int, default=0, help="0 means all pages.")
    run_all.add_argument("--max-hits-per-key", type=int, default=3)
    run_all.add_argument("--output-root", type=Path, default=DEFAULT_EVIDENCE_ROOT)

    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.command == "build-targets":
        selected_targets = []
        if args.include_2024:
            selected_targets.extend(build_targets_2024())
        if args.include_2025:
            selected_targets.extend(build_targets_2025())
        dump_targets(selected_targets, args.output)
        print(f"evidence_extraction: targets={len(selected_targets)} output={args.output}")
        return 0

    if args.command == "extract":
        summary = extract_targets(
            load_targets(args.targets),
            args.output_jsonl,
            args.review_csv,
            args.max_pages,
            args.max_hits_per_key,
        )
        print("evidence_extraction: " + json.dumps(summary, ensure_ascii=False))
        return 0

    if args.command == "run-all":
        output_root = args.output_root
        targets_path = output_root / "targets-2024-2025.json"
        selected_targets = [*build_targets_2024(), *build_targets_2025()]
        dump_targets(selected_targets, targets_path)
        summary = extract_targets(
            selected_targets,
            output_root / "report-evidence-2024-2025.jsonl",
            output_root / "review-queue-2024-2025.csv",
            args.max_pages,
            args.max_hits_per_key,
        )
        summary["targets_path"] = str(targets_path)
        print("evidence_extraction: " + json.dumps(summary, ensure_ascii=False))
        return 0

    raise ValueError(args.command)


if __name__ == "__main__":
    raise SystemExit(main())
