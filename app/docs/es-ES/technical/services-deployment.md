# Servicios y despliegue

Volver al [manual tecnico](index.md).

## Plantilla de Compose del operador

El siguiente bloque es una plantilla de referencia que el operador debe adaptar. No existe `compose.prod.yml` en el repositorio y este manual no afirma que exista.

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

Precondicion: repositorio disponible en el host de build y runtime Laravel adaptado para `pdo_pgsql`.

```sh
# Directorio: app
docker build -f frontend/Dockerfile.public -t ia4sustainability-frontend:operator-build ./frontend
docker build -f ai-service/Dockerfile.public -t ia4sustainability-ai:operator-build ./ai-service
docker build -f web/Dockerfile.public -t ia4sustainability-web:operator-build-with-pgsql ./web
```

Resultado esperado: tres imagenes locales construidas. La imagen web debe haber sido adaptada por el operador para PostgreSQL antes de usarse.

Comprobacion:

```sh
# Directorio: app
docker image ls | grep ia4sustainability
```

Con Podman:

```sh
# Directorio: app
podman build -f frontend/Dockerfile.public -t ia4sustainability-frontend:operator-build ./frontend
podman build -f ai-service/Dockerfile.public -t ia4sustainability-ai:operator-build ./ai-service
podman build -f web/Dockerfile.public -t ia4sustainability-web:operator-build-with-pgsql ./web
```

## Arranque ordenado

Precondicion: secretos cargados, redes privadas definidas y backup si se trata de una actualizacion.

```sh
# Directorio: /srv/ia4sustainability
docker compose up -d postgres redis ai-service
docker compose up -d web
docker compose exec web php artisan migrate --force
docker compose exec web php artisan optimize
docker compose up -d worker frontend caddy
```

Resultado esperado: base y Redis arrancan primero; Laravel migra de forma no destructiva; worker y frontend arrancan despues.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose ps
docker compose exec web php artisan migrate:status
docker compose exec web php artisan route:list --path=healthz
```

Con Podman, sustituir por `podman-compose up -d ...` y `podman-compose exec ...`.

## Healthchecks

Precondicion: servicios levantados.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan about
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/healthz', timeout=5).read().decode())"
```

Resultado esperado: Laravel responde sin error y FastAPI devuelve `status: ok`.

Comprobacion externa:

```sh
# Directorio: cualquier directorio administrativo del host
curl -fsS https://app.example.org/ >/dev/null
curl -fsS https://app.example.org/api/auth/register-config
```

## No usar entrypoint destructivo

El `public-entrypoint.sh` incluido ejecuta `migrate:fresh`. En produccion se debe sustituir por un arranque no destructivo que no toque la base salvo por el procedimiento explicito `php artisan migrate --force`.
