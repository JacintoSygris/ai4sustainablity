# Arquitectura

Volver al [manual tecnico](index.md).

## Topologia de referencia

```text
                          red publica
Internet
   |
   v
Caddy :80/:443  -- TLS, headers, body <= 50 MB
   |
   v
frontend:3000  Next.js publico
   |
   | rewrites /api, /characterization, /laravel/*, /profile, /logout, /build/*
   v
web:8000       Laravel privado
   | \
   |  \-- ai-service:8001 FastAPI privado (/healthz, /model-profiles, /predict)
   |
   |-- postgres:5432 PostgreSQL privado
   |-- redis:6379 Redis privado
   |-- worker Laravel privado (queue:work redis)
   \-- laravel_storage volumen local persistente
```

Solo Caddy y el frontend deben quedar alcanzables desde Internet. Laravel, FastAPI, PostgreSQL, Redis y worker permanecen en redes privadas de Compose/Podman y sin puertos publicados al host publico.

## Redes y puertos

| Componente | Puerto interno | Exposicion | Funcion |
|---|---:|---|---|
| Caddy | `80`, `443` | Publica | TLS, cabeceras, limite de cuerpo y reverse proxy. |
| Next.js | `3000` | Publica solo a traves de Caddy | Interfaz y rewrites hacia Laravel. |
| Laravel | `8000` | Privada | API, autenticacion, healthcheck, reporting y trabajos de aplicacion. |
| FastAPI IA | `8001` | Privada | Prediccion tecnica con `/predict`. |
| PostgreSQL | `5432` | Privada | Datos persistentes. |
| Redis | `6379` | Privada | Sesion, cache y cola. |
| Worker Laravel | sin puerto | Privada | Procesamiento de jobs. |

## Flujos

### Navegacion y API

1. El navegador abre `https://app.example.org`.
2. Caddy termina TLS y reenvia al frontend.
3. Next.js sirve paginas y activos.
4. Las rutas configuradas en `next.config.mjs` reescriben llamadas hacia Laravel por red privada.
5. Laravel usa sesion, cache y cola en Redis, y datos persistentes en PostgreSQL.

Resultado esperado: el navegador nunca llama directamente a `web:8000`, `ai-service:8001`, `postgres:5432` ni `redis:6379`.

Comprobacion: desde el host publico, el firewall solo debe admitir entrada a `80` y `443`; desde la red de Compose, `frontend` debe resolver `web` y `web` debe resolver `ai-service`, `postgres` y `redis`.

### Prediccion IA

1. Laravel recibe la caracterizacion.
2. Con `CHARACTERIZATION_GATEWAY=api`, Laravel llama a `CHARACTERIZATION_API_BASE_URL`, por ejemplo `http://ai-service:8001`.
3. FastAPI valida el perfil `new_format_732_v1_gpt41` y responde a `/predict`.
4. La salida es apoyo candidato para revision, no decision automatica ni garantia regulatoria.

### Archivos y colas

Los documentos, si se activan en el futuro, se guardan en disco local persistente porque el modelo `CharacterizationDocument` fuerza el disco `local`. El controlador acepta PDF/DOCX hasta 50 MB, valida extension y magic bytes, y debe conservar validaciones de seguridad de archivos verificadas y controles de seguridad de carga.

La extraccion no esta disponible con el codigo incluido: el job llama `POST /extract-document`, pero el FastAPI publico solo expone `/healthz`, `/model-profiles` y `/predict`. Mantener `P6_DOCUMENT_UPLOAD_ENABLED=false`.

## Compose publico

`app/compose.public.yml` es solo evaluacion local. Publica `8000`, `3000` y `8001`, usa SQLite temporal en `/tmp/ia4sustainability`, configura `CACHE_STORE=array`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`, y el entrypoint ejecuta `migrate:fresh`. No debe usarse en produccion ni como base sin eliminar esas propiedades destructivas.
