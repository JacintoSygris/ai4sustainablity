# Services And Deployment

Back to the [technical manual](index.md).

## Jenkins / production environment

The operator's Jenkins builds the public images. For `airis.sygris.com`, inject these variables into **web and worker** through private Jenkins configuration. Values inside `<...>` are placeholders to replace; never store secrets in Git. Generate `APP_KEY` once using `php artisan key:generate --show` in a private administrative environment and retain the same key across deployments.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=<persistent Laravel key>
APP_URL=https://airis.sygris.com
TRUSTED_HOSTS=airis.sygris.com
INTERNAL_TRUSTED_HOSTS=web,localhost,127.0.0.1
ENFORCE_TRUSTED_HOSTS=true
TRUSTED_PROXIES=<trusted reverse proxy IP or CIDR>
LOG_CHANNEL=stderr
LOG_LEVEL=info
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/storage/database/database.sqlite
I4S_RESET_DATABASE_ON_START=false
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=360
AUTH_PUBLIC_REGISTRATION_ENABLED=true
AUTH_REQUIRE_EMAIL_VERIFICATION=true
TURNSTILE_SITE_KEY=<real site key>
TURNSTILE_SECRET=<real Turnstile secret>
TURNSTILE_EXPECTED_HOSTNAME=airis.sygris.com
TURNSTILE_EXPECTED_ACTION=register
MAIL_MAILER=smtp
MAIL_HOST=<provider SMTP host>
MAIL_PORT=587
MAIL_USERNAME=<SMTP username>
MAIL_PASSWORD=<SMTP password>
MAIL_SCHEME=smtp
MAIL_REQUIRE_TLS=true
MAIL_FROM_ADDRESS=<provider authorised sender>
MAIL_FROM_NAME=IA4Sustainability
```

This block opens registration only when `RegistrationGuard` accepts all prerequisites. The Cloudflare widget must list `airis.sygris.com` as an allowed host and use real keys, never the public test keys. For implicit TLS, use `MAIL_SCHEME=smtps` and the provider's port, usually `465`. The image has no local MTA: do not use `sendmail`. For a deployment without public registration, set `AUTH_PUBLIC_REGISTRATION_ENABLED=false` and bootstrap accounts with the command below. `AUTH_REQUIRE_EMAIL_VERIFICATION` remains enabled; `AUTH_PASSWORD_RESET_ENABLED=true` is optional and requires the same safe mail and queue delivery.

For **frontend**, pass these values as build arguments to `app/frontend/Dockerfile.public` and as container environment variables. `NEXT_PUBLIC_*` values are embedded at build time; changing only the runtime environment is insufficient.

```dotenv
LARAVEL_API_ORIGIN=
LARAVEL_INTERNAL_API_ORIGIN=http://web:8000
LARAVEL_CANONICAL_ORIGIN=https://airis.sygris.com
NEXT_PUBLIC_APP_URL=https://airis.sygris.com
NEXT_PUBLIC_LARAVEL_API_BASE_URL=/api
```

Leave `LARAVEL_API_ORIGIN` empty: this legacy variable takes precedence over the internal origin when set.

Effective trusted hosts combine `TRUSTED_HOSTS`, the host of `APP_URL` and `INTERNAL_TRUSTED_HOSTS`, normalised to lowercase without empty values, duplicates or `example.com`/`app.example.org`. Next calls to `web:8000` are trusted by default. `APP_URL` must be the public HTTPS origin without a path or credentials. An empty, localhost or placeholder host produces a startup error and one `Log::error` per production process, including across requests to `artisan serve`. Deduplication uses a locked temporary marker identified by Linux boot ID, PID and process start time; keep `/proc` readable and the system temporary directory writable. If either is unavailable, the warning explicitly reports that deduplication is unavailable and requests continue.

Mount a **persistent volume at `/var/www/html/storage`**, shared by web and worker. `DB_DATABASE` must reference the file inside that volume; the entrypoint defaults to the path above. Do not delete or recreate the volume during deployment. It retains SQLite, sessions, the queue and files; include it in backups. If an existing database lives outside the volume, back it up and move it while web and worker are stopped before the next deployment. Startup runs `migrate --force` and upserts only the NACE and ESRS reference catalogues, preserving identifiers and accounts.

Run a continuous worker with the same image, environment, network and volume, **overriding its entrypoint** with `php` and passing `artisan queue:work database --tries=3 --timeout=300 --sleep=3`. In Compose, use `entrypoint: ["php", "artisan"]` and `command: ["queue:work", "database", "--tries=3", "--timeout=300", "--sleep=3"]`. Changing only `command` does not stop the public entrypoint from starting the web server. Start the worker after migrations and restart it on each deployment.

Commands inside web:

```sh
php artisan i4s:deploy:check
php artisan i4s:user:create operator@example.net --name="Operator" --verified
```

The password is requested through a hidden prompt and follows the same password rules as registration. Duplicate emails are refused and the new user id is printed. `--verified` marks the account verified; without it verification remains pending and this command sends no email. For automation, add `--password-stdin` and supply one line over a private pipe (using `docker compose exec -T web ...`); never put passwords in arguments, command-line environment assignments or Jenkins logs.

`i4s:deploy:check` prints `PASS`/`FAIL` for each requirement without secret values: origin, active host enforcement (`ENFORCE_TRUSTED_HOSTS`) and trusted hosts (including the `APP_URL` host and `web`), valid key, writable database, current migrations, registration with failing prerequisite names, safe mail, SMTP configuration and a durable queue. Origin/host, key, database or migration failures return a non-zero status. Registration, mail and queue failures are non-blocking diagnostics so closed deployments remain possible. The entrypoint runs the check after migrations and seeding, logs its result and continues even on failure. A `PASS` checks local configuration; it does not validate Cloudflare credentials, mail delivery, worker execution or the volume mount. The operator must verify those and externally check login and registration after deployment.

## Operator Compose Template

The following block is an operator reference template. It must be adapted. No `compose.prod.yml` exists in the repository and this manual does not claim that it does.

```yaml
services:
  caddy:
    image: caddy:2
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./config/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - frontend
    networks: [public, private]

  frontend:
    image: ia4sustainability-frontend:operator-build
    environment:
      NODE_ENV: production
      PORT: "3000"
      LARAVEL_INTERNAL_API_ORIGIN: http://web:8000
      LARAVEL_CANONICAL_ORIGIN: https://app.example.org
      NEXT_PUBLIC_LARAVEL_API_BASE_URL: /api
      NEXT_PUBLIC_APP_URL: https://app.example.org
      NEXT_TELEMETRY_DISABLED: "1"
    expose: ["3000"]
    depends_on: [web]
    networks: [private]

  web:
    image: ia4sustainability-web:operator-build-with-pgsql
    env_file: ./config/web.env
    expose: ["8000"]
    volumes:
      - laravel_storage:/var/www/html/storage
    depends_on: [postgres, redis, ai-service]
    networks: [private]

  worker:
    image: ia4sustainability-web:operator-build-with-pgsql
    env_file: ./config/web.env
    entrypoint: ["php", "artisan"]
    command: ["queue:work", "redis", "--tries=3", "--timeout=300", "--sleep=3"]
    volumes:
      - laravel_storage:/var/www/html/storage
    depends_on: [postgres, redis, ai-service]
    networks: [private]

  ai-service:
    image: ia4sustainability-ai:operator-build
    expose: ["8001"]
    networks: [private]

  postgres:
    image: postgres:18
    environment:
      POSTGRES_DB: ia4sustainability
      POSTGRES_USER: ia4_app
      POSTGRES_PASSWORD_FILE: /run/secrets/postgres_password
    volumes:
      - postgres_data:/var/lib/postgresql/data
    secrets: [postgres_password]
    networks: [private]

  redis:
    image: redis:latest
    command: ["redis-server", "--appendonly", "yes"]
    volumes:
      - redis_data:/data
    networks: [private]

