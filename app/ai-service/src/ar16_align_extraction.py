from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from registry.ar16 import default_paths
from registry.extraction_alignment import align_extraction_csv


def main(argv: list[str] | None = None) -> int:
    paths = default_paths()
    parser = argparse.ArgumentParser(
        description=(
            "Post-process an offline extraction CSV into AR16/Python-aligned "
            "values plus a separate review trace CSV."
        )
    )
    parser.add_argument("--input", type=Path, required=True, help="Extraction CSV produced by extract.py.")
    parser.add_argument("--output", type=Path, required=True, help="AR16-aligned values CSV to write.")
    parser.add_argument(
        "--trace-output",
        type=Path,
        required=True,
        help="Trace CSV to write with source key, target key, evidence/context, and review flags.",
    )
    parser.add_argument("--mapping", type=Path, default=paths["mapping_path"])
    parser.add_argument("--equivalences", type=Path, default=paths["key_equivalences_path"])
    parser.add_argument("--delimiter", default=";", help="CSV delimiter. Default: ';'.")
    args = parser.parse_args(argv)

    summary = align_extraction_csv(
        input_path=args.input,
        output_path=args.output,
        trace_output_path=args.trace_output,
        mapping_path=args.mapping,
        key_equivalences_path=args.equivalences,
        delimiter=args.delimiter,
    )

    print(json.dumps(summary, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
