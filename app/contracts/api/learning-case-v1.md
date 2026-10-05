# Learning case v1 — passive contract foundation

## Status and boundary

T01A defines a passive, fail-closed contract foundation only. It does not add an HTTP route, frontend request helper, database write, queue, export, training operation, candidate promotion, model activation, or live-data path. Transport remains deferred until the Laravel controllers and workflow state exist in T05/T06.

Canonical machine-readable surfaces:

- `learning-case-v1.schema.json` — immutable case payload.
- `learning-eligibility-v1.schema.json` — Laravel-to-Python eligibility manifest.
- `frontend-characterization-openapi-v0.json` — reusable contract-only components; no operational path is published by T01A.

## Learning case rules

A `human_product` case is eligible only when its reviewed topic universe is explicit and its label set is exactly one integer `0` or `1` per reviewed topic. Boolean, string, null, missing, duplicate, or extra human labels fail closed. Catalog topics outside the reviewed universe stay in `outside_scope_topic_ids`; they are scope metadata and are never inferred negatives.

A `report` case uses the same topic-label shape. An unobserved report-derived topic has `observed_mask: 0` and `value: null`. An observed topic has `observed_mask: 1` and an integer `0` or `1`. This keeps source incompleteness distinct from a human negative.

`synthetic` remains a provenance vocabulary value for mechanism fixtures, but a synthetic case is not eligible for learning. It fails closed until a later approved policy explicitly defines and enables synthetic eligibility; T01A does not invent synthetic label semantics.

Topic and datapoint identifiers are accepted only against caller-supplied framework/catalog/mapping authority whose framework, catalog, and mapping versions and SHA-256 digests equal the case declaration. Unknown identifiers and identifiers marked ambiguous by that authority fail closed. No legacy fixed-topic mapping is embedded in this contract.

The P5/P6/P8/P9 source revision tuple, provenance, framework/catalog/mapping versions and digests, rights state, policy state, and closure evidence are mandatory. Eligibility requires explicit `policy_status: approved`, `state: granted`, `declaration_status: accepted`, `reviewed_universe: true`, and `final_for_period_scope: true`. These fields record an upstream decision; T01A does not approve the decision or provide a default.

Datapoint decisions contain only review feedback (`relevant`, `selected_to_answer`, reason codes, and note). They do not carry or modify P9 values, availability, obligation, or response status.

## Eligibility manifest rules

`learning-eligibility-v1` carries a monotonic generation, issue and expiry timestamps, case IDs/hashes and source revision tuples, the eligible ID list, revoked/deleted tombstones, rights and policy digests, and a canonical SHA-256 digest. Its validity interval is half-open: `[issued_at, valid_until)`, so `now == valid_until` is expired. The supported RFC3339 subset is exactly `^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$`: uppercase `T`/`Z`, clock hours `00..23`, minutes and seconds `00..59`, an optional fraction of exactly one through six digits, and either `Z` or an offset whose hours are `00..23` and minutes are `00..59`. `+00:00` and `-00:00` are syntactically accepted. Calendar validity is then checked by `DateTimeImmutable`, and comparisons use the represented instant without accepting precision beyond PHP microseconds. Manifest generation, every source generation/revision, rights `authorization_generation`, and the caller-supplied previous generation are integers from `0` through `9007199254740991` inclusive. The public previous-generation argument is intentionally received as `mixed` and rejected unless it is already an integer in that range; booleans, numeric strings, and floats are never coerced.

`cases` contains only current, non-tombstoned case entries. A `case_id` present in either `revoked` or `deleted` must be absent from `cases`, regardless of its hash or membership in `eligible_case_ids`; duplicate IDs across revoked/deleted also fail closed. The pure Laravel validator rejects malformed, non-monotonic, not-yet-valid, expired, tombstone-conflicting, or digest-mismatched manifests.

The canonical digest is SHA-256 over the manifest without `canonical_digest`, encoded as UTF-8 JSON with object keys sorted lexicographically at every depth and arrays kept in declared order. Encoding uses the PHP equivalents of `JSON_UNESCAPED_SLASHES`, `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_LINE_TERMINATORS`, and `JSON_PRESERVE_ZERO_FRACTION`: ordinary Unicode plus U+2028/U+2029 remain literal, while quotes, backslashes, and JSON control characters remain escaped. Malformed JSON, excessive nesting, a non-object root, duplicate object member names (including escape-equivalent names), or an encoding error fails closed.

## Pure Laravel validation

`App\Services\LearningCaseContract` exposes:

- `assertEligibleLearningCase(string $caseJson, string $authorityJson): void`
- `assertEligibilityManifest(string $manifestJson, mixed $previousGeneration, DateTimeImmutable $now): void`
- `eligibilityManifestDigest(string $manifestJson): string`

The caller provides raw JSON authority/case or manifest bytes, previous generation, and current time. The raw boundary decodes JSON with objects preserved as `stdClass` and lists preserved as arrays. It proves every required object/list container—including valid empty lists—before normalizing values for semantic checks, so `{}`, numeric-keyed objects, and lists are not interchangeable. Before semantic or digest checks, it also rejects duplicate object members and enforces the same recursively closed object shapes as the standalone/OpenAPI schemas; missing or unknown keys fail closed. This is a bounded structural and semantic validator, not a claim that a generic runtime JSON-Schema engine exists. It performs no IO and does not infer policy, closure, equivalence, retention, or eligibility evidence.

## Deferred decisions

T01A does not define numerical evaluation thresholds, retention periods, reason-code policy, legal/UX declaration copy, company-link evidence, promotion authority, or activation authority. Missing or unapproved policy and closure evidence remain ineligible. T03/T04 own active P8/P9 payload changes; T05/T06 must bind transport and helpers atomically with their controllers.