networks:
  public:
  private:
    internal: true

volumes:
  caddy_data:
  caddy_config:
  postgres_data:
  redis_data:
  laravel_storage:

secrets:
  postgres_password:
    file: ./config/secrets/postgres_password
```

## Build

Prerequisite: repository available on the build host and Laravel runtime adapted for `pdo_pgsql`.

```sh
# Directory: app
docker build -f frontend/Dockerfile.public -t ia4sustainability-frontend:operator-build ./frontend
docker build -f ai-service/Dockerfile.public -t ia4sustainability-ai:operator-build ./ai-service
docker build -f web/Dockerfile.public -t ia4sustainability-web:operator-build-with-pgsql ./web
```

Expected result: three local images are built. The web image must have been adapted by the operator for PostgreSQL before use.

Check:

```sh
# Directory: app
docker image ls | grep ia4sustainability
```

With Podman:

```sh
# Directory: app
podman build -f frontend/Dockerfile.public -t ia4sustainability-frontend:operator-build ./frontend
podman build -f ai-service/Dockerfile.public -t ia4sustainability-ai:operator-build ./ai-service
podman build -f web/Dockerfile.public -t ia4sustainability-web:operator-build-with-pgsql ./web
```

## Ordered Startup

Prerequisite: secrets loaded, private networks defined and backup completed if this is an update.

```sh
# Directory: /srv/ia4sustainability
docker compose up -d postgres redis ai-service
docker compose up -d web
docker compose exec web php artisan migrate --force
docker compose exec web php artisan optimize
docker compose up -d worker frontend caddy
```

Expected result: database and Redis start first; Laravel migrates non-destructively; worker and frontend start afterwards.

Check:

```sh
# Directory: /srv/ia4sustainability
docker compose ps
docker compose exec web php artisan migrate:status
docker compose exec web php artisan route:list --path=healthz
```

With Podman, use `podman-compose up -d ...` and `podman-compose exec ...`.

## Health Checks

Prerequisite: services running.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan about
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/healthz', timeout=5).read().decode())"
```

Expected result: Laravel responds without error and FastAPI returns `status: ok`.

External check:

```sh
# Directory: any administrative directory on the host
curl -fsS https://app.example.org/ >/dev/null
curl -fsS https://app.example.org/api/auth/register-config
```

## No Destructive Entrypoint

The included `public-entrypoint.sh` preserves data by default. Only the explicit value `I4S_RESET_DATABASE_ON_START=true` runs `migrate:fresh`, with a prominent warning: it deletes all accounts and data. Keep it absent or `false` in production; it is not an ordinary deployment option.
