# DMA text retrieval v1

Internal `POST /dma/process`, JSON Schema: `dma-processing-v1.schema.json`
(`$defs/request`, `$defs/response`). A concept here is an **IG-3 datapoint ID**, never an XBRL QName.

Both `DMA_NLP_ENABLED=false` (Laravel) and `I4S_DMA_NLP_ENABLED=false`
(Python) require explicit activation; Laravel additionally requires DMA import and
reviewed IRO traceability. Python remains on `127.0.0.1`, with one ASGI process.
Bearer `I4S_AI_API_TOKEN` is required; missing configuration is 503, wrong
credentials 401, disabled 404. Authentication and the actual streamed 65,536-byte
limit precede parsing. Invalid schema/duplicate keys: 422; oversize: 413;
body read deadline: 408; capacity or infrastructure failure: 503 + Retry-After.
No infrastructure failure is represented as a successful abstention.

Request: version `dma-processing-v1`, locale `es|en`, 1–20 distinct opaque
64-hex IRO IDs with exact original descriptions (1–2,000 UTF-8 bytes) and
source SHA-256. No company identifiers, scores, catalogue selectors, topic IDs,
or user-selected output concepts. Other languages are rejected at this boundary.
The reserved per-level `unsupported_language` response code is not inferred by
an unvalidated language detector; the caller must select Spanish or English.

Response: pinned version, model SHA-256, catalogue version/hash, one ordered row
per requested IRO, description SHA-256, and separate `topic`, `dr`, `datapoint`
levels. Each has up to three uniquely identified, descending-similarity
candidates (canonical ID lexical tie break), canonical locale label and one
supporting span; otherwise `insufficient_evidence` or `ambiguous`. Scores are
finite [0,1] cosine similarities, **not probabilities**. Each span has `start`,
`end` (half-open Unicode code-point indices), `quote` copied exactly from the
original description and `catalogue_ref` identifying the matched allowlisted
row. A span is lexical support, not factual verification or entailment.
Negation anywhere causes conservative abstention; explicit uncertainty and
low/marginal scores also abstain. Text is never translated or logged.

A fresh spawn worker loads the hash-pinned JSON package, reconstructs sparse
TF-IDF vectors, and exits after each batch. The parent terminates/kills and reaps
it on timeout (ten seconds), failure, or completion. The response is capped at
256 KiB. Dedicated capacity is independent of P6, XBRL matching and learning.

Laravel uses a configured loopback URL and bearer token, connect timeout 2s,
total cURL timeout 12s, bounded transfer sink, no redirects/retries. It checks
exact object keys, result length/order, pinned hashes, seeded AR16 identities,
canonical labels/IDs, scores, uniqueness, ordering and code-point spans. The
Laravel image needs only `web/data/dma_nlp_model_v1.json` and its PHP hash pin;
it does not need a Python checkout. Python requires an external package configured by `I4S_DMA_NLP_MODEL_DIR`, containing
`model.json`, `manifest.json` and the three public source files in `catalogues/`.
Generate it with `scripts/dma_processing/build_model.py --output-dir "$DMA_MODEL_DIR"`
from `app/ai-service` with `PYTHONPATH=src`. The output directory must be outside the
checkout. Both runtimes pin the public catalogue manifest; missing or incompatible
assets fail closed. No model package or evaluation output is distributed in Git.

Browser API, under existing web/session/CSRF/auth/verification middleware:

- `POST /api/characterizations/{characterization}/dma-imports/{import}/nlp-runs`:
  stage `p8|p9`, locale, 1–20 IRO IDs, `expected_context` and `expected_revision`.
  Five requests/minute/user. Text is resolved only from the owned import.
- `GET /api/characterizations/{characterization}/dma-nlp-runs/{run}`: thirty/minute/user.
- `POST /api/characterizations/{characterization}/dma-nlp-runs/{run}/reviews`:
  server candidate ID, `accepted|rejected`, expected context/revision; twenty/minute/user.

Disabled/foreign resources return 404, invalid input 422, stale context or
conflicting immutable review 409. Identical successful batches are reused by
input/context/model digest (201 for creation, 200 for reuse); each import permits
at most 200 successful batches. Reusing a batch at the limit remains permitted.
The browser explicitly pages all rows in batches of twenty. Context is captured
and checked again under the characterization transaction; the service call is
outside the transaction. Deletion and delayed context changes reject persistence.

P8 accepts only topic associations, kept in a draft and resolved through separate
`dma_nlp_refs` at explicit P8 confirmation, with a reviewed source IRO. They never
become fake `dma_import_refs` for topics absent from the imported suggestions.
DR candidates remain informational. P9 requires a new current-P8-bound run;
only scoped datapoints from material reviewed IROs may be accepted. Decisions are
immutable (an identical retry is idempotent). Rejection contributes no shortlist
entry and changes no obligations. Review never changes normative scope,
applicability, phase-ins, answers or facts. Accepted entries and source/reviewed
context are frozen in form_data and the snapshot/IR evidence graph; exports use
captured evidence, not live run tables. Old approved snapshots survive flag rollback.
