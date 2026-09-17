# Self-Hosting And Operations Technical Manual

Back to the [documentation index](../index.md).

This manual explains how to prepare, install, configure, start, verify, maintain and recover an operator-owned IA4Sustainability installation using the public artefacts in this repository. The primary source is Spanish from Spain; this English version is equivalent.

The production reference architecture is:

```text
Internet -> Caddy (80/443, TLS) -> public Next.js -> private Laravel
                                           |-> private PostgreSQL
                                           |-> private Redis
                                           |-> private Laravel worker
                                           |-> local document volume
                                           |-> private FastAPI AI
```

`app/compose.public.yml` is not production. It exposes internal services, uses ephemeral SQLite and the Laravel startup script runs `php artisan migrate:fresh --seed --force`, which destroys data.

## Route Map

1. [Index and scope](scope.md): what can be installed today, prerequisites and included/provisioned/blocked matrix.
2. [Architecture](architecture.md): networks, ports, public/private exposure and flows.
3. [Server preparation](server-preparation.md): system, DNS, firewall, service user, volumes and resources.
4. [Secrets and parameters](secrets-and-parameters.md): creation, storage, rotation and variables.
5. [Database and persistence](database-persistence.md): PostgreSQL, Redis, document volume and migrations.
6. [Services and deployment](services-deployment.md): build, startup, migrations, `optimize`, worker and health checks.
7. [Proxy and TLS](proxy-tls.md): reference Caddyfile, headers, body limit and validation.
8. [Mail and OAuth](mail-oauth.md): SMTP, Google Cloud, Microsoft Entra and common errors.
9. [Operations](operations.md): queues, logs, observability, updates and incident actions.
10. [Backup and recovery](backup-recovery.md): backups, restore, RPO/RTO and post-restore verification.
11. [AI, mapping and reporting](ai-mapping-reporting.md): FastAPI, ESRS mapping, P9/P10, Arelle and candidate iXBRL.
12. [Document extraction](document-extraction.md): functional block, minimum contract and acceptance criteria.
13. [Acceptance and diagnostics](acceptance-diagnostics.md): reproducible checklist and symptom-check-safe-fix table.
14. [References](references.md): official sources consulted on 2026-09-17.

## Bridge Pages

These routes are kept for existing links and point back to the new manual:

- [Local installation](installation-local.md)
- [Configuration](configuration.md)
- [Deployment boundaries](deployment-boundaries.md)
- [Persistence and migrations](operations/persistence-migrations.md)
- [Security](operations/security.md)
- [Monitoring and troubleshooting](operations/monitoring-troubleshooting.md)
