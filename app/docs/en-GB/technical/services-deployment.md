# Services And Deployment

Back to the [technical manual](index.md).

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
      LARAVEL_API_ORIGIN: http://web:8000
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
    command: ["php", "artisan", "queue:work", "redis", "--tries=3", "--timeout=300", "--sleep=3"]
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

The included `public-entrypoint.sh` runs `migrate:fresh`. In production it must be replaced with non-destructive startup that does not touch the database except through the explicit `php artisan migrate --force` procedure.
