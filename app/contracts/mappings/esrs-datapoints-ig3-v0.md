# ESRS Datapoints IG3 v0

Status: runtime dataset available for P9 corpus generation. Standard-level
fallback is active by default; a valid approved AR16 matter -> DR mapping can
be loaded through `ESRS_MATTER_DR_MAPPING_PATH`.

## Source

| Field | Value |
|---|---|
| Source name | EFRAG IG 3 List of ESRS Data Points |
| Workbook version | 2025-06 |
| Source URL | `https://www.efrag.org/sites/default/files/media/document/2025-06/EFRAG%20IG%203%20List%20of%20ESRS%20Data%20Points%20%281%29%20%281%29.xlsx` |
| Downloaded | 2026-06-04 |
| SHA256 | `90F15872C489786D86C445D8DC02E00783EB16ECD998D6E1F5A43AD48EDD8BE9` |

EFRAG IG 3 is non-authoritative implementation guidance. ESRS remains the
authoritative source if conflicts exist.

## Runtime File

`app/web/data/esrs_datapoints_ig3.json`

Generated from the workbook with `openpyxl` in read-only/data-only mode. Rows
without a datapoint ID, ESRS standard, or name are skipped.

Runtime row counts:

| Inclusion type | Count |
|---|---:|
| `always_required` | 146 |
| `minimum_disclosure_requirement` | 44 |
| `materiality_based` | 994 |
| **Total** | **1184** |

## Current P9 Logic

`GET /api/esrs-datapoints` uses:

1. P8 `form_data.materiality_confirmation.confirmed_topic_ids` when present.
2. P6 `esrs_topic_ids` as fallback when P8 has not been written yet.
3. ESRS 2 datapoints as always required.
4. Topical E/S/G datapoints filtered by activated ESRS standard by default.
   If `ESRS_MATTER_DR_MAPPING_PATH` points to an approved mapping JSON that
   covers every selected material topic, has one mapping row per selected topic,
   and validates against the seeded ESRS topics plus IG3 DR keys, topical
   datapoints are filtered by the mapped `(ESRS standard, Disclosure Requirement)`
   pairs instead.
5. ESRS 2 MDR datapoints as a conditional block when at least one topical
   standard is material.
6. E1 non-material exception status when P6 proposed E1 and final P8 removed E1.
7. Disclosure Requirement groupings inside each datapoint block so the frontend
   can render P9 by DR without re-deriving grouping logic client-side.
8. A `completion_plan` with a stable recommended order: ESRS 2 baseline,
   topical material standards, ESRS 2 MDR review, and the E1 non-material
   explanation when it applies.
9. A `matter_mapping` block that lists each confirmed material AR16 topic. With
   no approved map, each topic is marked `pending_explicit_dr_mapping` and the
   standard-level DR/datapoint counts are exposed as the transparent fallback.
   With full valid approved-map coverage, each topic is marked
   `mapped_to_disclosure_requirements` and exposes the mapped DR keys plus the
   mapped datapoint count. Partial, duplicate-selected-topic, or invalid maps do
   not advertise per-topic DR-level filtering.
10. A `phase_in_assessment` block that evaluates the current employee-count
    data against the ESRS `<750` phase-in threshold and counts phase-in
    datapoints for frontend planning.
11. An `applicability` block on each datapoint DTO, giving the frontend a
    deterministic "why this datapoint is here" explanation without re-deriving
    backend mapping logic.

Each `disclosure_requirements` entry contains:

- `key`: normalized DR key, or `unassigned` if the source row has no explicit
  DR.
- `standard`: the source ESRS standard value for the group.
- `dr`: the original DR value, or `null` when unassigned.
- `datapoint_count` and `datapoint_ids`.

Each `completion_plan.phases` entry contains:

- `sequence`, `key`, `title`, `block_key`, `applies`, `standards`,
  `datapoint_count`, and `status`.
- The topical phase exposes `coverage_status`. It is `standard_level_partial`
  by default and becomes `dr_level` only when a fully covering approved map is
  loaded.

Each `matter_mapping.material_topics` entry contains:

