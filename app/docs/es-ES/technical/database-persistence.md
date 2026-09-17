# Base de datos y persistencia

Volver al [manual tecnico](index.md).

## PostgreSQL

La arquitectura de produccion usa PostgreSQL. SQLite solo sirve para evaluacion local y automatizada; no usarlo para una instalacion propia con datos reales.

Precondicion: servicio `postgres` privado creado por el operador.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec postgres createuser --no-superuser --createdb --no-createrole ia4_app
docker compose exec postgres createdb --owner ia4_app ia4sustainability
```

Resultado esperado: existe un rol de aplicacion sin superusuario y una base propiedad de ese rol.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec postgres psql -U postgres -c "\\du ia4_app"
docker compose exec postgres psql -U postgres -c "\\l ia4sustainability"
```

Con Podman, sustituir `docker compose` por `podman-compose`.

## Migraciones no destructivas

Precondicion: backup reciente realizado y entorno Laravel con `DB_CONNECTION=pgsql`.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan migrate --force
```

Resultado esperado: Laravel aplica solo migraciones pendientes. No se borran tablas existentes.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan migrate:status
```

No ejecutar `php artisan migrate:fresh` en produccion. Ese comando elimina y recrea la base.

## Runtime PostgreSQL

El Dockerfile publico de Laravel instala `pdo_sqlite`, no `pdo_pgsql`. Antes de usar PostgreSQL, el operador debe publicar o construir una imagen/runtime que incluya la extension PHP de PostgreSQL y las librerias necesarias.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php -m | grep -E '^pdo_pgsql$'
```

Resultado esperado: aparece `pdo_pgsql`. Si no aparece, no continuar con migraciones de produccion.

## Redis persistente

Redis se usa para sesion, cache y cola. Configurarlo con persistencia segun la politica del operador, por ejemplo AOF o RDB+AOF, y con almacenamiento separado.

Precondicion: `redis` privado arrancado con persistencia.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec redis redis-cli PING
```

Resultado esperado: `PONG`.

Comprobacion desde Laravel:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php artisan queue:failed
```

El comando debe conectar sin errores de Redis o base.

## Volumen documental local

La arquitectura incluida conserva documentos en almacenamiento local persistente montado para Laravel. El modelo documental fuerza `local`, por lo que S3/MinIO requiere desarrollo/configuracion adicional antes de declararse operativo para documentos.

Precondicion: volumen `laravel_storage` montado en el runtime web y worker.

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web test -w storage/app/private
```

Resultado esperado: salida vacia y codigo de salida correcto.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
docker compose exec web php -r "echo is_writable('storage/app/private') ? 'writable' : 'not writable';"
```

## Backup previo a migrar

Antes de cada migracion:

1. Crear backup PostgreSQL.
2. Crear snapshot/copia del volumen documental.
3. Guardar configuracion no secreta de despliegue.
4. Confirmar que existe una restauracion probada.

Sin backup restaurable no debe ejecutarse una migracion de produccion.
