# IA4Sustainability: Technical Package

This directory contains the runnable application: Next.js interface, Laravel API, FastAPI proposal service, contracts and data assets.

Before starting the local profile, remember that the included SQLite database is temporary and is recreated when the web component starts. Use fictitious data only.

## Main Guides

- [Local installation](docs/en-GB/technical/installation-local.md)
- [Architecture](docs/en-GB/technical/architecture.md)
- [Configuration](docs/en-GB/technical/configuration.md)
- [Persistence and migrations](docs/en-GB/technical/operations/persistence-migrations.md)
- [Monitoring and troubleshooting](docs/en-GB/technical/operations/monitoring-troubleshooting.md)
- [Integration contracts](docs/en-GB/integrations/authentication.md)

## Local Profile Services

| Service | Usual URL |
|---|---|
| Interface | `http://localhost:3000` |
| Laravel API | `http://localhost:8000` |
| Proposal service | `http://localhost:8001` |

These URLs are for local evaluation. Port publication, HTTPS, proxy, secrets, persistence, mail, queues and data policies are the responsibility of the operator of an own environment.
