"""Verified-company, read-only comparison; no model, learning or user data."""
from __future__ import annotations
import csv
import hashlib
import io
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from .materiality_recommender import build_propensity
from .datatypes import SectorArtifact, SectorComparisonRequest, SectorComparisonResponse, SectorPeriod, SectorProvenance

VERSION = "sector-comparison-v1"
TAXONOMY = "NACE Rev.2.1 / CNAE-2025"
SECTIONS = "ABCDEFGHIJKLMNOPQRSTUV"
TOPICS = [str(i) for i in range(1, 90)]
MAX_ARTIFACT = 5 * 1024 * 1024


def strict_json(raw):
    def pairs(items):
        out = {}
        for key, value in items:
            if key in out:
                raise ValueError("duplicate JSON key")
            out[key] = value
        return out
    def reject_constant(_):
        raise ValueError("nonfinite JSON")
    try:
        return json.loads(raw, object_pairs_hook=pairs, parse_constant=reject_constant)
    except RecursionError:
        raise ValueError("JSON nesting limit exceeded") from None


def read_cells(raw, expected):
    with io.StringIO(raw.decode("utf-8"), newline="") as stream:
        reader = csv.DictReader(stream)
        header = reader.fieldnames or []
        if len(set(header)) != len(header) or set(header) != {"file", *expected}:
            raise ValueError("CSV vocabulary mismatch")
        rows = {}
        for row in reader:
            if None in row or not row["file"] or any(row[k] not in ("0", "1") for k in expected):
                raise ValueError("invalid CSV cell")
            report = row.pop("file")
            if report in rows and rows[report] != row:
                raise ValueError("contradictory duplicate report")
            rows[report] = row
        return rows


def build_artifact(*, labels, masks, companies, encoding, topic_mapping):
    paths = dict(labels=labels, masks=masks, companies=companies, encoding=encoding, topic_mapping=topic_mapping)
    inputs = {key: Path(path).read_bytes() for key, path in paths.items()}
    hashes = {key: hashlib.sha256(raw).hexdigest() for key, raw in inputs.items()}
    contract = strict_json(inputs["encoding"])
    if contract.get("version") != "p5-gpt41-encoding-v1" or contract.get("sector_taxonomy") != TAXONOMY:
        raise ValueError("incompatible encoding")
    mapping = strict_json(inputs["topic_mapping"])
    if mapping.get("mapping_version") != "new_format_732_v1" or mapping.get("status") != "runtime-approved-for-candidate-suggestions":
        raise ValueError("incompatible topic mapping")
    keys = [r["python_esrs_key"] for r in mapping["keys"]]
    if len(set(keys)) != len(keys):
        raise ValueError("duplicate mapping key")
    by_topic = {t: [] for t in TOPICS}
    for row in mapping["keys"]:
        if row["status"] == "approved" and not row["python_esrs_key"].endswith("_summary"):
            for t in row["ar16_topic_ids"]:
                if type(t) is not int or str(t) not in by_topic:
                    raise ValueError("invalid mapped topic")
                if row["python_esrs_key"] not in by_topic[str(t)]:
                    by_topic[str(t)].append(row["python_esrs_key"])
    label_rows, mask_rows = read_cells(inputs["labels"], keys), read_cells(inputs["masks"], keys)
    if set(label_rows) != set(mask_rows):
        raise ValueError("labels/masks report mismatch")
    manifest = strict_json(inputs["companies"])
    if set(manifest) != {"version", "sector_taxonomy", "provenance", "companies"} or manifest["version"] != VERSION or manifest["sector_taxonomy"] != TAXONOMY:
        raise ValueError("incompatible company manifest")
    provenance = SectorProvenance.model_validate(manifest["provenance"]).model_dump()
    required = {"company_id", "identity_verified", "report_links_verified", "sections_verified", "report_ids", "selected_report_id", "period", "sections", "sector_taxonomy", "verification_reference"}
    ids, report_owners, normalized, topic_masks, codes, periods = set(), {}, [], {}, {}, []
    excluded = 0
    if not isinstance(manifest["companies"], list) or not manifest["companies"]:
        raise ValueError("empty company population")
    for c in sorted(manifest["companies"], key=lambda c: c["company_id"]):
        if set(c) != required or any(c[k] is not True for k in ("identity_verified", "report_links_verified", "sections_verified")) or c["sector_taxonomy"] != TAXONOMY:
            raise ValueError("unverified identity, link or taxonomy")
        cid = c["company_id"]
        reports, selected, sections = c["report_ids"], c["selected_report_id"], c["sections"]
        if not isinstance(cid, str) or not cid or cid in ids or not isinstance(c["verification_reference"], str) or not c["verification_reference"]:
            raise ValueError("invalid or duplicate company identity")
        if not isinstance(reports, list) or not reports or any(not isinstance(r, str) or not r for r in reports) or len(set(reports)) != len(reports) or selected not in reports or selected not in label_rows:
            raise ValueError("invalid selected or linked report")
        for report in reports:
            if report in report_owners:
                raise ValueError("report shared between companies")
            report_owners[report] = cid
        if not isinstance(sections, list) or not sections or len(set(sections)) != len(sections) or any(not isinstance(s, str) or s not in SECTIONS or len(s) != 1 for s in sections):
            raise ValueError("verified NACE sections required; products are not activities")
        ids.add(cid)
        periods.append(SectorPeriod.model_validate(c["period"]).model_dump())
        excluded += len(reports) - 1
        row, mask = {"file": cid}, {}
        for topic, approved in by_topic.items():
            positive = any(label_rows[selected][k] == "1" and mask_rows[selected][k] == "1" for k in approved)
            negative = bool(approved) and all(label_rows[selected][k] == "0" and mask_rows[selected][k] == "1" for k in approved)
            row[topic] = "1" if positive else "0"
            mask[topic] = "1" if positive or negative else "0"
        normalized.append(row)
        topic_masks[cid], codes[cid] = mask, set(sections)
    if set(label_rows) - set(report_owners):
        raise ValueError("unlinked report rows")
    propensity = build_propensity(normalized, topic_masks, lambda cid: codes[cid], retain_all=True)
    def population(code):
        stats = propensity.get(code, {})
        return {"population_companies": len(ids) if code == "__global__" else sum(code in cs for cs in codes.values()),
                "topics": {t: {"n_scored": stats.get(t, {}).get("n_scored", 0), "n_material": stats.get(t, {}).get("n_material", 0)} for t in TOPICS}}
    artifact = {"version": VERSION, "built_at": datetime.now(timezone.utc).isoformat(),
                "selection_policy": "operator-selected-report-per-verified-company",
                "aggregation_policy": "known-positive-any-known-negative-all-approved-keys",
                "sector_taxonomy": TAXONOMY, "min_support": 5, "hashes": hashes,
                "provenance": provenance, "coverage_period": {"start": min(p["start"] for p in periods), "end": max(p["end"] for p in periods)},
                "vocabulary": TOPICS, "excluded_additional_reports": excluded,
                "global_population": population("__global__"), "sections": {s: population(s) for s in SECTIONS}}
    return SectorArtifact.model_validate(artifact).model_dump()


