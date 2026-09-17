# Proposal Integration

Back to the [documentation index](../index.md).

The proposal service receives normalised characterisation and returns candidate keys. Laravel converts them through configured mappings before showing them as reviewable topics.

## Technical Surface

- `POST /predict`
- `GET /healthz`
- `GET /model-profiles`

The included profile uses the technical profile `new_format_732_v1_gpt41`.

## Interpretation Rules

- The output proposes candidate topics, not final materiality.
- A response with no candidates may be valid.
- Unknown or review-only keys must not be converted automatically into decisions.
- A key prefix is not enough to create a topic.
- The final selection must be confirmed by the user.

An integration must preserve this separation in its screens and messages.
