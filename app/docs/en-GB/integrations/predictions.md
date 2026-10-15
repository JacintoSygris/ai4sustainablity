# Proposal Integration

Back to the [documentation index](../index.md).

The proposal service receives normalised characterisation and returns candidate keys. Laravel converts them through configured mappings before showing them as reviewable topics.

## Technical Surface

- `POST /predict`
- `GET /healthz`
- `GET /model-profiles`

The included profile uses the technical profile `new_format_732_v1_gpt41`. The service serves the code in `app/ai-service/public_runtime`.

## Characterisation Encoding

For `new_format_732_v1_gpt41`, Laravel sends the CNAE-2025 / NACE Rev. 2.1 section letter of the sector directly: banking `L64` → `L`, IT `K62` → `K`, publishing `J58` → `J`. When it receives a numeric division, the service applies the same conversion.

Products use the A–L extraction taxonomy, independent of the sector letters. The versioned mapping is `app/contracts/mappings/p5-gpt41-feature-encoding-v1.json`; Laravel loads its identical copy in `app/web/data/`. No product is inferred for mixed, unknown or missing options, nor from the sector. Headcount is converted to the training size bands, and the default regional value counts the United Kingdom as Europe. The [encoding note](../../fix-ai-encoding.md) (in Spanish) lists the categories and bands the model does not know.

`feature_metadata.derived_fields.encoding_coverage` reports `complete` or `degraded` and lists `unsupported_values`; P6 shows a warning when coverage is `degraded`. It describes encoding coverage, not proposal quality. Because P5 does not collect the legal form, coverage is usually `degraded`.

## Serving Identity And Metadata

Every response includes in `mapping_metadata`:

- `runtime_activation`: `runtime_enabled` for the base model.
- `serving_identity`: profile, SHA-256 of the loaded artefacts, SHA-256 of the policies, effective configuration (threshold and active policies) and `serving_identity_sha256`. Per-label thresholds, the sector filter and the CRC floor are not part of the public service and are reported as inactive.
- `feedback_bandit`, only when feedback-based adjustment is loaded: algorithm, shadow mode, sequence, applied changes, `decision_id` and `basis` (encoder, context, feature-schema hash, model hash and base scores). Laravel keeps it to capture consented P8 events.

## Optional Learning Fields

Laravel may add `learning_shadow_authorized` (boolean, `false` by default) and `learning_shadow_subject` (lowercase UUID) to the request. It derives them from the authenticated account's current consent; they are never copied from the browser. They are not model features, do not authenticate the request and are excluded from training exports. They only permit shadow logging when the service has it enabled; Python rechecks authorisation and withdrawals before logging.

With `LEARNING_ENGINE_ENABLED` and `LEARNING_ENGINE_SERVING_ENABLED` on and a manually promoted candidate, the response may use `model_profile=learning:<candidate-id>`, with the same key axis as the base profile. In that case `runtime_activation` is `explicit_promoted_learning_candidate`, `serving_identity` binds the candidate bytes and its promotion gate, and `learning_base_identity` records the base model identity. Any integrity, consent, expiry or compatibility failure returns the ordinary `new_format_732_v1_gpt41` prediction. With the local profile defaults, learning does not alter the output. See [Optional learning engine](../learning-engine.md).

`GET /healthz` includes a `learning` block (hook availability, effective flags and `runtime_sha256`) and `GET /model-profiles` includes `serving_identity_sha256`. They show whether the running service matches the trainer environment and the baseline identity computed by Laravel.

## Interpretation Rules

- The output proposes candidate topics, not final materiality.
- A response with no candidates may be valid.
- Unknown or review-only keys must not be converted automatically into decisions.
- A key prefix is not enough to create a topic.
- The final selection must be confirmed by the user.

An integration must preserve this separation in its screens and messages.
