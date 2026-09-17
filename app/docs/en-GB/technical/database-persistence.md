# Database And Persistence

Back to the [technical manual](index.md).

## PostgreSQL

The production architecture uses PostgreSQL. SQLite is only for local and automated evaluation; do not use it for an operator-owned installation with real data.

Prerequisite: private `postgres` service created by the operator.

```sh
# Directory: /srv/ia4sustainability
docker compose exec postgres createuser --no-superuser --createdb --no-createrole ia4_app
docker compose exec postgres createdb --owner ia4_app ia4sustainability
```

Expected result: a non-superuser application role and a database owned by that role exist.

Check:

```sh
# Directory: /srv/ia4sustainability
docker compose exec postgres psql -U postgres -c "\\du ia4_app"
docker compose exec postgres psql -U postgres -c "\\l ia4sustainability"
```

With Podman, replace `docker compose` with `podman-compose`.

## Non-Destructive Migrations

Prerequisite: recent backup completed and Laravel environment set to `DB_CONNECTION=pgsql`.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan migrate --force
```

Expected result: Laravel applies only pending migrations. Existing tables are not deleted.

Check:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan migrate:status
```

Do not run `php artisan migrate:fresh` in production. That command drops and recreates the database.

## PostgreSQL Runtime

The public Laravel Dockerfile installs `pdo_sqlite`, not `pdo_pgsql`. Before using PostgreSQL, the operator must publish or build an image/runtime that includes the PostgreSQL PHP extension and required libraries.

Check:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php -m | grep -E '^pdo_pgsql$'
```

Expected result: `pdo_pgsql` appears. If it does not, do not continue with production migrations.

## Persistent Redis

Redis is used for session, cache and queue. Configure it with persistence according to operator policy, for example AOF or RDB+AOF, and separate storage.

Prerequisite: private `redis` running with persistence.

```sh
# Directory: /srv/ia4sustainability
docker compose exec redis redis-cli PING
```

Expected result: `PONG`.

Check from Laravel:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan queue:failed
```

The command should connect without Redis or database errors.

## Local Document Volume

The included architecture keeps documents on persistent local storage mounted for Laravel. The document model forces `local`, so S3/MinIO requires additional development/configuration before it can be declared operational for documents.

Prerequisite: `laravel_storage` volume mounted into the web and worker runtimes.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web test -w storage/app/private
```

Expected result: empty output and successful exit code.

Check:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php -r "echo is_writable('storage/app/private') ? 'writable' : 'not writable';"
```

## Backup Before Migration

Before each migration:

1. Create PostgreSQL backup.
2. Create snapshot/copy of the document volume.
3. Save non-secret deployment configuration.
4. Confirm that a tested restore exists.

Without a restorable backup, a production migration must not be run.
