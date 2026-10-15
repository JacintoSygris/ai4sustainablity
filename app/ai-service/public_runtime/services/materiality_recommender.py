"""Legacy propensity proposals. Codes are caller-defined, never inferred from products.

The separate sector comparison uses verified NACE Rev.2.1 sections and one
selected report per company; it never calls recommend() or ranks topics.
"""
from __future__ import annotations

from dataclasses import dataclass, field

MIN_SUPPORT = 5  # a (code, topic) needs >= this many scored companies to be trusted


@dataclass
class Proposal:
    esrs_key: str
    label: str
    score: float                      # 0..1, the max propensity across the company's codes
    drivers: list[dict] = field(default_factory=list)  # provenance: [{code, pct, n_scored}]
    low_confidence: bool = False      # true when only the global fallback supported it


def build_propensity(esrs_rows: list[dict], mask_by_file: dict, codeset_of,
                     min_support: int = MIN_SUPPORT, *, retain_all: bool = False) -> dict:
    """code -> {topic: {'pct': float, 'n_material': int, 'n_scored': int}} + a
    '__global__' base rate. Counts only confident (mask==1) cells. A (code, topic)
    needs >= min_support scored companies to be kept by default. retain_all=True
    preserves zero/thin cells for informational comparison, without changing recommend()."""
    cols = [c for c in esrs_rows[0] if c != "file"]
    num = {}; den = {}
    gnum = {c: 0 for c in cols}; gden = {c: 0 for c in cols}
    for r in esrs_rows:
        f = r["file"]; m = mask_by_file.get(f, {})
        codes = codeset_of(f) or set()
        for c in cols:
            if m.get(c) != "1":
                continue
            material = 1 if r[c] == "1" else 0
            gnum[c] += material; gden[c] += 1
            for code in codes:
                num.setdefault(code, {}).setdefault(c, 0)
                den.setdefault(code, {}).setdefault(c, 0)
                num[code][c] += material; den[code][c] += 1
    out = {"__global__": {c: {"pct": (gnum[c] / gden[c] if gden[c] else 0.0),
                              "n_material": gnum[c], "n_scored": gden[c]} for c in cols}}
    if retain_all:
        for r in esrs_rows:
            for code in codeset_of(r["file"]) or set():
                num.setdefault(code, {c: 0 for c in cols})
                den.setdefault(code, {c: 0 for c in cols})
                for c in cols:
                    num[code].setdefault(c, 0)
                    den[code].setdefault(c, 0)
    for code in den:
        kept = {c: {"pct": (num[code][c] / den[code][c] if den[code][c] else 0.0), "n_material": num[code][c],
                    "n_scored": den[code][c]}
                for c in den[code] if retain_all or den[code][c] >= min_support}
        if kept:
            out[code] = kept
    return out


def recommend(codes: set[str], propensity: dict, labels: dict,
              threshold: float = 0.35, top_n: int | None = None) -> list[Proposal]:
    """Return one deduped, ranked, explained topic list for a company's code set."""
    glob = propensity["__global__"]
    usable = [c for c in codes if c in propensity]
    topics: dict[str, Proposal] = {}
    if not usable:
        # no code match -> explicit low-confidence base-rate fallback, never silent
        for key, stat in glob.items():
            if stat["pct"] >= threshold:
                topics[key] = Proposal(key, labels.get(key, key), round(stat["pct"], 4),
                                       [{"code": "(base rate)", "pct": round(stat["pct"], 4),
                                         "n_scored": stat["n_scored"]}], low_confidence=True)
    else:
        for code in usable:
            for key, stat in propensity[code].items():
                p = stat["pct"]
                if p < threshold:
                    continue
                driver = {"code": code, "pct": round(p, 4), "n_scored": stat["n_scored"]}
                cur = topics.get(key)
                if cur is None:
                    topics[key] = Proposal(key, labels.get(key, key), round(p, 4), [driver])
                else:
                    cur.drivers.append(driver)
                    cur.score = max(cur.score, round(p, 4))
        for p in topics.values():
            p.drivers.sort(key=lambda d: -d["pct"])  # strongest driver first
    ranked = sorted(topics.values(), key=lambda x: -x.score)
    return ranked[:top_n] if top_n else ranked
