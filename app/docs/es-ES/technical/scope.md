# Indice y alcance

Volver al [manual tecnico](index.md).

## Que permite instalar hoy

El repositorio permite construir una instalacion propia basada en Next.js, Laravel y FastAPI, siempre que el operador aporte la infraestructura de produccion: proxy TLS, base PostgreSQL persistente, Redis, worker Laravel, gestion de secretos, correo, copias de seguridad, observabilidad y procedimientos de operacion.

La instalacion de produccion documentada aqui no usa `app/compose.public.yml` como artefacto final. Ese Compose sirve para evaluacion local: publica `web`, `frontend` y `ai-service`; configura SQLite en una ruta temporal; usa cache, sesion y cola no persistentes; desactiva OAuth y documentos; y ejecuta `migrate:fresh` en cada arranque de Laravel.

## Precondiciones

- Dominio de ejemplo usado en el manual: `app.example.org`.
- Host Linux mantenido por el operador, con Docker Compose v2 o Podman Compose.
- Acceso administrativo para crear usuario de servicio, directorios persistentes, firewall y unidades de proceso.
- Imagen o runtime de Laravel adaptado para PostgreSQL: el Dockerfile publico incluido instala `pdo_sqlite`, pero no `pdo_pgsql`.
- PostgreSQL y Redis en red privada, no publicados en Internet.
- SMTP real si se activan verificacion de correo y restablecimiento de contrasena.
- Aplicaciones OAuth externas solo si se desea login social. OAuth no es obligatorio.
- Politica de backup y recuperacion aprobada antes de cargar datos reales.

## Matriz de estado

| Area | Estado | Evidencia operativa | Condicion |
|---|---|---|---|
| Frontend Next.js | Incluido y verificable | `app/frontend/Dockerfile.public`, rewrites en `next.config.mjs` | Publicarlo solo detras de Caddy. |
| Laravel API y UI privada | Incluido y verificable | rutas `web.php`, `api.php`, `auth.php` | Mantenerlo en red privada. |
| FastAPI IA | Incluido y verificable | `/healthz`, `/model-profiles`, `/predict` en `public_model_app.py` | Usar `CHARACTERIZATION_GATEWAY=api`. |
| Modelo IA publico | Incluido y verificable | perfil `new_format_732_v1_gpt41` | Salida candidata, no decision final. |
| Health Laravel | Incluido y verificable | `GET /healthz` | Comprueba base y senal basica de cola. |
| Mapping AR16 a ESRS/DR | Incluido y verificable | `data/ar16_to_esrs_dr_mapping_esrs2023_v1.json`, comando `esrs:validate-matter-dr-mapping` | Activar con ruta inmutable y validada. |
| Informacion ESRS, exportaciones y candidato tecnico | Incluido y verificable con precondiciones | endpoints `report/*`, Arelle y taxonomias vendorizadas | Estado completo, assets validos y validacion tecnica pasada. |
| PostgreSQL produccion | Se configura al instalar | Laravel soporta `pgsql` por configuracion | Runtime con `pdo_pgsql`, backup y migraciones no destructivas. |
| Redis persistente | Se configura al instalar | Laravel soporta Redis para cache, sesion y cola | Configurar persistencia y contrasena/ACL segun plataforma. |
| Caddy/TLS | Se configura al instalar | No hay Caddyfile incluido | Exponer solo Caddy y frontend. |
| Correo SMTP | Se configura al instalar | `config/mail.php` | Credenciales externas, remitente y entregabilidad. |
| OAuth Google/Microsoft | Se configura al instalar | rutas Socialite | Apps externas, redirect exacto y secretos. |
| S3/MinIO documental | Requiere desarrollo antes de activar | `CharacterizationDocument::STORAGE_DISK = 'local'` | No funciona automaticamente para documentos. |
| Extraccion documental | Requiere desarrollo antes de activar | Laravel llama `POST /extract-document`; FastAPI no lo ofrece | Mantener `P6_DOCUMENT_UPLOAD_ENABLED=false`. |

## Regla de lectura

Cuando una seccion dice `incluido`, significa que hay codigo o artefactos en este repositorio que lo hacen comprobable. Cuando dice `se configura al instalar`, significa que el repositorio puede integrarse con esa pieza, pero la instalacion debe aportarla y configurarla. Cuando dice `requiere desarrollo`, ninguna variable, Compose o clave externa convierte esa capacidad en funcional.
