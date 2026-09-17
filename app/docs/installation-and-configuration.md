# Instalación y configuración

Esta es la guía canónica para instalar, ejecutar y configurar el perfil público
de IA4Sustainability. Describe el entorno local incluido en este repositorio y
separa sus límites de lo que debe diseñarse para un despliegue propio.

Este repositorio no suministra un despliegue de producción llave en mano. No
incluye proxy inverso, base de datos persistente configurada, almacenamiento de
objetos, proveedor de correo, credenciales OAuth, proceso de trabajo dedicado ni
Compose de producción.

## Requisitos

- Git.
- Docker Compose v2, o `podman-compose`.
- Red en la primera construcción para descargar imágenes base y dependencias.

No necesitas credenciales externas para el perfil local.

## Clonar y entrar en el paquete

```sh
git clone https://github.com/JacintoSygris/ai4sustainablity.git
cd ai4sustainablity/app
```

El directorio `app/` es la raíz del paquete ejecutable.

## Arrancar el perfil local

Con Docker Compose v2:

```sh
docker compose -f compose.public.yml up --build -d
```

También puedes usar:

```sh
podman-compose -f compose.public.yml up --build -d
```

Servicios publicados en local:

| Servicio | URL |
|---|---|
| Frontend Next | `http://localhost:3000` |
| API Laravel | `http://localhost:8000` |
| Servicio FastAPI | `http://localhost:8001` |

Uso en navegador: abre `http://localhost:3000`, registra un usuario y después
inicia sesión. El frontend proxyfica `/api` hacia Laravel.

## Comprobaciones de salud

Ejecuta estas comprobaciones con el stack levantado. Se usa `127.0.0.1` en
línea de comandos para fijar IPv4 incluso en equipos donde `localhost` prioriza
IPv6; en el navegador puedes usar las URL `localhost` de la tabla anterior.

```sh
curl -fsS http://127.0.0.1:3000/ >/dev/null
curl -fsS http://127.0.0.1:8000/healthz
curl -fsS http://127.0.0.1:3000/api/auth/register-config
curl -fsS http://127.0.0.1:8001/healthz
curl -fsS http://127.0.0.1:8001/model-profiles
```

`/api/auth/register-config` se comprueba a través del frontend porque el
navegador ve `/api`; el hostname interno de Compose no debe aparecer en
configuración expuesta al navegador.

## Configuración exacta del Compose público

`compose.public.yml` está pensado solo para evaluación y desarrollo local:

- `APP_ENV=local` y `APP_DEBUG=false`.
- `APP_KEY` fijo, público y solo apto para este entorno local.
- SQLite en `tmpfs`: `/tmp/ia4sustainability/database.sqlite`.
- Sesiones en fichero, caché en memoria de proceso y cola sincrónica.
- Correo a log.
- OAuth desactivado.
- Verificación de email desactivada.
- Subida documental P6 y escaneo documental P6 desactivados.
- `PUBLIC_COMPOSE_FRONTEND_URL=http://localhost:3000`, para que Laravel genere
  URL hacia el origen del frontend durante la ejecución local.
- `CHARACTERIZATION_GATEWAY=api` y
  `CHARACTERIZATION_API_BASE_URL=http://ai-service:8001` dentro de la red de
  Compose.

El entrypoint público de Laravel ejecuta `migrate:fresh --seed` en cada
arranque del contenedor. Por tanto, el stack descarta todos los datos al
reiniciar. No lo uses para datos reales ni como producción.

## Frontend y proxy de API

El frontend Next usa:

- `LARAVEL_API_ORIGIN=http://web:8000` para llamadas servidor-servidor dentro de
  Compose.
- `NEXT_PUBLIC_LARAVEL_API_BASE_URL=/api` para el navegador.

En un despliegue propio, configura `APP_URL` y el origen/proxy del frontend de
forma coherente, bajo el host HTTPS final. Verifica registro, inicio de sesión,
redirecciones y cookies con ese host definitivo.

## Servicio de modelo

El servicio FastAPI incluido expone solo el perfil
`new_format_732_v1_gpt41`. El endpoint `/model-profiles` reporta 102 claves.

Las predicciones P6 son propuestas candidatas para apoyar una revisión humana
posterior de materialidad. No son materialidad final, decisión automática ni
sustituto de gobierno interno.

## Flujo P5-P10

| Fase | Alcance |
|---|---|
| P5 | Caracterización de la organización. |
| P6 | Propuesta candidata del modelo. |
| P7 | Guía de doble materialidad. |
| P8 | Confirmación humana de materialidad final. |
| P9 | Captura de datapoints factuales y tipados. |
| P10 | Paquete revisable de informe/evidencias y candidato técnico iXBRL. |

P10 no es una presentación oficial ante un regulador, un trabajo de
aseguramiento, una opinión legal, una atestación de taxonomía ni una salida
aceptada por un regulador.

## P9 y mapa AR16 a DR

P9 tiene una puerta fail-closed a nivel Disclosure Requirement. El Compose por
defecto deja `ESRS_MATTER_DR_MAPPING_PATH` sin valor, por lo que no debes
interpretar que todos los datapoints tópicos están habilitados. En ese estado,
P9 permanece en modo de alcance.

Si un operador posee un mapa aprobado, puede añadir un fichero local de
sobreescritura ignorado por Git llamado `compose.local.yml`:

