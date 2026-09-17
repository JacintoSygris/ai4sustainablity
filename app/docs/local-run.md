# Ejecución local rápida

Esta página es un recordatorio operativo para levantar el perfil público local.
La referencia completa de instalación, configuración, límites y despliegue
propio está en [`installation-and-configuration.md`](installation-and-configuration.md).

## Requisitos

- Docker Compose v2, o `podman-compose` con soporte equivalente para Dockerfile.
- Red disponible durante la primera construcción para descargar imágenes base y
  dependencias.

## Levantar el stack

Desde `app/`:

```sh
docker compose -f compose.public.yml up --build -d
```

También puedes usar:

```sh
podman-compose -f compose.public.yml up --build -d
```

Parar y eliminar contenedores:

```sh
docker compose -f compose.public.yml down
```

## Servicios y comprobaciones

| Servicio | URL |
|---|---|
| Frontend Next | `http://localhost:3000` |
| API Laravel | `http://localhost:8000` |
| Servicio FastAPI | `http://localhost:8001` |

```sh
curl -fsS http://127.0.0.1:3000/ >/dev/null
curl -fsS http://127.0.0.1:8000/healthz
curl -fsS http://127.0.0.1:3000/api/auth/register-config
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

Uso en navegador: abre `http://localhost:3000`, registra un usuario y después
inicia sesión.

## Recordatorios de alcance

El perfil local usa una base de datos SQLite efímera en `tmpfs` y Laravel
ejecuta `migrate:fresh --seed` en cada arranque. Todo dato creado durante la
prueba se descarta al reiniciar.

P10 genera un paquete técnico revisable y un candidato técnico iXBRL. No es una
presentación oficial, un trabajo de aseguramiento, una firma legal, una
atestación de taxonomía ni una salida aceptada por un regulador.
