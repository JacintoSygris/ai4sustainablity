import argparse
import base64
import csv
import json
import re
import os
import unicodedata
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path
from typing import Callable
from urllib.parse import parse_qs, quote_plus, unquote, urljoin, urlparse

import requests


DEFAULT_DATASET = (
    Path(__file__).resolve().parents[1]
    / "training_data"
    / "new_format"
    / "gpt41"
    / "companies_gpt41_clean.csv"
)
DEFAULT_EFRAG_METADATA = (
    Path(__file__).resolve().parents[1]
    / "training_data"
    / "source_reports"
    / "efrag_2024"
    / "metadata.json"
)
DEFAULT_REFRESH_ROOT = Path(__file__).resolve().parents[1] / "training_data" / "refresh_2025"
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
class CompanyRecord:
    file: str
    company_name: str


@dataclass(frozen=True)
class ReportTextValidation:
    is_valid: bool
    reasons: list[str]


@dataclass(frozen=True)
class FetchResult:
    body: bytes | None
    status_code: int | None
    content_type: str
    final_url: str
    error: str | None = None


def _append_unique(values: list[str], value: str) -> None:
    if value and value not in values:
        values.append(value)


def sanitize_filename_stem(value: str, max_len: int = 90) -> str:
    normalized = unicodedata.normalize("NFKD", value or "")
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii")
    ascii_text = re.sub(r"\b([A-Za-z])\.\s*([A-Za-z])\.", r"\1\2", ascii_text)
    ascii_text = ascii_text.replace("&", " and ")
    ascii_text = re.sub(r"[^A-Za-z0-9]+", "_", ascii_text)
    ascii_text = re.sub(r"_+", "_", ascii_text).strip("_")
    return (ascii_text or "company")[:max_len].strip("_") or "company"


def company_key(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value or "")
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii").lower()
    ascii_text = re.sub(r"[/&+]", " ", ascii_text)
    tokens = re.findall(r"[a-z0-9]+", ascii_text)
    filtered = [token for token in tokens if token not in LEGAL_TOKENS]
    return " ".join(filtered or tokens)


def company_keys_for_record(company: CompanyRecord) -> set[str]:
    keys = {company_key(company.company_name)}
    keys.add(company_key(Path(company.file).stem.replace("_", " ")))
    return {key for key in keys if key}


def load_companies(dataset_path: Path = DEFAULT_DATASET) -> list[CompanyRecord]:
    with dataset_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle, delimiter=";")
        fieldnames = reader.fieldnames or []
        if "file" not in fieldnames:
            raise ValueError(f"{dataset_path} does not contain a 'file' column.")

        name_field = (
            "company_data_company_name"
            if "company_data_company_name" in fieldnames
            else "company_name"
            if "company_name" in fieldnames
            else None
        )
        if not name_field:
            raise ValueError(f"{dataset_path} does not contain a company name column.")

        companies = []
        for row in reader:
            filename = (row.get("file") or "").strip()
            company_name = (row.get(name_field) or "").strip()
            if filename and company_name:
                companies.append(CompanyRecord(file=filename, company_name=company_name))
    return companies


def load_metadata_urls_by_filename(metadata_path: Path = DEFAULT_EFRAG_METADATA) -> dict[str, str]:
    data = json.loads(metadata_path.read_text(encoding="utf-8"))
    urls = data.get("urls") or {}
    by_filename: dict[str, str] = {}
    for url, meta in urls.items():
        path = (meta or {}).get("path") or ""
        filename = Path(path).name
        if filename:
            by_filename[filename.lower()] = url
    return by_filename


def load_efrag_catalog(catalog_path: Path) -> list[dict]:
    data = json.loads(catalog_path.read_text(encoding="utf-8"))
    if isinstance(data, dict):
        companies = data.get("companies", [])
    elif isinstance(data, list):
        companies = data
    else:
        companies = []
    return [item for item in companies if isinstance(item, dict)]


def catalog_urls_by_company_key(catalog: list[dict]) -> dict[str, str]:
    urls: dict[str, str] = {}
    for item in catalog:
        name = item.get("name") or item.get("company") or ""
        url = item.get("report_url") or item.get("documentUrl") or ""
        key = company_key(name)
        if key and url and key not in urls:
            urls[key] = url
    return urls


