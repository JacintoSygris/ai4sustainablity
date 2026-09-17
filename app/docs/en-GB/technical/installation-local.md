# Local Installation

Back to the [technical manual](index.md).

This page is kept as a bridge route. For production, follow [services and deployment](services-deployment.md). For local evaluation, remember that `app/compose.public.yml` exposes internal services, uses ephemeral SQLite and runs `migrate:fresh`.

Prerequisite: use fictitious data only.

```sh
# Directory: app
docker compose -f compose.public.yml up --build -d
```

Expected result: frontend, Laravel and FastAPI start for local evaluation.

Check:

```sh
# Directory: app
curl -fsS http://localhost:3000/ >/dev/null
curl -fsS http://localhost:8000/healthz
curl -fsS http://localhost:8001/healthz
curl -fsS http://localhost:8001/model-profiles
```

Stop:

```sh
# Directory: app
docker compose -f compose.public.yml down
```

When started again, the database is recreated.
