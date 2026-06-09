# Characterization Prediction Contract v0

Status: adapter implemented in Laravel. Local `.env.example` stays on
`CHARACTERIZATION_GATEWAY=mock`; the private VPS is intentionally on
`CHARACTERIZATION_GATEWAY=api` per the current deployment notes.

## Current Implementations

Laravel web app:

- Interface: `app/web/app/Services/Contracts/CharacterizationGateway.php`
- Current API gateway: `app/web/app/Services/ApiCharacterizationGateway.php`
- Current external call: `POST {CHARACTERIZATION_API_BASE_URL}/predict`
- Current auth expectation: optional bearer token; internal dev service currently ignores auth
- Current response normalization: `App\Services\CharacterizationPredictionMapper`

Python AI service:

- Service: `app/ai-service/src/services/endpoints.py`
- Current prediction endpoint: `POST /predict`
- Current profile inventory endpoint: `GET /model-profiles`
- Current auth expectation: none
- Default runtime profile: `legacy_v0`
- Runtime-enabled profiles: `legacy_v0`, `new_format_732_v1_gpt41`
- Known but blocked profiles: `new_format_732_v1_gemini`
- Current response: an `esrs` object plus profile/mapping metadata. Older
  Laravel consumers that only read `esrs` remain compatible.

## Adapter Shape

The product uses a Laravel-side adapter layer for P5/P6. The adapter normalizes
current Laravel characterization data into the Python `/predict` payload and
maps Python prediction keys back into P6 candidate AR16 topics.

The Laravel P5 wizard now captures the profile fields required by the Python
payload: company name, headquarters country, reporting year, reporting scope,
subsidiary-country count, stock-listing status, and reporting currency. It also
stores a controlled product/service type for P5 characterization. The adapter
keeps the legacy numeric payload intact and also forwards optional new-format
crosswalk fields (`subsidiaries_regions`, `products_services`, `juridic_form`
when present) so the 732-report profiles can be shadow-tested without changing
the default runtime profile. When Laravel requests `new_format_732_v1_gpt41`,
the adapter uses the 732 AR16 mapping inventory by default. Employee count and
annual revenue are captured as SME-friendly ranges; the adapter converts those
range keys into representative numeric estimates for the existing Python
payload. Older drafts remain supported through adapter defaults and legacy
exact numeric fallback.

The runtime NACE/CNAE catalog is Spanish CNAE 2025 for UI/search. The Python
classifier, however, was trained on a small English sector vocabulary stored in
`app/ai-service/data/sector_columns.pkl`. The Laravel adapter therefore maps the
selected CNAE section to a local Python sector label when one is known
(`A` -> `Agriculture`, `K` -> `Information technology`, etc.) instead of
forwarding the Spanish catalog title directly. Unknown sections fall back to the
catalog title.

### Request From Laravel Characterization

```json
{
  "model_profile": "legacy_v0",
  "company_name": "ENTITY_1",
  "sector_list": ["PYTHON_SECTOR_LABEL_OR_CNAE_TITLE"],
  "headquarters_country": "ES",
  "num_subsidiaries_countries": 0,
  "subsidiaries_regions": ["EU"],
  "products_services": ["A", "C"],
  "juridic_form": "LLC",
  "employees_total": 0,
  "annual_turnover_million_euro": 0,
  "stock_listed": false,
  "reporting_currency": "EUR"
}
```

`model_profile` is optional. When omitted, Python uses
`I4S_AI_MODEL_PROFILE`, defaulting to `legacy_v0`. Unknown profile values return
HTTP 422. `new_format_732_v1_gpt41` is runtime-enabled. It applies a
high-confidence score filter before returning binary ESRS values
(`I4S_AI_NEW_FORMAT_SCORE_THRESHOLD=0.95`). The service does not cap the number
of returned positive keys; if many keys pass the score filter, Laravel must treat
that as P6/P8 review evidence rather than as final materiality.
`new_format_732_v1_gemini` is inventoried but not runtime-enabled and returns
HTTP 422.

### Profile Inventory

`GET /model-profiles` returns the active profile, runtime-enabled profiles, and
the known profile inventory without executing a prediction. It exposes
`legacy_v0` and `new_format_732_v1_gpt41` as runtime-enabled, and
`new_format_732_v1_gemini` as known but blocked.

