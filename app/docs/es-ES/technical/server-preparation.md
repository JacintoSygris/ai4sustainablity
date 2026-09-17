# Preparacion del servidor

Volver al [manual tecnico](index.md).

## Requisitos de sistema

Dimensionar el host con margen para Next.js, Laravel, FastAPI, PostgreSQL, Redis, Arelle y el worker. Como punto de partida para una instalacion pequena: 4 vCPU, 8 GB de RAM y almacenamiento SSD con espacio separado para base, Redis y volumen documental. El operador debe ajustar recursos tras medir trafico real, tamano documental y tiempos de P9/P10.

Instalar:

- sistema Linux mantenido y actualizado;
- Docker Engine con Compose v2, o Podman con Podman Compose;
- Caddy si se ejecuta fuera de Compose, o imagen Caddy si se ejecuta como servicio;
- herramientas de backup PostgreSQL compatibles con la version desplegada;
- mecanismo de secretos externo al repositorio.

## DNS y firewall

Precondicion: el dominio `app.example.org` apunta al host gestionado por el operador.

Resultado esperado: Internet solo alcanza Caddy en `80` y `443`. Los puertos de Laravel, FastAPI, PostgreSQL y Redis no se publican.

Comprobacion:

```sh
# Directorio: cualquier directorio administrativo del host
# Precondicion: firewall gestionado por el operador.
sudo ss -ltnp
```

La salida debe mostrar procesos escuchando en `80` y `443` para Caddy. Si aparecen `8000`, `8001`, `5432` o `6379` enlazados a interfaces publicas, cerrar esa exposicion antes de continuar.

## Usuario de servicio

Precondicion: acceso administrativo al host.

```sh
# Directorio: cualquier directorio administrativo del host
sudo useradd --system --create-home --home-dir /srv/ia4sustainability --shell /usr/sbin/nologin ia4sustainability
sudo install -d -o ia4sustainability -g ia4sustainability -m 0750 /srv/ia4sustainability
sudo install -d -o ia4sustainability -g ia4sustainability -m 0750 /srv/ia4sustainability/{config,data,backups,logs}
sudo install -d -o ia4sustainability -g ia4sustainability -m 0750 /srv/ia4sustainability/data/{postgres,redis,laravel-storage}
```

Resultado esperado: los directorios existen y no son escribibles por otros usuarios.

Comprobacion:

```sh
# Directorio: cualquier directorio administrativo del host
sudo ls -ld /srv/ia4sustainability /srv/ia4sustainability/data/laravel-storage
```

## Docker Compose

Precondicion: Docker Engine y plugin Compose instalados.

```sh
# Directorio: /srv/ia4sustainability
docker compose version
```

Resultado esperado: se muestra una version de Docker Compose v2.

Comprobacion adicional:

```sh
# Directorio: /srv/ia4sustainability
docker network ls
```

Debe poder listar redes sin exponer servicios aun.

## Podman Compose

Precondicion: Podman y `podman-compose` instalados; si se usa modo rootless, el operador ha habilitado servicios persistentes de usuario segun su distribucion.

```sh
# Directorio: /srv/ia4sustainability
podman --version
podman-compose --version
```

Resultado esperado: ambas herramientas responden.

Comprobacion:

```sh
# Directorio: /srv/ia4sustainability
podman network ls
```

## Permisos de volumen

Laravel necesita escritura en `storage` y `bootstrap/cache`; en la arquitectura de produccion el volumen documental local debe montarse en la ruta de almacenamiento privado usada por Laravel. PostgreSQL y Redis deben tener volumenes separados para permitir backup y restauracion por componente.

No reutilizar `/tmp` para datos. No colocar secretos en el volumen documental. No usar permisos `0777` para resolver errores de escritura; corregir UID/GID y montaje.

## Limites de recursos

Aplicar limites segun la plataforma: memoria para FastAPI/modelo, CPU para Arelle y worker, almacenamiento para PostgreSQL, Redis y documentos. Si se definen limites demasiado bajos, los sintomas habituales son timeouts de `/predict`, validaciones iXBRL fallidas por timeout, jobs atascados y reinicios del runtime PHP.
