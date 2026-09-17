# Local Installation

Back to the [documentation index](../index.md).

**Warning before starting:** the local profile recreates the SQLite database when the web component starts. Use fictitious data only. Do not use it for real information or to retain work.

## Requirements

- Git.
- Docker Compose v2 or compatible `podman-compose`.
- Network access during the first build to download base images and dependencies.

## Start

From the `app/` directory:

```sh
docker compose -f compose.public.yml up --build -d
```

With Podman:

```sh
podman-compose -f compose.public.yml up --build -d
```

Usual services:

| Service | URL |
|---|---|
| Interface | `http://localhost:3000` |
| Laravel API | `http://localhost:8000` |
| Proposal service | `http://localhost:8001` |

## Checks

```sh
curl -fsS http://127.0.0.1:3000/ >/dev/null
curl -fsS http://127.0.0.1:8000/healthz
curl -fsS http://127.0.0.1:3000/api/auth/register-config
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

These checks show that services respond. They do not prove email delivery, production persistence, external queues, specific exports or environment security.

## Stop

```sh
docker compose -f compose.public.yml down
```

When started again, the database is recreated.
