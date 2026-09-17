# Index And Scope

Back to the [technical manual](index.md).

## What Can Be Installed Today

The repository allows an operator-owned installation based on Next.js, Laravel and FastAPI, provided the operator supplies the production infrastructure: TLS proxy, persistent PostgreSQL database, Redis, Laravel worker, secret management, mail, backups, observability and operating procedures.

The production installation documented here does not use `app/compose.public.yml` as the final artefact. That Compose file is for local evaluation: it publishes `web`, `frontend` and `ai-service`; configures SQLite in a temporary path; uses non-persistent cache, session and queue settings; disables OAuth and documents; and runs `migrate:fresh` whenever Laravel starts.

## Prerequisites

- Example domain used in this manual: `app.example.org`.
- Operator-maintained Linux host with Docker Compose v2 or Podman Compose.
- Administrative access to create a service user, persistent directories, firewall rules and process units.
- Laravel image or runtime adapted for PostgreSQL: the included public Dockerfile installs `pdo_sqlite`, but not `pdo_pgsql`.
- PostgreSQL and Redis on a private network, not published to the Internet.
- Real SMTP if email verification and password reset are enabled.
- External OAuth applications only if social login is required. OAuth is not mandatory.
- Backup and recovery policy approved before loading real data.

## Status Matrix

| Area | Status | Operational evidence | Condition |
|---|---|---|---|
| Next.js frontend | Included and verifiable | `app/frontend/Dockerfile.public`, rewrites in `next.config.mjs` | Publish only behind Caddy. |
| Laravel API and private UI | Included and verifiable | `web.php`, `api.php`, `auth.php` routes | Keep it on a private network. |
| FastAPI AI | Included and verifiable | `/healthz`, `/model-profiles`, `/predict` in `public_model_app.py` | Use `CHARACTERIZATION_GATEWAY=api`. |
| Public AI model | Included and verifiable | `new_format_732_v1_gpt41` profile | Candidate support, not a final decision. |
| Laravel health | Included and verifiable | `GET /healthz` | Checks database and a basic queue signal. |
| AR16 to ESRS/DR mapping | Included and verifiable | `data/ar16_to_esrs_dr_mapping_esrs2023_v1.json`, `esrs:validate-matter-dr-mapping` command | Enable with immutable, validated path. |
| ESRS information, exports and technical candidate | Included and verifiable with prerequisites | `report/*` endpoints, Arelle and vendored taxonomies | Complete state, valid assets and passed technical validation. |
| Production PostgreSQL | Provisioned by operator | Laravel supports `pgsql` configuration | Runtime with `pdo_pgsql`, backup and non-destructive migrations. |
| Persistent Redis | Provisioned by operator | Laravel supports Redis for cache, session and queue | Configure persistence and password/ACL according to platform. |
| Caddy/TLS | Provisioned by operator | No Caddyfile included | Expose only Caddy and frontend. |
| SMTP mail | Provisioned by operator | `config/mail.php` | External credentials, sender and deliverability. |
| Google/Microsoft OAuth | Provisioned by operator | Socialite routes | External apps, exact redirect and secrets. |
| ClamAV | Provisioned by operator | `UploadVirusScanner` | Operational binary before enabling scanning. |
| Document S3/MinIO | Requires development before enabling | `CharacterizationDocument::STORAGE_DISK = 'local'` | Does not work automatically for documents. |
| Document extraction | Requires development before enabling | Laravel calls `POST /extract-document`; FastAPI does not provide it | Keep `P6_DOCUMENT_UPLOAD_ENABLED=false`. |

## Reading Rule

When a section says `included`, there is code or an artefact in this repository that makes it verifiable. When it says `provisioned by operator`, the repository can integrate with that piece but does not supply it. When it says `requires development`, no variable, Compose file or external key makes that capability functional.
