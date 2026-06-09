import argparse
import csv
import json
from dataclasses import dataclass
from pathlib import Path


DEFAULT_DATASET = Path(__file__).resolve().parents[1] / "training_data" / "new_format" / "gpt41" / "companies_gpt41_clean.csv"
DEFAULT_SOURCE_ROOT = Path(__file__).resolve().parents[1] / "training_data" / "source_reports" / "efrag_2024"
DEFAULT_SOURCE_ROOTS_DIR = DEFAULT_SOURCE_ROOT.parent


@dataclass(frozen=True)
class TrainingSourceInventory:
    expected_count: int
    found_unique_count: int
    missing_files: list[str]
    duplicate_files: list[str]
    matches: dict[str, list[str]]

    def to_dict(self) -> dict:
        return {
            "expected_count": self.expected_count,
            "found_unique_count": self.found_unique_count,
            "missing_count": len(self.missing_files),
            "duplicate_count": len(self.duplicate_files),
            "missing_files": self.missing_files,
            "duplicate_files": self.duplicate_files,
            "matches": self.matches,
        }


def load_expected_report_files(dataset_path: Path) -> list[str]:
    with dataset_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle, delimiter=";")
        if "file" not in (reader.fieldnames or []):
            raise ValueError(f"{dataset_path} does not contain a 'file' column.")

        files = []
        seen = set()
        for row in reader:
            filename = (row.get("file") or "").strip()
            if not filename or filename in seen:
                continue
            seen.add(filename)
            files.append(filename)

    return files


def scan_report_sources(expected_files: list[str], source_roots: list[Path]) -> TrainingSourceInventory:
    expected_by_lower = {filename.lower(): filename for filename in expected_files}
    matches: dict[str, list[str]] = {filename: [] for filename in expected_files}

    for source_root in source_roots:
        if not source_root.exists():
            continue
        for pdf_path in source_root.rglob("*.pdf"):
            expected = expected_by_lower.get(pdf_path.name.lower())
            if expected:
                matches[expected].append(str(pdf_path))

    missing_files = [filename for filename in expected_files if not matches[filename]]
    duplicate_files = [filename for filename in expected_files if len(matches[filename]) > 1]

    return TrainingSourceInventory(
        expected_count=len(expected_files),
        found_unique_count=len(expected_files) - len(missing_files),
        missing_files=missing_files,
        duplicate_files=duplicate_files,
        matches={filename: paths for filename, paths in matches.items() if paths},
    )


def default_source_roots(
    base_dir: Path = DEFAULT_SOURCE_ROOTS_DIR,
    include_refresh_roots: bool = False,
) -> list[Path]:
    if not base_dir.exists():
        return [DEFAULT_SOURCE_ROOT]

    roots = [
        path
        for path in sorted(base_dir.iterdir(), key=lambda item: item.name.lower())
        if path.is_dir() and (include_refresh_roots or not path.name.lower().startswith("fy2025"))
    ]
    return roots or [DEFAULT_SOURCE_ROOT]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Inventory source PDFs available for the IA4S 732-report training dataset."
    )
    parser.add_argument(
        "--dataset",
        type=Path,
        default=DEFAULT_DATASET,
        help="Semicolon-delimited companies CSV with a file column.",
    )
    parser.add_argument(
        "--source-root",
        type=Path,
        action="append",
        default=None,
        help="Folder to scan recursively for matching PDFs. Can be repeated.",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Print full JSON output including missing files and matches.",
    )
    parser.add_argument(
        "--include-refresh-roots",
        action="store_true",
        help="Include refresh roots such as fy2025_direct in the source scan.",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    source_roots = args.source_root or default_source_roots(include_refresh_roots=args.include_refresh_roots)
    expected = load_expected_report_files(args.dataset)
    inventory = scan_report_sources(expected, source_roots)
    value = inventory.to_dict()

    if args.json:
        print(json.dumps(value, ensure_ascii=False, indent=2))
    else:
        print(
            "training_source_inventory: "
            f"expected={value['expected_count']} "
            f"found_unique={value['found_unique_count']} "
            f"missing={value['missing_count']} "
            f"duplicates={value['duplicate_count']}"
        )
        if inventory.missing_files:
            print("missing_files:")
            for filename in inventory.missing_files:
                print(f"- {filename}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
