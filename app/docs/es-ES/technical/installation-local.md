# Instalación local

Volver al [índice de documentación](../index.md).

**Aviso antes de arrancar:** el perfil local recrea la base SQLite al iniciar el componente web. Usa solo datos ficticios. No lo uses para información real ni para conservar trabajo.

## Requisitos

- Git.
- Docker Compose v2 o `podman-compose` compatible.
- Red durante la primera construcción para descargar imágenes base y dependencias.

## Arranque

Desde el directorio `app/`:

```sh
docker compose -f compose.public.yml up --build -d
```

Con Podman:

```sh
podman-compose -f compose.public.yml up --build -d
```

Servicios habituales:

| Servicio | URL |
|---|---|
| Interfaz | `http://localhost:3000` |
| API Laravel | `http://localhost:8000` |
| Servicio de propuestas | `http://localhost:8001` |

## Comprobaciones

```sh
curl -fsS http://127.0.0.1:3000/ >/dev/null
curl -fsS http://127.0.0.1:8000/healthz
curl -fsS http://127.0.0.1:3000/api/auth/register-config
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

Estas comprobaciones muestran que los servicios responden. No prueban envío de correo, persistencia productiva, colas externas, exportaciones concretas ni seguridad del entorno.

## Parada

```sh
docker compose -f compose.public.yml down
```

Al volver a arrancar, la base se recrea.
