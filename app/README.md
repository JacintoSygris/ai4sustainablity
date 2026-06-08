# IA4Sustainability App

This folder contains the integrated IA4Sustainability product source.

## Layout

| Path | Purpose |
|---|---|
| `frontend/` | Next.js user-facing frontend. |
| `web/` | Laravel backend/API, authentication boundary, persistence, workflow APIs, and Python adapter. |
| `ai-service/` | Python/FastAPI service for local model-backed prediction workflows. |
| `contracts/mappings/` | Public ESRS/AR16 mapping inventories used by the integration. |

## Integration Shape

- The frontend talks to Laravel through Laravel-owned API routes.
- Laravel owns authentication, persistence, workflow state, validation, and
  report/readiness APIs.
- The Python service is optional for public source setup. Keep Laravel on the
  mock characterization gateway unless you provide model artifacts locally.
- Final materiality remains user-confirmed in Laravel; AI output is candidate
  support only.
- ESRS datapoint selection is deterministic from the checked-in mapping data.

See the root `README.md` for install and verification commands.
