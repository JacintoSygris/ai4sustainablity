#!/usr/bin/env python3
"""Explicit private inputs only. No corpus discovery, identity or sector inference."""
import argparse
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from services.sector_comparison import build_artifact, write_artifact


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("labels", "masks", "companies", "encoding", "topic-mapping", "output"):
        parser.add_argument("--" + name, required=True)
    args = vars(parser.parse_args())
    output = args.pop("output")
    try:
        write_artifact(build_artifact(**args), output)
    except (ValueError, OSError, KeyError, TypeError) as error:
        # Do not print private manifest content or paths.
        parser.exit(1, "Sector comparison build rejected (invalid input or output).\n")


if __name__ == "__main__":
    main()