def derive_candidate_urls(url: str, source_year: int = 2024, target_year: int = 2025) -> list[str]:
    source_year_text = str(source_year)
    target_year_text = str(target_year)
    source_publication_year = str(source_year + 1)
    target_publication_year = str(target_year + 1)
    source_short = str(source_year)[-2:]
    target_short = str(target_year)[-2:]

    candidates: list[str] = []

    report_year_url = url.replace(source_year_text, target_year_text)
    _append_unique(candidates, report_year_url)

    publication_year_url = url.replace(source_publication_year, target_publication_year)
    publication_and_report_year_url = publication_year_url.replace(source_year_text, target_year_text)
    _append_unique(candidates, publication_and_report_year_url)
    _append_unique(candidates, publication_year_url)

    short_year_replacements = [
        (f"FY{source_short}", f"FY{target_short}"),
        (f"fy{source_short}", f"fy{target_short}"),
        (f"FY_{source_year_text}", f"FY_{target_year_text}"),
        (f"fy_{source_year_text}", f"fy_{target_year_text}"),
        (f"FY-{source_year_text}", f"FY-{target_year_text}"),
        (f"fy-{source_year_text}", f"fy-{target_year_text}"),
    ]
    for old, new in short_year_replacements:
        if old in url:
            _append_unique(candidates, url.replace(old, new))

    parsed = urlparse(url)
    basename = Path(parsed.path).name
    if basename:
        stem, suffix = Path(basename).stem, Path(basename).suffix
        if suffix.lower() == ".pdf" and source_year_text not in basename:
            sibling = url.replace(basename, f"{stem}-{target_year_text}{suffix}")
            _append_unique(candidates, sibling)

    return [candidate for candidate in candidates if candidate != url]


def build_manifest_targets(
    companies: list[CompanyRecord],
    urls_by_filename: dict[str, str],
    urls_by_company_key: dict[str, str] | None = None,
    source_year: int = 2024,
    target_year: int = 2025,
) -> list[dict]:
    targets = []
    urls_by_company_key = urls_by_company_key or {}
    for company in companies:
        source_url = urls_by_filename.get(company.file.lower())
        if not source_url:
            for key in company_keys_for_record(company):
                source_url = urls_by_company_key.get(key)
                if source_url:
                    break
        if not source_url:
            continue

        stem = sanitize_filename_stem(company.company_name)
        for index, candidate_url in enumerate(
            derive_candidate_urls(source_url, source_year=source_year, target_year=target_year),
            start=1,
        ):
            targets.append(
                {
                    "company_name": company.company_name,
                    "source_file": company.file,
                    "source_url": source_url,
                    "expected_filename": f"{stem}_FY{target_year}_{index:02d}.pdf",
                    "url": candidate_url,
                    "allow_variant": False,
                }
            )
    return targets


def validate_report_text(text: str, target_year: int = 2025) -> ReportTextValidation:
    normalized = re.sub(r"\s+", " ", text or "").lower()
    reasons: list[str] = []
    if str(target_year) not in normalized:
        reasons.append("target_year_not_found")
    if not any(
        marker in normalized
        for marker in (
            "csrd",
            "esrs",
            "corporate sustainability reporting directive",
            "european sustainability reporting standards",
        )
    ):
        reasons.append("csrd_esrs_marker_not_found")
    return ReportTextValidation(is_valid=not reasons, reasons=reasons)


def extract_pdf_text(pdf_path: Path, max_pages: int = 12) -> str:
    try:
        import PyPDF2
    except Exception as exc:
        raise RuntimeError("PyPDF2 is required to validate downloaded PDFs.") from exc

    chunks: list[str] = []
    with pdf_path.open("rb") as handle:
        reader = PyPDF2.PdfReader(handle)
        for page in reader.pages[:max_pages]:
            try:
                chunks.append(page.extract_text() or "")
            except Exception:
                continue
    return "\n".join(chunks)


def write_manifest(targets: list[dict], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps({"targets": targets}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def load_manifest_targets(manifest_path: Path) -> list[dict]:
    data = json.loads(manifest_path.read_text(encoding="utf-8"))
    rows = data.get("targets", data) if isinstance(data, dict) else data
    if not isinstance(rows, list):
        raise ValueError(f"{manifest_path} does not contain a target list.")
    return [row for row in rows if isinstance(row, dict)]


def fetch_document_direct(url: str, timeout: tuple[int, int] = (8, 30)) -> FetchResult:
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36"
        ),
        "Accept": "application/pdf,application/octet-stream,*/*",
    }
    try:
        response = requests.get(url, headers=headers, timeout=timeout, allow_redirects=True)
        content_type = (response.headers.get("Content-Type") or "").split(";")[0].lower()
        return FetchResult(
            body=response.content if response.status_code < 400 else None,
            status_code=response.status_code,
            content_type=content_type,
            final_url=str(response.url),
        )
    except Exception as exc:
        return FetchResult(
            body=None,
            status_code=None,
            content_type="",
            final_url=url,
            error=f"{type(exc).__name__}: {exc}",
        )