```yaml
services:
  web:
    environment:
      ESRS_MATTER_DR_MAPPING_PATH: /var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

Arranca con ambos ficheros y valida el mapa:

```sh
docker compose -f compose.public.yml -f compose.local.yml up --build -d
docker compose -f compose.public.yml -f compose.local.yml exec web php artisan esrs:validate-matter-dr-mapping --no-ansi
```

Un mapa ausente, inválido o marcado como borrador mantiene el bloqueo
fail-closed y P9 sigue en modo de alcance. No alteres datos de mapeo
suministrados de forma casual; el mapa debe estar aprobado por quien responda
del criterio metodológico.

## Auditorías de runtime

Con el stack levantado:

```sh
docker compose -f compose.public.yml exec web php artisan report:validate-assets --no-ansi
docker compose -f compose.public.yml exec web php artisan taxonomy:validate-assets esrs-set1-2024 --no-ansi
docker compose -f compose.public.yml exec web /opt/arelle/bin/arelleCmdLine --version
```

Que Arelle exista o muestre una versión no demuestra que un documento sea
aceptado por un regulador.

## Parar el stack local

Para detener los contenedores conservando las imágenes construidas:

```sh
docker compose -f compose.public.yml down
```

Si usas `podman-compose`:

```sh
podman-compose -f compose.public.yml down
```

El perfil local guarda la base SQLite en `tmpfs`, por lo que los datos de la
ejecución se descartan al parar el contenedor web.

## Referencia de variables Laravel

La referencia pública está en `web/.env.production.example`. Para un despliegue
propio, usa un fichero de entorno no versionado o un gestor de secretos. No
guardes secretos en Git, imágenes ni logs.

Variables soportadas:

- `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`.
- `DB_*`.
- `SESSION_*`, `CACHE_*`, `QUEUE_*`.
- `MAIL_*`.
- `AUTH_REQUIRE_EMAIL_VERIFICATION`.
- `TURNSTILE_*`.
- `CHARACTERIZATION_GATEWAY=api`.
- `CHARACTERIZATION_API_BASE_URL`.
- `CHARACTERIZATION_API_TOKEN`, opcional; si se configura, se envía como
  `Bearer`.
- `CHARACTERIZATION_API_TIMEOUT`.
- `CHARACTERIZATION_AI_MODEL_PROFILE`.
- `CHARACTERIZATION_PREDICTION_MAPPING_PATH`.
- `ESRS_MATTER_DR_MAPPING_PATH`.
- Variables OAuth sociales: `SOCIAL_LOGIN_ENABLED`, `GOOGLE_*`,
  `MICROSOFT_*`.

Usa valores literales de marcador en ejemplos, como `REPLACE_WITH_APP_KEY`, y
valores reales solo en el mecanismo de secretos del entorno.

## Subida y escaneo documental P6

El perfil público desactiva subida y escaneo documental P6. Esta decisión evita
exponer una superficie de carga de ficheros sin almacenamiento duradero,
límites operativos, política de retención y antivirus real.

No es un interruptor único de producción. Antes de habilitarlo en un despliegue
propio necesitas almacenamiento adecuado, límites de tamaño y tipo, políticas de
retención, supervisión operativa y un binario real de ClamAV accesible desde el
contenedor web.

## Lista pedagógica para despliegue propio

Para un entorno real, diseña y prueba al menos:

- `APP_KEY` único generado para el entorno.
- `APP_ENV=production` y `APP_DEBUG=false`.
- TLS y proxy inverso.
- Base de datos persistente, con copias y restauración ensayadas, y controlador
  de Laravel compatible con la extensión instalada en la imagen que uses.
- Sesiones, caché y cola duraderas, con un proceso de trabajo supervisado.
- FastAPI en red no publicada hacia internet; no expongas el puerto `8001`.
- Correo saliente y autenticación solo con credenciales que controles.
- Subida documental solo con almacenamiento, límites y ClamAV real disponible
  para el contenedor web.
- Secretos fuera de Git, imágenes y logs.
- Monitorización, alertas y procedimientos de backup/restore ensayados.

El repositorio público no afirma suministrar PostgreSQL ni ClamAV en la imagen.

## Resolución de problemas

| Síntoma | Causa probable | Remedio |
|---|---|---|
| El arranque falla por puerto ocupado | Ya hay un proceso usando `3000`, `8000` o `8001`. | Para el proceso que ocupa el puerto o cambia el mapeo local en un fichero de sobreescritura propio. |
| La primera construcción tarda mucho | Descarga inicial de imágenes base y dependencias. | Espera a que termine; la primera ejecución necesita red. |
| Falla `/healthz` de Laravel | El contenedor web aún está migrando o sembrando datos. | Espera unos segundos y revisa logs del servicio `web`. |
| Falla `http://localhost:8001/model-profiles` | El servicio FastAPI no arrancó o falló la construcción. | Revisa logs de `ai-service` y vuelve a construir con el mismo Compose. |
| La aplicación pierde usuarios o datos tras reiniciar | El perfil local usa SQLite en `tmpfs` y `migrate:fresh --seed`. | Es comportamiento esperado; no uses este perfil para datos reales. |
| P9 queda en modo de alcance | `ESRS_MATTER_DR_MAPPING_PATH` está vacío o apunta a un mapa no aprobado/válido. | Configura un fichero local de sobreescritura solo si tienes un mapa aprobado y valídalo con `esrs:validate-matter-dr-mapping`. |
| El navegador no conserva la sesión | `APP_URL`, el proxy o las cookies no coinciden con el host final. | Revisa el origen HTTPS público, las redirecciones y el proxy hacia Laravel. |
| Se interpreta Arelle como garantía de presentación oficial | Confusión entre disponibilidad de herramienta y aceptación regulatoria. | Trata Arelle como validación técnica auxiliar; no implica presentación oficial ni aceptación por regulador. |
