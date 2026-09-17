# Health Endpoint Monitoring

The Laravel app exposes `GET /healthz` as an unauthenticated liveness/readiness
probe.

## Response

```json
{
  "status": "ok",
  "db": "ok",
  "queue_recent": "ok",
  "time": "2026-07-06T13:00:00+00:00"
}
```

- HTTP `200` means the database dependency is reachable.
- HTTP `503` means the database dependency failed and `status` is `degraded`.
- `queue_recent` is best effort. `unknown` should be treated as informational
  unless your deployment adds a stricter queue monitor.

The endpoint does not expose secrets, framework versions, release identifiers,
stack traces, or filesystem paths.

## Local Checks

From `app/`, start the public stack:

```sh
docker compose -f compose.public.yml up --build
```

Then check:

```sh
curl -fsS http://localhost:8000/healthz
```

For external deployments, configure your reverse proxy or monitor according to
your own access-control policy. Keep credentials out of monitoring URLs and
public documentation.
