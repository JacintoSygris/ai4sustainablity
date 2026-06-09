# IA4Sustainability App

Single integrated product deliverable for the IA4Sustainability project.

## Layout

| Path | Purpose |
|---|---|
| `frontend/` | Original Airis Next/Vercel frontend copied from `docs/source/09-Web/airis-main.zip`. |
| `web/` | Laravel backend/API application copied from `docs/source/09-Web/ia4sustainablity-release-main.zip`; its Blade UI is a temporary private-dev fallback, not the target frontend. |
| `ai-service/` | Python/FastAPI AI service copied from `docs/source/09-Web/IASR-code-main (1).zip`. |
| `contracts/api/` | Product-level API contracts for frontend-to-Laravel and Laravel-to-Python integration. |
| `contracts/mappings/` | Product-level mapping inventories and decisions. |
| `docs/` | Private workspace run/deployment notes; omitted from the sanitized public source tree. |
| `scripts/` | Private workspace helper scripts; omitted from the sanitized public source tree. |

## Current Target

The target product split is:

- The original Airis/Vercel frontend is the user-facing app. Its buildable
  source is now present under `frontend/`.
- Laravel is the API/backend: authentication boundary, persistence,
  validation, characterization workflow state, job dispatch, and Python adapter.
- Python/FastAPI remains private infrastructure behind Laravel.

Current development has migrated the Airis/Next workflow through P10/Step 6
onto Laravel-owned auth, persistence, workflow APIs, and report-readiness
state. The app remains a development release until broader browser-level
a11y/i18n QA and residual dependency/audit cleanup are closed:

- P5 captures standardized SME characterization data through the JSON API and
  the temporary Blade fallback.
- P6 uses the Python service only to propose candidate ESRS/AR16 topics through
  Laravel.
- P8 remains the user confirmation point for final materiality.
- P9 fails closed for topical datapoints unless an approved AR16 matter -> ESRS
  Disclosure Requirement map is configured; deterministic AR16 -> ESRS -> DR ->
  datapoint mapping remains the authority for datapoints.
- P10 renders Laravel report readiness and draft data. Final report/XBRL
  generation remains outside this source release.
- The stable runtime AI profile is the new-format 732 GPT-4.1 classifier with
  no fixed candidate-topic cap. The reviewed materiality v6 retrain is a
  training artifact only, not the deployed/default runtime profile.

## Source Rule

The full private workspace keeps immutable source snapshots under
`docs/source/09-Web/`. This public repository contains the copy-extracted app
source only; raw ZIPs, training corpora, generated model artifacts, and private
operator evidence are intentionally omitted.

The real frontend environment file from handoff is local-only at
`frontend/.env.real` and is ignored by Git. Public docs and examples must use
`frontend/.env.example` without real values.
Better Auth, Turso/libSQL, and Basic Auth deployment values are private handoff
inputs, not repo artifacts. The received frontend has a development fallback
Better Auth secret for non-production source inspection only; production mode
fails closed when `BETTER_AUTH_SECRET` is absent, so a successful local build is
not proof that deployment secrets are configured correctly.

## Integration Gates

- Frontend-to-Laravel contract: `contracts/api/frontend-characterization-v0.md`.
- Frontend source workspace: `frontend/`.
- Laravel-to-Python contract: `contracts/api/characterization-prediction-v0.md`.
- AR16/Python mapping strategy: `contracts/mappings/ar16-to-python-esrs-v0.md`.
- Do not expose the Python service directly to the frontend.
- Do not make the Laravel Blade UI the final frontend; the original Airis
  frontend source is now present under `frontend/` and is pending integration.
- Do not treat the received Next `/api/wizard/*`, Better Auth, Turso/libSQL,
  local topic IDs, or local report state as authoritative IA4S backend
  behavior; they are provisional source behavior until the frontend is wired to
  Laravel.
- Current frontend contract baseline: Next can proxy `/api/*` to Laravel, local
  Next backend routes are guarded, Laravel exposes `/api/auth/session`, P5 Step
  1 stores Laravel characterization draft data, P6 Step 2 reviews Laravel
  materiality proposals, P7 Step 3 reads the Laravel double-materiality
  guide/templates, P8 Step 4 confirms final materiality through Laravel, P9
  Step 5 edits Laravel datapoint responses, and P10 Step 6 renders Laravel
  report readiness/draft data.
- Retired Better Auth/Turso/local Next backend behavior must not be treated as
  authoritative IA4S backend state.

## Public Deployment Baseline

- URL: `https://i4s.ueporreres.com/`
- Deployment notes are maintained in the private workspace and are not part of
  this sanitized public source tree.
- Current verified state in the private workspace: Laravel-auth-only
  Laravel+Next development release with Apache Basic Auth retired,
  `CHARACTERIZATION_GATEWAY=api`, `PRIVATE_DEV_AUTO_LOGIN=false`, and private
  FastAPI bound to `127.0.0.1:8001`.
- Public repo publication is a sanitized source subset. Local run/deployment
  notes, generated training material, model artifacts, logs, and private
  operator evidence are intentionally not part of the public source tree.
