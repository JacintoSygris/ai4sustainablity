import argparse
import copy
import json
from pathlib import Path

from project_paths import resolve_web_data_dir, resolve_web_data_file
from services import service_predict


MAPPING_VERSION = "new_format_732_v1"
NEW_FORMAT_MAPPING_FILENAME = "ar16_to_python_esrs_mapping_new_format_732_v1.json"
LEGACY_MAPPING_FILENAME = "ar16_to_python_esrs_mapping.json"
DEFAULT_EQUIVALENCES = Path(__file__).resolve().parents[1] / "data" / "ar16_key_equivalences.json"
MODEL_PROFILES = [
    "new_format_732_v1_gpt41",
    "new_format_732_v1_gemini",
]
APPROVAL_BASIS = (
    "Generated from approved legacy AR16 mapping plus explicit "
    "ar16_key_equivalences.json entries; non-AR16 summaries stay aggregate-only."
)


def build_inventory(
    legacy_mapping_path: Path | None = None,
    equivalences_path: Path = DEFAULT_EQUIVALENCES,
) -> dict:
    legacy_mapping_path = legacy_mapping_path or resolve_web_data_file(LEGACY_MAPPING_FILENAME)
    profile_keys = {}
    for profile_name in MODEL_PROFILES:
        profile = service_predict.resolve_model_profile(profile_name)
        service_predict.validate_profile_inventory(profile)
        profile_keys[profile_name] = service_predict.load_profile_esrs_columns(profile)

    first_keys = profile_keys[MODEL_PROFILES[0]]
    for profile_name, keys in profile_keys.items():
        if keys != first_keys:
            raise ValueError(f"Profile {profile_name} does not match the canonical {MAPPING_VERSION} key order.")

    legacy_mapping = load_json(legacy_mapping_path)
    equivalences = load_json(equivalences_path)
    legacy_topics_by_key = build_legacy_topics_by_key(legacy_mapping)
    equivalences_by_key = build_equivalences_by_source_key(equivalences)
    rows = [
        build_mapping_row(key, legacy_topics_by_key, equivalences_by_key)
        for key in first_keys
    ]
    approved_key_count = len([
        row
        for row in rows
        if row["status"] == "approved" and row["ar16_topic_ids"]
    ])

    return {
        "schema_version": "1.0",
        "mapping_version": MAPPING_VERSION,
        "status": "runtime-approved-for-candidate-suggestions",
        "model_profiles": MODEL_PROFILES,
        "model_key_count": len(first_keys),
        "approved_key_count": approved_key_count,
        "approved_by": "local-ar16-equivalence-crosswalk",
        "approved_at": "2026-06-08",
        "approval_basis": APPROVAL_BASIS,
        "keys": rows,
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Export the fail-closed AR16 mapping inventory for the 732-report new-format model profiles."
    )
    parser.add_argument("--output", type=Path, default=None)
    parser.add_argument("--legacy-mapping", type=Path, default=None)
    parser.add_argument("--equivalences", type=Path, default=DEFAULT_EQUIVALENCES)
    args = parser.parse_args()

    output_path = args.output or (resolve_web_data_dir(LEGACY_MAPPING_FILENAME) / NEW_FORMAT_MAPPING_FILENAME)
    inventory = build_inventory(
        legacy_mapping_path=args.legacy_mapping,
        equivalences_path=args.equivalences,
    )
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(inventory, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {output_path} with {inventory['model_key_count']} keys.")
    return 0


def load_json(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"{path} must contain a JSON object.")
    return value


def build_legacy_topics_by_key(mapping: dict) -> dict[str, list[dict]]:
    topics_by_key: dict[str, list[dict]] = {}
    for topic in mapping.get("candidate_topics", []):
        if not isinstance(topic, dict) or topic.get("mapping_status") != "approved":
            continue
        for key in topic.get("python_esrs_keys", []):
            if not isinstance(key, str) or not key:
                continue
            topics_by_key.setdefault(key, []).append(topic)
    return topics_by_key


def build_equivalences_by_source_key(equivalences: dict) -> dict[str, list[dict]]:
    by_source_key: dict[str, list[dict]] = {}
    for item in equivalences.get("equivalences", []):
        if not isinstance(item, dict):
            continue
        source_key = item.get("source_key")
        if not isinstance(source_key, str) or not source_key:
            continue
        by_source_key.setdefault(source_key, []).append(item)
    return by_source_key


def build_mapping_row(
    key: str,
    legacy_topics_by_key: dict[str, list[dict]],
    equivalences_by_key: dict[str, list[dict]],
) -> dict:
    direct_topics = legacy_topics_by_key.get(key, [])
    if direct_topics:
        return approved_row(
            key=key,
            topics=direct_topics,
            notes="Direct key match against the approved legacy AR16 mapping.",
        )

    equivalences = equivalences_by_key.get(key, [])
    valid_targets: dict[str, list[dict]] = {}
    for equivalence in equivalences:
        if equivalence.get("status") != "equivalent":
            continue
        target_key = equivalence.get("target_key")
        if not isinstance(target_key, str) or not target_key:
            continue
        target_topics = legacy_topics_by_key.get(target_key, [])
        if target_topics:
            valid_targets[target_key] = target_topics

    if len(valid_targets) == 1:
        target_key, target_topics = next(iter(valid_targets.items()))
        return approved_row(
            key=key,
            topics=target_topics,
            notes=f"Equivalent to approved legacy key {target_key}.",
            target_key=target_key,
        )

    if len(valid_targets) > 1:
        return review_row(
            key=key,
            notes="Multiple valid equivalence targets found; kept review-only to avoid ambiguous AR16 activation.",
        )

    statuses = {equivalence.get("status") for equivalence in equivalences}
    if key.endswith("_summary") or "known_non_ar16_summary" in statuses:
        return {
            "python_esrs_key": key,
            "status": "aggregate_only",
            "ar16_topic_ids": [],
            "notes": "Standard-level summary output; it can inform review but is not an AR16 matter/submatter candidate.",
        }

    if "review_only_non_ar16" in statuses:
        return review_row(
            key=key,
            notes="Known non-AR16 residual bucket; kept in manual review if predicted positive.",
        )

    return review_row(
        key=key,
        notes="No approved AR16 equivalence found; kept review-only until domain mapping is supplied.",
    )


def approved_row(key: str, topics: list[dict], notes: str, target_key: str | None = None) -> dict:
    topic_ids = sorted({
        int(topic["ar16_topic_id"])
        for topic in topics
        if "ar16_topic_id" in topic
    })
    row = {
        "python_esrs_key": key,
        "status": "approved",
        "ar16_topic_ids": topic_ids,
        "notes": notes,
    }

    if target_key:
        row["equivalent_to_python_esrs_key"] = target_key

    first_topic = copy.deepcopy(topics[0]) if topics else {}
    if len(topic_ids) == 1:
        for field in ["web_esrs", "web_label_en", "web_theme_en", "web_subtheme_en", "web_subtopic_en"]:
            if field in first_topic:
                row[field] = first_topic[field]

    return row


def review_row(key: str, notes: str) -> dict:
    return {
        "python_esrs_key": key,
        "status": "review_only",
        "ar16_topic_ids": [],
        "notes": notes,
    }


if __name__ == "__main__":
    raise SystemExit(main())