- `topic_id`, `esrs_code`, and localized AR16 topic labels.
- `mapping_status`: `pending_explicit_dr_mapping` by default, or
  `mapped_to_disclosure_requirements` when a valid approved map covers every
  selected material topic and DR-level filtering is actually applied.
- `current_filter`: `standard_level` or `disclosure_requirement_level`.
- Pending topics include `standard_level_disclosure_requirement_count` and
  `standard_level_datapoint_count`, calculated from the activated ESRS standard
  in the IG3 corpus.
- Mapped topics include `mapped_disclosure_requirement_keys`,
  `mapped_disclosure_requirement_count`, and `mapped_datapoint_count`.

When no approved map is configured, this block is intentionally a transparency
scaffold, not exact filtering.

Each datapoint `applicability` block contains:

- `block_key`: `always_required`, `topical`, or
  `minimum_disclosure_requirements`.
- `reason_code`: `always_required_esrs_2`, `material_esrs_standard`, or
  `conditional_esrs_2_mdr`.
- `reason`: frontend-ready copy explaining why the datapoint is included.
- `mapping_basis`: `always_required`, `activated_esrs_standard`,
  `mapped_disclosure_requirements`, or
  `conditional_mdr_for_material_topics`.
- `source_chain`: IG3 source dataset, ESRS standard, Disclosure Requirement
  key/null, and datapoint ID.
- `limitations`: visible caveats. The default topical fallback includes a
  limitation that no fully covering approved AR16 matter -> DR map is loaded.

## Optional AR16 Matter -> DR Map

The optional map is configured through `ESRS_MATTER_DR_MAPPING_PATH`. The file
is used only when `source.status` is `approved`; missing, malformed, unapproved,
or empty files leave the endpoint on the standard-level fallback.

Minimal JSON shape:

```json
{
  "version": "v0",
  "source": {
    "name": "Approved AR16 matter to DR map",
    "status": "approved",
    "approved_at": "2026-06-04"
  },
  "mappings": [
    {
      "ar16_topic_id": 2,
      "esrs_code": "E2",
      "disclosure_requirements": ["E2.IRO-1"]
    }
  ]
}
```

The backend applies the DR-level filter only when every selected material topic
has exactly one valid mapping row. A valid row must reference an existing seeded
`EsrsTopic`, its `esrs_code` must match that topic's seeded ESRS standard, and
each mapped DR key must exist in the IG3 corpus for that same standard. The
runtime filter uses `(ESRS standard, DR)` pairs so cross-standard DR-key
collisions in the sourced IG3 corpus do not leak into another selected standard.
If coverage is partial, any selected topic is duplicated, or any selected mapping
is invalid, the API reports `matter_mapping.status=partial`, keeps
`coverage_status=standard_level_partial`, shows a visible limitation, and
continues filtering topical datapoints at activated-standard level.

Each `phase_in_assessment` entry contains:

- `status`: `eligible_less_than_750`, `not_eligible_750_or_more`, or
  `unknown_employee_count`.
- `employee_count`: source (`employee_count`, `employee_count_range`, or
  `missing`), range key, numeric estimate, and `less_than_750` boolean/null.
- `counts`: datapoints with `<750` phase-in relief, datapoints with
  all-undertaking phase-in text, and the currently applicable phase-in count.

This block does not remove datapoints from the corpus. It exists so the frontend
can explain planning implications while keeping the full deterministic corpus
available.

## Known Limitation

The default granularity is `standard_level`. It does not map each AR16 matter to
specific Disclosure Requirements unless an approved map is configured. Without
that map, the API exposes:

```json
{
  "mapping_granularity": "standard_level",
  "matter_to_dr_mapping_status": "pending",
  "coverage_status": "standard_level_partial"
}
```

Next mapping work must add the explicit deterministic chain:

`AR16 matter -> ESRS standard -> Disclosure Requirement -> datapoint`

Do not hide this limitation in frontend copy. Until an approved matter-level
map is configured, the corpus is useful for P9 scaffolding and gap analysis but
should not be presented as exact matter-level filtering.
