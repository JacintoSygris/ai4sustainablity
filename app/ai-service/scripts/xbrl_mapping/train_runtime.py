"""Build a private immutable runtime model; no benchmark or held-out accuracy claims."""
import argparse
import json
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "src"))
from services.xbrl_concept_matcher import (Matcher, catalog_inventory, concept_text, digest,
                                         load_input_bytes, read_bounded, save_runtime)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("ig3", "concept-map", "output-dir"):
        parser.add_argument("--" + name, type=Path, required=True)
    parser.add_argument("--model-version", required=True)
    parser.add_argument("--taxonomy-catalog", type=Path)
    parser.add_argument("--min-score", type=float, default=0.5)
    parser.add_argument("--min-margin", type=float, default=0.05)
    args = parser.parse_args()
    ig3_bytes, map_bytes = read_bounded(args.ig3), read_bounded(args.concept_map)
    known, _, concepts = load_input_bytes(ig3_bytes, map_bytes)
    inputs = {"ig3_sha256": digest(ig3_bytes), "concept_map_sha256": digest(map_bytes)}
    metadata, languages, package = {}, ["en"], None
    profile, catalog_hash = "esrs_set1_2023-12-22", inputs["concept_map_sha256"]
    if args.taxonomy_catalog:
        raw = read_bounded(args.taxonomy_catalog, 16777216)
        catalog = json.loads(raw)
        metadata = catalog_inventory(catalog)
        concepts = sorted(set(concepts) | set(metadata))
        languages, package, profile = catalog["languages"], catalog["package_sha256"], catalog["taxonomy_profile"]
        catalog_hash = inputs["taxonomy_catalog_sha256"] = digest(raw)
    labels = [" ".join([concept_text(c), *metadata.get(c, {}).get("labels", {}).values(),
                       *metadata.get(c, {}).get("descriptions", {}).values()]) for c in concepts]
    matcher = Matcher(concepts, known, labels).fit(known)
    source = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=Path(__file__).resolve().parent, text=True).strip()
    service_root = Path(__file__).resolve().parents[2]
    source_names = ["scripts/xbrl_mapping/train_runtime.py", "public_runtime/services/xbrl_concept_matcher.py",
                    "public_runtime/services/xbrl_runtime_endpoint.py", "public_runtime/services/datatypes.py",
                    "public_runtime/services/xbrl_runtime_context.py", "public_model_app.py"]
    source_files = {name: digest((service_root / name).read_bytes()) for name in source_names}
    dirty = bool(subprocess.check_output(["git", "status", "--porcelain", "--", *source_names], cwd=service_root, text=True))
    result = save_runtime(matcher, args.output_dir, model_version=args.model_version, source_commit=source,
        inputs=inputs, taxonomy_profile=profile, catalog_sha256=catalog_hash, package_sha256=package,
        metadata=metadata, languages=languages, min_score=args.min_score, min_margin=args.min_margin,
        source_files=source_files, source_dirty=dirty)
    print(json.dumps({"artifact_sha256": result.artifact_sha256, "inventory_count": len(concepts),
                      "evaluation": "pending_independent_specialist_labels"}))


if __name__ == "__main__":
    main()
