# Operations

Back to the [technical manual](index.md).

## Queues And Worker

Laravel uses a separate worker for jobs. Do not use `QUEUE_CONNECTION=sync` in production.

Prerequisite: `QUEUE_CONNECTION=redis` and Redis available.

```sh
# Directory: /srv/ia4sustainability
docker compose up -d worker
docker compose logs --since=10m worker
```

Expected result: the worker runs with `queue:work redis` and no connection errors.

Check:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan queue:failed
```

The failed jobs table should be queryable without error. If jobs have failed, review the cause before retrying them.

## Logs

Send logs from Caddy, frontend, Laravel, worker, FastAPI, PostgreSQL and Redis to the operator's observability platform. Logs must not include documents, tokens, passwords or full OAuth provider responses.

Prerequisite: services running.

```sh
# Directory: /srv/ia4sustainability
docker compose logs --since=30m web worker ai-service frontend caddy
```

Expected result: recent events are visible without sensitive traces.

## Basic Observability And Alerts

Alert at least on:

- `https://app.example.org/` not responding;
- `https://app.example.org/api/auth/register-config` failing;
- Laravel `/healthz` degraded on the private network;
- FastAPI `/healthz` failing;
- worker stopped or queue accumulating;
- repeated SMTP/OAuth errors;
- PostgreSQL, Redis or document volume storage above the operator threshold;
- TLS renewal expiry or failure.

## Updates

Prerequisite: complete backup and rollback plan.

```sh
# Directory: /srv/ia4sustainability
docker compose pull
docker compose up -d postgres redis ai-service
docker compose up -d web
docker compose exec web php artisan migrate --force
docker compose exec web php artisan optimize
docker compose up -d worker frontend caddy
```

Expected result: services updated and migrations applied non-destructively.

Check: run the post-deployment block in [acceptance and diagnostics](acceptance-diagnostics.md).

## Secret Rotation

Rotate `APP_KEY` only with a specific Laravel procedure and maintenance window because it affects encrypted data and sessions. Rotate PostgreSQL, Redis, SMTP and OAuth passwords individually, verifying each dependency before revoking the previous one.

## Incident Actions

1. Identify the affected service.
2. Review the last deployment, rotated secrets and resource saturation.
3. Read service logs without exposing data.
4. If data is affected, freeze changes and prepare restoration.
5. Document cause and corrective action outside the public repository.
