# Backup y recuperacion

Volver al [manual tecnico](index.md).

## Alcance del backup

Respaldar como minimo:

- PostgreSQL con `pg_dump` en formato custom;
- volumen documental local `laravel_storage`;
- configuracion no secreta de Compose/Caddy;
- manifiesto de version de imagenes desplegadas;
- lista de secretos requeridos, sin valores.

No guardar secretos en el paquete de backup documental ni en el repositorio.

## Backup PostgreSQL

Precondicion: servicio `postgres` sano y espacio libre suficiente.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec postgres pg_dump -U ia4_app -d ia4sustainability -Fc -f /tmp/ia4sustainability.dump
docker compose cp postgres:/tmp/ia4sustainability.dump ./backups/ia4sustainability.dump
```

Resultado esperado: existe un fichero `.dump` en `./backups`.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
ls -lh ./backups/ia4sustainability.dump
```

Con Podman, sustituir `docker compose` por `podman-compose`.

## Backup del volumen documental

Precondicion: ventana en la que no se escriben documentos o snapshot consistente de la plataforma.

```sh
# Directorio: /srv/ia4sustainability
docker run --rm -v ia4sustainability_laravel_storage:/data:ro -v "$PWD/backups:/backup" busybox tar -czf /backup/laravel-storage.tgz -C /data .
```

Resultado esperado: archivo comprimido con el contenido del volumen.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
tar -tzf backups/laravel-storage.tgz | head
```

## RPO y RTO

El repositorio no decide RPO ni RTO. El operador debe definir:

- frecuencia de backup;
- retencion;
- cifrado en reposo;
- ubicacion fuera del host;
- tiempo maximo tolerable de restauracion;
- prueba periodica de restauracion.

## Restauracion paso a paso

Precondicion: entorno nuevo o aislado, secretos disponibles en el gestor correspondiente y backups verificados.

```sh
# Directorio: /srv/ia4sustainability
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

Resultado esperado: base y volumen restaurados, servicios arrancados y migraciones pendientes aplicadas.

Comprobacion post-restore:

```sh
# Directorio: /srv/ia4sustainability
curl -fsS https://app.example.org/ >/dev/null
curl -fsS https://app.example.org/api/auth/register-config
docker compose exec web php artisan migrate:status
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/healthz', timeout=5).read().decode())"
```

Ademas, iniciar sesion con una cuenta de prueba, verificar descargas P9/P10 si hay datos suficientes y confirmar que no hay jobs fallidos.