def write_artifact(artifact, output):
    """Atomic link of a validated same-filesystem temp file; never overwrite."""
    import tempfile
    raw = (json.dumps(SectorArtifact.model_validate(artifact).model_dump(), sort_keys=True, indent=2) + "\n").encode()
    if len(raw) > MAX_ARTIFACT:
        raise ValueError("artifact exceeds limit")
    target = Path(output)
    fd, name = tempfile.mkstemp(dir=target.parent, prefix=".sector-")
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.link(name, target)  # atomic, exclusive create
    finally:
        os.unlink(name)


def load_snapshot(path, expected_hash):
    import re
    if not isinstance(expected_hash, str) or re.fullmatch(r"[0-9a-f]{64}", expected_hash) is None:
        raise ValueError("pinned digest required")
    with Path(path).open("rb") as stream:
        raw = stream.read(MAX_ARTIFACT + 1)
    if len(raw) > MAX_ARTIFACT or hashlib.sha256(raw).hexdigest() != expected_hash:
        raise ValueError("artifact size or digest mismatch")
    return SectorArtifact.model_validate(strict_json(raw))


def compare_topics(artifact, artifact_hash, sections, topics):
    request = SectorComparisonRequest.model_validate(dict(version=VERSION, sections=sections, topics=topics))
    comparisons = []
    for code in request.sections or [None]:
        sector = artifact.sections[code] if code else None
        cells = []
        for topic in request.topics:
            s = sector.topics[topic].model_dump() if sector else dict(n_scored=0, n_material=0)
            g = artifact.global_population.topics[topic].model_dump()
            source, counts = ("sector", s) if s["n_scored"] >= 5 else (("global_fallback", g) if g["n_scored"] >= 5 else ("insufficient_data", None))
            cells.append(dict(topic_id=topic, source=source, low_confidence=source != "sector",
                              proportion=counts["n_material"] / counts["n_scored"] if counts else None,
                              sector=s, global_counts=g))
        comparisons.append(dict(section=code, population_companies=sector.population_companies if sector else 0, topics=cells))
    return SectorComparisonResponse.model_validate(dict(version=VERSION, artifact_sha256=artifact_hash,
        built_at=artifact.built_at, hashes=artifact.hashes.model_dump(), provenance=artifact.provenance.model_dump(),
        coverage_period=artifact.coverage_period.model_dump(), sector_taxonomy=TAXONOMY, min_support=5,
        global_population_companies=artifact.global_population.population_companies, comparisons=comparisons)).model_dump()
