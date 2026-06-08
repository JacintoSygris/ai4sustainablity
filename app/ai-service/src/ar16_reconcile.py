from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from registry.ar16 import build_reconciliation_report, default_paths, write_report


def main() -> int:
    paths = default_paths()
    parser = argparse.ArgumentParser(
        description="Report drift between AR16 mapping, Python ESRS fields, dataset columns, and YAML extraction models."
    )
    parser.add_argument("--mapping", type=Path, default=paths["mapping_path"])
    parser.add_argument("--company-esrs", type=Path, default=paths["company_esrs_csv_path"])
    parser.add_argument("--features", type=Path, default=paths["feature_description_path"])
    parser.add_argument("--yaml-models", type=Path, default=paths["yaml_models_dir"])
    parser.add_argument("--equivalences", type=Path, default=paths["key_equivalences_path"])
    parser.add_argument(
        "--no-equivalences",
        action="store_true",
        help="Ignore the explicit AR16 key equivalence table even if it exists.",
    )
    parser.add_argument("--output", type=Path, help="Optional JSON report path.")
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Exit with code 2 when any unmatched key is reported.",
    )
    args = parser.parse_args()

    report = build_reconciliation_report(
        mapping_path=args.mapping,
        company_esrs_csv_path=args.company_esrs,
        feature_description_path=args.features,
        yaml_models_dir=args.yaml_models,
        key_equivalences_path=None if args.no_equivalences else args.equivalences,
    )
    payload = report.to_dict()

    if args.output:
        write_report(report, args.output)

    print(json.dumps(payload, indent=2, ensure_ascii=False))
    if args.strict and report.has_unmatched():
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
