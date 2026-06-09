# AR16 to Python ESRS Mapping v0

Status: runtime mapping file structurally reviewed for candidate suggestions. Domain review is still required before using it as final materiality evidence.

## Inputs

| Source | File | Count |
|---|---|---:|
| Laravel AR16-like topic inventory | `app/contracts/mappings/web-ar16-topic-inventory.csv` | 89 |
| Python prediction key inventory | `app/contracts/mappings/python-esrs-key-inventory.csv` | 96 |
| Runtime adapter mapping | `app/web/data/ar16_to_python_esrs_mapping.json` | 89 candidate rows |

## Why This Gate Exists

The Laravel app stores ESRS topic selections as AR16-like topics from `app/web/data/esrs_topics.json`. The Python service predicts 96 `esrs_*` columns from `app/ai-service/data/company_esrs.csv`.

These are related vocabularies, not the same schema. Some Python keys are aggregate labels, some are detailed subtopics, and some have naming drift or typos. A direct endpoint connection would produce misleading P6 output.

## Mapping Rules

- Every Laravel topic used in P6 must map to zero, one, or many Python keys explicitly.
- Every Python key with prediction value `1` must either map to one or more Laravel topics or be classified as `aggregate_only`, `out_of_scope`, `needs_review`, or `review_only`.
- Aggregate Python keys may influence candidate grouping but must not create final P9 datapoints.
- P9 mapping remains a separate deterministic chain: AR16 -> ESRS -> Disclosure Requirement -> datapoint.
- The accepted mapping should be machine-readable before adapter code is merged.

## Machine-Readable Shape

```json
{
  "ar16_topic_id": 1,
  "web_esrs": "E1",
  "web_label_en": "Adaptation to climate change",
  "python_esrs_keys": ["esrs_e1_adaptation_to_climate_change"],
  "mapping_status": "approved",
  "notes": ""
}
```

Allowed `mapping_status` values:

- `approved`
- `needs_review`
- `review_only`
- `aggregate_only`
- `out_of_scope`

Python keys that do not correspond to one candidate AR16 topic are recorded in
the top-level `python_key_statuses` object. Current classifications:

- `aggregate_only`: `esrs_e1_climate_change`, `esrs_e2_pollution`, `esrs_e3_water_and_marine_resources`
- `review_only`: `esrs_e3_other`

Domain decision for `esrs_e3_other`: the Python key is an E3 residual bucket
defined as "Any other issue reported about ESRS E3". The Laravel AR16-like E3
inventory has specific topics for water consumption, water extractions, water
spills, water spills into oceans, and extraction/use of marine resources, but no
E3 "Other" topic. Mapping the residual bucket to any one of those specific AR16
topics would overclaim materiality. Therefore `esrs_e3_other` is accepted as
`review_only`: it must not create a P6 candidate topic, but a positive
prediction remains visible through `review_required_prediction_keys` for manual
review.

## Structural Review Evidence

Verified on 2026-06-04:

- 89 runtime candidate rows match the 89 Laravel AR16-like inventory rows.
- 96 Python prediction keys are fully covered: 92 mapped key references plus 4 top-level `python_key_statuses`.
- Unknown mapped keys: 0.
- Unknown status keys: 0.
- Uncovered Python keys: 0.
- Duplicate AR16 rows: 0.
- Duplicate Python key references: 0.
- Empty approved mapping rows: 0.
- ESRS prefix mismatches: 0.
- Web inventory label mismatches: 0.

## Next Work

The Laravel adapter now consumes `app/web/data/ar16_to_python_esrs_mapping.json`
and only emits candidate topics for rows whose `mapping_status` is `approved`.
Aggregate Python keys such as `esrs_e1_climate_change`, `esrs_e2_pollution`,
and `esrs_e3_water_and_marine_resources` are recorded as aggregate-only and do
not create AR16 candidate topics by themselves. Positive `needs_review` or
`review_only` keys, and any positive key unknown to the runtime mapping, are
exposed as `review_required_prediction_keys` in the Laravel adapter response.

The private VPS is now intentionally configured with
`CHARACTERIZATION_GATEWAY=api` per the current deployment notes. Keep
`.env.example` on `mock` for local development, and run an intentional gateway
smoke test against the private Python service before claiming any future mapping
or gateway release as live.
