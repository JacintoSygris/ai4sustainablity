# Architecture

Back to the [technical manual](index.md).

## Reference Topology

```text
                         public network
Internet
   |
   v
Caddy :80/:443  -- TLS, headers, body <= 50 MB
   |
   v
frontend:3000  public Next.js
   |
   | rewrites /api, /characterization, /laravel/*, /profile, /logout, /build/*
   v
web:8000       private Laravel
   | \
   |  \-- ai-service:8001 private FastAPI (/healthz, /model-profiles, /predict)
   |
   |-- postgres:5432 private PostgreSQL
   |-- redis:6379 private Redis
   |-- private Laravel worker (queue:work redis)
   |-- laravel_storage persistent local volume
   \-- optional ClamAV, private or operational inside the web runtime
```

Only Caddy and the frontend should be reachable from the Internet. Laravel, FastAPI, PostgreSQL, Redis, worker and ClamAV remain on private Compose/Podman networks and have no ports published on the public host.

## Networks And Ports

| Component | Internal port | Exposure | Function |
|---|---:|---|---|
| Caddy | `80`, `443` | Public | TLS, headers, body limit and reverse proxy. |
| Next.js | `3000` | Public only through Caddy | Interface and rewrites to Laravel. |
| Laravel | `8000` | Private | API, authentication, health check, reporting and application jobs. |
| FastAPI AI | `8001` | Private | Technical prediction with `/predict`. |
| PostgreSQL | `5432` | Private | Persistent data. |
| Redis | `6379` | Private | Session, cache and queue. |
| Laravel worker | no port | Private | Job processing. |
| ClamAV | mode-dependent | Private | Optional document scanning. |

## Flows

### Navigation And API

1. The browser opens `https://app.example.org`.
2. Caddy terminates TLS and forwards to the frontend.
3. Next.js serves pages and assets.
4. Routes configured in `next.config.mjs` rewrite calls to Laravel over the private network.
5. Laravel uses Redis for session, cache and queue, and PostgreSQL for persistent data.

Expected result: the browser never calls `web:8000`, `ai-service:8001`, `postgres:5432` or `redis:6379` directly.

Check: from the public host, the firewall should only allow inbound `80` and `443`; from the Compose network, `frontend` should resolve `web` and `web` should resolve `ai-service`, `postgres` and `redis`.

### AI Prediction

1. Laravel receives the characterisation.
2. With `CHARACTERIZATION_GATEWAY=api`, Laravel calls `CHARACTERIZATION_API_BASE_URL`, for example `http://ai-service:8001`.
3. FastAPI validates the `new_format_732_v1_gpt41` profile and responds to `/predict`.
4. The output is candidate support for review, not an automated decision or regulatory guarantee.

### Files And Queues

Documents, if enabled in future, are stored on persistent local disk because `CharacterizationDocument` forces the `local` disk. The controller accepts PDF/DOCX up to 50 MB, validates extension and magic bytes, and can call ClamAV if `P6_DOCUMENT_SCAN_ENABLED=true`.

Extraction is not available with the included code: the job calls `POST /extract-document`, but the public FastAPI exposes only `/healthz`, `/model-profiles` and `/predict`. Keep `P6_DOCUMENT_UPLOAD_ENABLED=false`.

## Public Compose

`app/compose.public.yml` is local evaluation only. It publishes `8000`, `3000` and `8001`, uses temporary SQLite in `/tmp/ia4sustainability`, configures `CACHE_STORE=array`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`, and the entrypoint runs `migrate:fresh`. It must not be used in production or as a base without removing those destructive properties.