### Raw Response From Python `/predict`

```json
{
  "esrs": {
    "esrs_e1_climate_change_mitigation": 1
  },
  "model_profile": "new_format_732_v1_gpt41",
  "model_key_count": 102,
  "mapped_key_count": 0,
  "feature_metadata": {
    "derived_fields": {},
    "defaulted_fields": {},
    "missing_required_fields": []
  },
  "mapping_metadata": {
    "mapping_status": "external_laravel_mapping",
    "runtime_activation": "runtime_enabled",
    "new_format_score_threshold": 0.95,
    "raw_positive_key_count": 56,
    "threshold_positive_key_count": 26,
    "excluded_non_candidate_key_count": 2,
    "emitted_positive_key_count": 24
  },
  "evidence_refs": []
}
```

### Response To Laravel P6

```json
{
  "candidate_topics": [
    {
      "ar16_topic_id": "AR16_TOPIC_ID",
      "python_esrs_keys": ["esrs_example_key"],
      "score_source": "python_predict",
      "suggested": true
    }
  ],
  "review_required_prediction_keys": [
    "esrs_e3_other"
  ],
  "raw_prediction": {
    "esrs_example_key": 1
  },
  "model_profile": "legacy_v0",
  "model_key_count": 96,
  "mapped_key_count": 92,
  "feature_metadata": {
    "derived_fields": {},
    "defaulted_fields": {},
    "missing_required_fields": []
  },
  "mapping_metadata": {
    "python": {
      "mapping_status": "external_laravel_mapping"
    },
    "laravel": {
      "mapping_version": "v0",
      "mapping_status": "runtime-approved-for-candidate-suggestions",
      "mapping_key_count": 92,
      "mapping_model_key_count": null,
      "mapping_sha256": "..."
    }
  },
  "evidence_refs": []
}
```

## Rules

- The adapter must not mark final materiality. It only proposes P6 candidates.
- The adapter must apply an explicit runtime mapping file; ESRS prefix matching alone is not sufficient. `legacy_v0` uses `app/web/data/ar16_to_python_esrs_mapping.json`. `new_format_732_v1_*` uses `app/web/data/ar16_to_python_esrs_mapping_new_format_732_v1.json` unless `CHARACTERIZATION_PREDICTION_MAPPING_PATH` explicitly overrides the path.
- Positive Python keys classified as `needs_review`, `review_only`, or `aggregate_only`, plus positive keys unknown to the runtime mapping, must be returned in `review_required_prediction_keys` instead of being silently dropped.
- `esrs_e3_other` is a review-only residual E3 bucket: it does not map to one specific AR16 candidate topic, but positive predictions remain visible for manual review.
- `num_subsidiaries_countries` must come from the explicit P5 subsidiary-country count, not from broad operation-region selections.
- Employee count and annual turnover must prefer the P5 range keys when present, with exact numeric values retained only as legacy fallback.
- A `200` response with `"esrs": {}` is a valid zero-candidate prediction. A missing `esrs` object, non-object `esrs`, non-JSON body, or non-binary ESRS value is contract drift and must fail closed instead of creating a completed empty P6 proposal.
- `new_format_732_v1_gpt41` is runtime-enabled only with the high-confidence
  score filter. The AI service must not cap emitted candidate count by default:
  many high-confidence positives are surfaced for P6/P8 review and are not final
  materiality.
- The 732-report mapping inventory has 102 model keys: 91 approved through the
  local AR16 equivalence crosswalk, 10 `aggregate_only` standard summaries, and
  1 `review_only` residual key.
- `src/shadow_model_profiles.py` can run legacy and 732 profiles offline
  against either the three SME calibration cases (10, 150, and 400 employees)
  or a broader heterogeneous matrix. It projects positive keys through the 732
  AR16 mapping inventory.
- `/retrain` only supports `legacy_v0`. New-format retraining must use a
  separate approved profile pipeline; the current endpoint returns HTTP 422 for
  non-legacy profiles instead of retraining the wrong artifact set.
- Production auth can keep Laravel bearer-token semantics, but local development may use an unauthenticated internal service on the loopback/private network.
- Current live/private VPS use of `CHARACTERIZATION_GATEWAY=api` is a deployment state, not proof that later local fixes are live. Rerun the verifier and deployment checklist before claiming a new live AI-service or Laravel gateway release.