def is_pdf_body(body: bytes | None) -> bool:
    return bool(body and body.startswith(b"%PDF"))


class LinkExtractor(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag.lower() != "a":
            return
        attrs_by_name = {name.lower(): value for name, value in attrs}
        href = attrs_by_name.get("href")
        if href:
            self.links.append(href)


def decode_redirect_href(href: str) -> str:
    value = (href or "").replace("&amp;", "&")
    if value.startswith("//"):
        value = "https:" + value
    parsed = urlparse(value)
    if "duckduckgo.com" in (parsed.netloc or "") and parsed.path.startswith("/l/"):
        target = parse_qs(parsed.query).get("uddg", [""])[0]
        return unquote(target)
    if "bing.com" in (parsed.netloc or "") and parsed.path.startswith("/ck/"):
        encoded = parse_qs(parsed.query).get("u", [""])[0]
        if encoded.startswith("a1"):
            encoded = encoded[2:]
        try:
            padding = "=" * ((4 - len(encoded) % 4) % 4)
            return base64.urlsafe_b64decode(encoded + padding).decode("utf-8")
        except Exception:
            return value
    return value


def decode_duckduckgo_href(href: str) -> str:
    return decode_redirect_href(href)


def extract_candidate_links(html: str, base_url: str, target_year: int = 2025) -> list[str]:
    parser = LinkExtractor()
    parser.feed(html or "")
    links: list[str] = []
    for href in parser.links:
        decoded = decode_redirect_href(href)
        absolute = urljoin(base_url, decoded)
        lower = absolute.lower()
        if ".pdf" not in lower:
            continue
        if str(target_year) not in lower:
            continue
        if not any(token in lower for token in ("annual", "report", "sustainability", "statement", "urd", "registration")):
            continue
        _append_unique(links, absolute)
    return links


def search_duckduckgo(query: str, max_results: int = 6) -> list[str]:
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    results: list[str] = []
    for base_url in (
        "https://lite.duckduckgo.com/lite/?q=",
        "https://html.duckduckgo.com/html/?q=",
    ):
        try:
            response = requests.get(base_url + quote_plus(query), headers=headers, timeout=(8, 25))
            if response.status_code not in (200, 202):
                continue
            parser = LinkExtractor()
            parser.feed(response.text)
            for href in parser.links:
                decoded = decode_redirect_href(href)
                parsed = urlparse(decoded)
                if parsed.scheme not in ("http", "https") or not parsed.netloc:
                    continue
                if "duckduckgo.com" in parsed.netloc:
                    continue
                _append_unique(results, decoded)
                if len(results) >= max_results:
                    return results
        except Exception:
            continue
        if results:
            return results
    return search_bing_via_jina(query, max_results=max_results)


def search_bing_via_jina(query: str, max_results: int = 6) -> list[str]:
    reader_url = "https://r.jina.ai/http://www.bing.com/search?q=" + quote_plus(query)
    response = requests.get(reader_url, headers={"User-Agent": "Mozilla/5.0"}, timeout=(8, 35))
    if response.status_code >= 400:
        return []

    results: list[str] = []
    for raw_url in re.findall(r"\]\((https?://[^)]+)\)", response.text):
        decoded = decode_redirect_href(raw_url)
        parsed = urlparse(decoded)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            continue
        if any(skip in parsed.netloc for skip in ("bing.com", "microsoft.com")):
            continue
        _append_unique(results, decoded)
        if len(results) >= max_results:
            break
    return results


def fetch_html(url: str) -> str:
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)", "Accept": "text/html,*/*"}
    response = requests.get(url, headers=headers, timeout=(8, 25), allow_redirects=True)
    content_type = (response.headers.get("Content-Type") or "").lower()
    if response.status_code >= 400 or "html" not in content_type:
        return ""
    return response.text


def build_search_query(company_name: str, target_year: int = 2025) -> str:
    return f"{company_name} annual report {target_year} sustainability statement ESRS PDF"


def build_search_manifest_targets(
    companies: list[CompanyRecord],
    offset: int = 0,
    limit_companies: int | None = None,
    target_year: int = 2025,
    results_per_company: int = 6,
    links_per_company: int = 4,
) -> list[dict]:
    selected = companies[offset : offset + limit_companies if limit_companies is not None else None]
    targets: list[dict] = []
    for company in selected:
        query = build_search_query(company.company_name, target_year=target_year)
        try:
            results = search_duckduckgo(query, max_results=results_per_company)
        except Exception:
            results = []

        links: list[str] = []
        for result in results:
            lower = result.lower()
            if ".pdf" in lower and str(target_year) in lower:
                _append_unique(links, result)
            if len(links) < links_per_company:
                try:
                    html = fetch_html(result)
                    for link in extract_candidate_links(html, result, target_year=target_year):
                        _append_unique(links, link)
                        if len(links) >= links_per_company:
                            break
                except Exception:
                    continue
            if len(links) >= links_per_company:
                break

        stem = sanitize_filename_stem(company.company_name)
        for index, link in enumerate(links[:links_per_company], start=1):
            targets.append(
                {
                    "company_name": company.company_name,
                    "source_file": company.file,
                    "search_query": query,
                    "expected_filename": f"{stem}_FY{target_year}_search_{index:02d}.pdf",
                    "url": link,
                    "allow_variant": False,
                }
            )
    return targets


def _write_attempt(attempt_log: Path | None, record: dict) -> None:
    if not attempt_log:
        return
    attempt_log.parent.mkdir(parents=True, exist_ok=True)
    with attempt_log.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def download_targets_direct(
    targets: list[dict],
    output_dir: Path,
    fetcher: Callable[[str], FetchResult] = fetch_document_direct,
    attempt_log: Path | None = None,
    limit: int | None = None,
) -> dict:
    output_dir.mkdir(parents=True, exist_ok=True)
    completed_sources: set[str] = set()
    summary = {
        "targets": 0,
        "installed": 0,
        "failed": 0,
        "skipped_existing": 0,
        "skipped_after_success": 0,
    }

    selected_targets = targets[:limit] if limit is not None else targets
    for target in selected_targets:
        summary["targets"] += 1
        target_urls = [target["url"]] if target.get("url") else list(target.get("urls") or [])
        source_file = target.get("source_file") or target.get("expected_filename") or (target_urls[0] if target_urls else "")
        expected_filename = (
            target.get("expected_filename")
            or target.get("source_file")
            or (Path(urlparse(target_urls[0]).path).name if target_urls else "")
        )
        if source_file in completed_sources:
            summary["skipped_after_success"] += 1
            _write_attempt(attempt_log, {**target, "result": "skipped_after_success"})
            continue

        dest = output_dir / sanitize_filename_stem(Path(expected_filename).stem)
        dest = dest.with_suffix(Path(expected_filename).suffix or ".pdf")
        if dest.exists():
            completed_sources.add(source_file)
            summary["skipped_existing"] += 1
            _write_attempt(attempt_log, {**target, "result": "skipped_existing", "path": str(dest)})
            continue

        if not target_urls:
            summary["failed"] += 1
            _write_attempt(attempt_log, {**target, "result": "missing_url"})
            continue

        for url in target_urls:
            result = fetcher(url)
            record = {
                **target,
                "url": url,
                "status_code": result.status_code,
                "content_type": result.content_type,
                "final_url": result.final_url,
                "error": result.error,
                "bytes": len(result.body or b""),
                "pdf_magic": is_pdf_body(result.body),
            }
            if is_pdf_body(result.body):
                temp_path = dest.with_suffix(dest.suffix + ".part")
                temp_path.write_bytes(result.body or b"")
                os.replace(temp_path, dest)
                completed_sources.add(source_file)
                summary["installed"] += 1
                _write_attempt(attempt_log, {**record, "result": "installed", "path": str(dest)})
                break

            summary["failed"] += 1
            _write_attempt(attempt_log, {**record, "result": "not_pdf"})

    return summary


def write_validation_report(reports_dir: Path, output_path: Path, target_year: int, max_pages: int) -> None:
    rows = []
    for pdf_path in sorted(reports_dir.rglob("*.pdf")):
        try:
            text = extract_pdf_text(pdf_path, max_pages=max_pages)
            validation = validate_report_text(text, target_year=target_year)
            rows.append(
                {
                    "file": str(pdf_path),
                    "is_valid": validation.is_valid,
                    "reasons": validation.reasons,
                }
            )
        except Exception as exc:
            rows.append(
                {
                    "file": str(pdf_path),
                    "is_valid": False,
                    "reasons": [f"extract_error:{type(exc).__name__}"],
                }
            )

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps({"reports": rows}, ensure_ascii=False, indent=2), encoding="utf-8")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Prepare and validate IA4S FY2025 training report refresh manifests.")
    subparsers = parser.add_subparsers(dest="command", required=True)

    manifest = subparsers.add_parser("manifest", help="Build a targeted downloader manifest from 2024 metadata.")
    manifest.add_argument("--dataset", type=Path, default=DEFAULT_DATASET)
    manifest.add_argument("--metadata", type=Path, default=DEFAULT_EFRAG_METADATA)
    manifest.add_argument("--efrag-catalog", type=Path, default=None)
    manifest.add_argument("--source-year", type=int, default=2024)
    manifest.add_argument("--target-year", type=int, default=2025)
    manifest.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_REFRESH_ROOT / "manifests" / "fy2025-url-candidates.json",
    )

    validate = subparsers.add_parser("validate", help="Validate downloaded reports by text markers.")
    validate.add_argument("--reports-dir", type=Path, required=True)
    validate.add_argument("--target-year", type=int, default=2025)
    validate.add_argument("--max-pages", type=int, default=12)
    validate.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_REFRESH_ROOT / "validation" / "fy2025-validation.json",
    )

    download = subparsers.add_parser("download-direct", help="Download manifest targets via direct HTTP only.")
    download.add_argument(
        "--manifest",
        type=Path,
        default=DEFAULT_REFRESH_ROOT / "manifests" / "fy2025-url-candidates.json",
    )
    download.add_argument(
        "--output-dir",
        type=Path,
        default=DEFAULT_REFRESH_ROOT / "source_reports_2025",
    )
    download.add_argument(
        "--attempt-log",
        type=Path,
        default=DEFAULT_REFRESH_ROOT / "logs" / "fy2025-direct-attempts.jsonl",
    )
    download.add_argument("--limit", type=int, default=None)

    search = subparsers.add_parser("search-manifest", help="Build a FY2025 manifest from web search results.")
    search.add_argument("--dataset", type=Path, default=DEFAULT_DATASET)
    search.add_argument("--target-year", type=int, default=2025)
    search.add_argument("--offset", type=int, default=0)
    search.add_argument("--limit-companies", type=int, default=50)
    search.add_argument("--results-per-company", type=int, default=6)
    search.add_argument("--links-per-company", type=int, default=4)
    search.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_REFRESH_ROOT / "manifests" / "fy2025-search-candidates.json",
    )

    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.command == "manifest":
        companies = load_companies(args.dataset)
        urls_by_filename = load_metadata_urls_by_filename(args.metadata)
        urls_by_company_key = (
            catalog_urls_by_company_key(load_efrag_catalog(args.efrag_catalog))
            if args.efrag_catalog
            else {}
        )
        targets = build_manifest_targets(
            companies,
            urls_by_filename,
            urls_by_company_key=urls_by_company_key,
            source_year=args.source_year,
            target_year=args.target_year,
        )
        write_manifest(targets, args.output)
        print(f"training_report_refresh: companies={len(companies)} targets={len(targets)} output={args.output}")
        return 0

    if args.command == "validate":
        write_validation_report(args.reports_dir, args.output, args.target_year, args.max_pages)
        print(f"training_report_refresh: validation_output={args.output}")
        return 0

    if args.command == "download-direct":
        targets = load_manifest_targets(args.manifest)
        summary = download_targets_direct(
            targets,
            args.output_dir,
            attempt_log=args.attempt_log,
            limit=args.limit,
        )
        print(
            "training_report_refresh: "
            f"targets={summary['targets']} "
            f"installed={summary['installed']} "
            f"failed={summary['failed']} "
            f"skipped_existing={summary['skipped_existing']} "
            f"skipped_after_success={summary['skipped_after_success']} "
            f"output_dir={args.output_dir}"
        )
        return 0 if summary["installed"] or summary["skipped_existing"] else 2

    if args.command == "search-manifest":
        companies = load_companies(args.dataset)
        targets = build_search_manifest_targets(
            companies,
            offset=args.offset,
            limit_companies=args.limit_companies,
            target_year=args.target_year,
            results_per_company=args.results_per_company,
            links_per_company=args.links_per_company,
        )
        write_manifest(targets, args.output)
        print(
            "training_report_refresh: "
            f"companies={len(companies)} "
            f"offset={args.offset} "
            f"limit_companies={args.limit_companies} "
            f"targets={len(targets)} "
            f"output={args.output}"
        )
        return 0

    raise AssertionError(f"Unhandled command: {args.command}")


if __name__ == "__main__":
    raise SystemExit(main())
