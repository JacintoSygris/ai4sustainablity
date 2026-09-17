# Backup And Recovery

Back to the [technical manual](index.md).

## Backup Scope

Back up at least:

- PostgreSQL with `pg_dump` in custom format;
- local document volume `laravel_storage`;
- non-secret Compose/Caddy configuration;
- manifest of deployed image versions;
- list of required secrets, without values.

Do not store secrets in the document backup package or in the repository.

## PostgreSQL Backup

Prerequisite: healthy `postgres` service and enough free space.

```sh
# Directory: /srv/ia4sustainability
docker compose exec postgres pg_dump -U ia4_app -d ia4sustainability -Fc -f /tmp/ia4sustainability.dump
docker compose cp postgres:/tmp/ia4sustainability.dump ./backups/ia4sustainability.dump
```

Expected result: a `.dump` file exists in `./backups`.

Check:

```sh
# Directory: /srv/ia4sustainability
ls -lh ./backups/ia4sustainability.dump
```

With Podman, replace `docker compose` with `podman-compose`.

## Document Volume Backup

Prerequisite: a window with no document writes, or a platform-consistent snapshot.

```sh
# Directory: /srv/ia4sustainability
docker run --rm -v ia4sustainability_laravel_storage:/data:ro -v "$PWD/backups:/backup" busybox tar -czf /backup/laravel-storage.tgz -C /data .
```

Expected result: compressed archive with the volume contents.

Check:

```sh
# Directory: /srv/ia4sustainability
tar -tzf backups/laravel-storage.tgz | head
```

## RPO And RTO

The repository does not decide RPO or RTO. The operator must define:

- backup frequency;
- retention;
- encryption at rest;
- location outside the host;
- maximum tolerable restoration time;
- periodic restoration test.

## Step-By-Step Restore

Prerequisite: new or isolated environment, secrets available in the relevant manager and verified backups.

```sh
# Directory: /srv/ia4sustainability
docker compose up -d postgres redis
docker compose cp ./backups/ia4sustainability.dump postgres:/tmp/ia4sustainability.dump
docker compose exec postgres dropdb -U postgres --if-exists ia4sustainability
docker compose exec postgres createdb -U postgres --owner ia4_app ia4sustainability
docker compose exec postgres pg_restore -U ia4_app -d ia4sustainability --clean --if-exists /tmp/ia4sustainability.dump
docker run --rm -v ia4sustainability_laravel_storage:/data -v "$PWD/backups:/backup:ro" busybox sh -c "rm -rf /data/* && tar -xzf /backup/laravel-storage.tgz -C /data"
docker compose up -d ai-service web worker frontend caddy
docker compose exec web php artisan migrate --force
docker compose exec web php artisan optimize
```

Expected result: database and volume restored, services started and pending migrations applied.

Post-restore check:

```sh
# Directory: /srv/ia4sustainability
curl -fsS https://app.example.org/ >/dev/null
curl -fsS https://app.example.org/api/auth/register-config
docker compose exec web php artisan migrate:status
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/healthz', timeout=5).read().decode())"
```

Also log in with a test account, verify P9/P10 downloads if enough data exists and confirm there are no failed jobs.
