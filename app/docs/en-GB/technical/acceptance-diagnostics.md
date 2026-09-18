# Acceptance And Diagnostics

Back to the [technical manual](index.md).

## Clean Installation Checklist

1. Preparation: DNS, firewall, service user, directories and secrets created according to [server preparation](server-preparation.md).
2. Build: frontend, web and ai-service images built; web image has `pdo_pgsql`.
3. Database: private PostgreSQL created, limited role and non-destructive migrations applied.
4. Redis: session, cache and queue configured with Redis.
5. Startup: `postgres`, `redis`, `ai-service`, `web`, `worker`, `frontend` and `caddy` running.
6. External health: `https://app.example.org/` and `/api/auth/register-config` respond.
7. Private health: Laravel `/healthz`, FastAPI `/healthz` and `/model-profiles` respond.
8. Registration and session: a test account can register, log in and log out.
9. Password reset: SMTP delivers a link with `https://app.example.org`.
10. Optional OAuth: Google/Microsoft work only if `SOCIAL_LOGIN_ENABLED=true`.
11. Prediction: a test characterisation obtains a technical proposal from FastAPI.
12. ESRS information and technical candidate: with sufficient data, expected downloads appear; Arelle technically validates the candidate if applicable.
13. Backup: PostgreSQL and document volume backups are generated.
14. Restore: restoration tested in an isolated environment.
15. Documents: `P6_DOCUMENT_UPLOAD_ENABLED=false` confirmed until `/extract-document` exists.

## Acceptance Commands

Prerequisite: deployment running.

```sh
# Directory: /srv/ia4sustainability
curl -fsS https://app.example.org/ >/dev/null
curl -fsS https://app.example.org/api/auth/register-config
docker compose exec web php artisan migrate:status
docker compose exec web php artisan queue:failed
docker compose exec web php artisan report:validate-assets

docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/model-profiles', timeout=5).read().decode())"
```

Expected result: every command completes successfully. The XHTML/iXBRL candidate additionally requires the external ZIP, manifest and configured Arelle described in [External EFRAG taxonomy](external-efrag-taxonomy.md); without them it must remain blocked. If a command fails, do not accept the installation until it is resolved or formally justified as not applicable.

## Diagnostics

| Symptom | Check | Safe fix |
|---|---|---|
| `https://app.example.org` does not load | Caddy logs and `frontend` status | Restore frontend or Caddy; do not expose Laravel directly. |
| `/api/auth/register-config` fails | Next rewrites and `web:8000` from frontend | Correct `LARAVEL_API_ORIGIN` and private network. |
| Laravel `/healthz` degraded | PostgreSQL connection | Review `DB_*`, `pdo_pgsql`, permissions and database state. |
| Login does not persist | Cookies and Redis | Review `SESSION_DRIVER=redis`, `SESSION_SECURE_COOKIE=true`, domain and TLS. |
| Jobs do not progress | Worker and Redis status | Restart worker after reading logs; do not switch to `sync` in production. |
| Mail does not arrive | SMTP provider and `MAIL_*` | Correct credentials/sender; do not log passwords. |
| OAuth returns redirect error | Registered URI | Match callback and `APP_URL` exactly. |
| `/predict` fails | FastAPI `/healthz` and `/model-profiles` | Review profile, artefacts and resources. |
| ESRS information outputs do not download | Journey state and assets | Complete data, validate mapping/assets/taxonomy. |
| Candidate iXBRL fails | Arelle and taxonomies | Run validators; treat as technical failure, do not force a valid download. |
| Document remains `failed` | `P6_DOCUMENT_UPLOAD_ENABLED` | Keep disabled; `/extract-document` is missing. |
| Backup does not restore | Isolated test | Fix procedure before loading real data. |
| Redis loses sessions | Redis persistence | Enable and verify AOF/RDB according to policy. |
| Internal ports appear public | `ss` and firewall rules | Close ports and remove `ports` from private services. |

## Safe Reset

In production, reset means restoring from backup or building a new environment and restoring data. Do not use `migrate:fresh`. If a test environment must be emptied, document that it contains no real data and do it outside the production installation.
