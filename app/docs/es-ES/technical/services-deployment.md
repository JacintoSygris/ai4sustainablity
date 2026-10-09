# Servicios y despliegue

Volver al [manual tecnico](index.md).

## Jenkins / entorno de producción

El Jenkins del operador construye las imágenes públicas. Para `airis.sygris.com`, inyectar estas variables en **web y worker** mediante la configuración privada de Jenkins. Los valores entre `<...>` son marcadores que deben sustituirse; no guardar secretos en Git. Generar `APP_KEY` una sola vez con `php artisan key:generate --show` en un entorno administrativo privado y conservar la misma clave entre despliegues.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=<clave persistente de Laravel>
APP_URL=https://airis.sygris.com
TRUSTED_HOSTS=airis.sygris.com
INTERNAL_TRUSTED_HOSTS=web,localhost,127.0.0.1
ENFORCE_TRUSTED_HOSTS=true
TRUSTED_PROXIES=<IP o CIDR del proxy inverso de confianza>
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
TURNSTILE_SITE_KEY=<clave real del sitio>
TURNSTILE_SECRET=<secreto real de Turnstile>
TURNSTILE_EXPECTED_HOSTNAME=airis.sygris.com
TURNSTILE_EXPECTED_ACTION=register
MAIL_MAILER=smtp
MAIL_HOST=<servidor SMTP del proveedor>
MAIL_PORT=587
MAIL_USERNAME=<usuario SMTP>
MAIL_PASSWORD=<contraseña SMTP>
MAIL_SCHEME=smtp
MAIL_REQUIRE_TLS=true
MAIL_FROM_ADDRESS=<remitente autorizado por el proveedor>
MAIL_FROM_NAME=IA4Sustainability
```

Este bloque abre el registro únicamente si `RegistrationGuard` acepta todos sus requisitos. El widget de Cloudflare debe incluir `airis.sygris.com` entre sus hosts permitidos y utilizar claves reales, nunca las claves públicas de prueba. Para TLS implícito usar `MAIL_SCHEME=smtps` y el puerto del proveedor, normalmente `465`. La imagen no tiene un MTA local: no usar `sendmail`. Para un despliegue sin registro público, establecer `AUTH_PUBLIC_REGISTRATION_ENABLED=false` y crear las cuentas con el comando indicado abajo. `AUTH_REQUIRE_EMAIL_VERIFICATION` sigue activo; `AUTH_PASSWORD_RESET_ENABLED=true` es opcional y requiere la misma entrega segura de correo y cola.

En **frontend**, pasar estos valores como argumentos de construcción de `app/frontend/Dockerfile.public` y como variables del contenedor. Los valores `NEXT_PUBLIC_*` quedan incorporados al construir la imagen; cambiarlos solo en ejecución no basta.

```dotenv
LARAVEL_API_ORIGIN=
LARAVEL_INTERNAL_API_ORIGIN=http://web:8000
LARAVEL_CANONICAL_ORIGIN=https://airis.sygris.com
NEXT_PUBLIC_APP_URL=https://airis.sygris.com
NEXT_PUBLIC_LARAVEL_API_BASE_URL=/api
```

Dejar `LARAVEL_API_ORIGIN` vacío: es una variable heredada que, si está definida, tiene prioridad sobre el origen interno.

La lista efectiva de hosts es la unión de `TRUSTED_HOSTS`, el host de `APP_URL` e `INTERNAL_TRUSTED_HOSTS`; se normaliza a minúsculas, sin vacíos, duplicados ni `example.com`/`app.example.org`. Las llamadas de Next a `web:8000` quedan autorizadas por defecto. `APP_URL` debe ser el origen HTTPS público, sin ruta ni credenciales. Un host vacío, `localhost` o de ejemplo genera un error al arrancar y un `Log::error` por proceso en producción, también entre peticiones a `artisan serve`. La deduplicación usa un marcador temporal con bloqueo, identificado por el arranque de Linux, el PID y la hora de inicio del proceso; mantener `/proc` legible y el directorio temporal del sistema escribible. Si alguno no está disponible, el aviso indica expresamente que no se puede deduplicar y las peticiones continúan.

Montar un **volumen persistente en `/var/www/html/storage`**, compartido por web y worker. `DB_DATABASE` debe apuntar al archivo dentro de ese volumen; su valor por defecto en el entrypoint es el del bloque anterior. No eliminar ni recrear el volumen al desplegar. El volumen conserva SQLite, sesiones, cola y archivos; las copias de seguridad deben incluirlo. Si ya existe una base fuera del volumen, hacer copia y trasladarla con web y worker detenidos antes del siguiente despliegue. El arranque ejecuta `migrate --force` y actualiza únicamente los catálogos NACE y NEIS mediante upserts, conservando identificadores y cuentas.

Arrancar un worker continuo con la misma imagen, entorno, red y volumen, **sustituyendo su entrypoint** por `php` y usando `artisan queue:work database --tries=3 --timeout=300 --sleep=3` como argumentos. En Compose: `entrypoint: ["php", "artisan"]` y `command: ["queue:work", "database", "--tries=3", "--timeout=300", "--sleep=3"]`. Cambiar solo `command` no evita que el entrypoint público arranque el servidor web. Arrancar el worker después de las migraciones y reiniciarlo en cada despliegue.

Comandos dentro de web:

```sh
php artisan i4s:deploy:check
php artisan i4s:user:create operator@example.net --name="Operador" --verified
```

La contraseña se solicita de forma oculta y cumple las mismas reglas de contraseña que el registro. Se rechazan correos duplicados y se imprime el identificador creado. `--verified` marca la cuenta como verificada; sin esa opción queda pendiente y este comando no envía un correo. Para automatizar, añadir `--password-stdin` y proporcionar una sola línea por una tubería privada (con `docker compose exec -T web ...`); nunca poner la contraseña en argumentos, variables de la línea de comandos ni registros de Jenkins.

`i4s:deploy:check` muestra `PASS`/`FAIL` por requisito, sin valores secretos: origen, protección de hosts activa (`ENFORCE_TRUSTED_HOSTS`) y hosts (incluidos el host de `APP_URL` y `web`), clave válida, base escribible, migraciones aplicadas, registro y nombres de sus requisitos fallidos, correo seguro, configuración SMTP y cola duradera. Devuelve código distinto de cero por fallos de origen/hosts, clave, base o migraciones. Registro, correo y cola se informan como fallos no bloqueantes, permitiendo instalaciones cerradas. El entrypoint ejecuta el diagnóstico después de migrar y sembrar, registra su resultado y continúa incluso si falla. Un `PASS` verifica configuración local, no acredita claves de Cloudflare, entrega de correo, ejecución del worker ni persistencia del volumen: el operador debe comprobarlos y verificar externamente inicio de sesión y registro tras desplegar.

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

El `public-entrypoint.sh` incluido conserva los datos por defecto. Solo el valor explícito `I4S_RESET_DATABASE_ON_START=true` ejecuta `migrate:fresh`, con un aviso destacado: elimina todas las cuentas y datos. Mantenerlo ausente o en `false` en producción; no es una opción de despliegue ordinario.
