"""Closed ESRS Set 1 DR context vocabulary, independent of training coverage."""

TOPICAL_DR_COUNTS = {"E1": 9, "E2": 6, "E3": 5, "E4": 6, "E5": 6,
                     "S1": 17, "S2": 5, "S3": 5, "S4": 5, "G1": 6}
DR_STANDARDS = {
    **{f"{standard}-{number}": standard
       for standard, count in TOPICAL_DR_COUNTS.items() for number in range(1, count + 1)},
    **{code: "ESRS 2" for code in (
        "BP-1", "BP-2", "GOV-1", "GOV-2", "GOV-3", "GOV-4", "GOV-5",
        "SBM-1", "SBM-2", "SBM-3", "IRO-1", "IRO-2", "MDR-P", "MDR-A", "MDR-M", "MDR-T")},
}


def supported_context(indicator: dict) -> bool:
    standard, dr = indicator.get("standard"), indicator.get("dr")
    if standard is not None and standard not in {"ESRS 2", *TOPICAL_DR_COUNTS}:
        return False
    return dr is None or (dr in DR_STANDARDS and
                          (standard is None or DR_STANDARDS[dr] == standard))
