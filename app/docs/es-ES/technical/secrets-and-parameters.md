# Secretos y parametros

Volver al [manual tecnico](index.md).

## Principios

- No guardar `.env` reales en Git.
- No pegar secretos en tickets, documentacion, capturas ni comandos compartidos.
- Usar un gestor de secretos o ficheros del host con permisos `0640` o mas restrictivos.
- Separar configuracion no secreta de secretos: dominios, rutas y flags pueden versionarse en plantillas; claves y contrasenas no.
- Rotar credenciales con una ventana planificada y verificar login, correo, colas y healthchecks despues.

## Creacion de secretos

Precondicion: estar en una copia de despliegue con dependencias PHP instaladas o en un contenedor Laravel construido.

```sh
# Directorio: app/web
php artisan key:generate --show
```

Resultado esperado: se imprime una clave `base64:...`.

Comprobacion: copiarla solo al secreto `APP_KEY` del entorno; no escribirla en Markdown ni en un Compose versionado.

Para contrasenas de base, Redis, SMTP y OAuth, generar valores con el gestor de secretos corporativo o con un generador local seguro. No reutilizar valores entre servicios.

## Almacenamiento y rotacion

Precondicion: el despliegue lee variables desde el mecanismo elegido por el operador.

1. Crear el secreto nuevo sin borrar el anterior.
2. Actualizar el servicio consumidor.
3. Reiniciar solo los procesos afectados.
4. Verificar healthchecks y flujo funcional.
5. Revocar el secreto anterior en el proveedor.

Resultado esperado: el servicio queda operativo con el secreto nuevo y el anterior deja de autenticar.

Comprobacion: revisar logs de autenticacion del proveedor y errores de Laravel/FastAPI sin exponer el secreto.

## Tabla de variables

| Variable | Servicio | Obligatoria | Ejemplo no secreto | Validacion |
|---|---|---:|---|---|
| `APP_NAME` | Laravel | Si | `IA4Sustainability` | Aparece en correos y vistas. |
| `APP_ENV` | Laravel | Si | `production` | `php artisan about` dentro de `web`. |
| `APP_KEY` | Laravel | Si | `base64:EXAMPLE_NOT_A_SECRET=` | Sesiones cifradas no fallan. |
| `APP_DEBUG` | Laravel | Si | `false` | Errores no muestran trazas al usuario. |
| `APP_URL` | Laravel | Si | `https://app.example.org` | Links de correo y OAuth usan el dominio correcto. |
| `APP_LOCALE` | Laravel | Recomendado | `es` | Mensajes localizados esperados. |
| `LOG_CHANNEL` | Laravel | Si | `stderr` o `stack` | Logs visibles en plataforma. |
| `LOG_LEVEL` | Laravel | Si | `info` | No registra datos sensibles en modo debug. |
| `DB_CONNECTION` | Laravel | Si | `pgsql` | `php artisan migrate:status`. |
| `DB_HOST` | Laravel | Si | `postgres` | Laravel alcanza PostgreSQL por red privada. |
| `DB_PORT` | Laravel | Si | `5432` | Conexion TCP interna correcta. |
| `DB_DATABASE` | Laravel/PostgreSQL | Si | `ia4sustainability` | Base existe. |
| `DB_USERNAME` | Laravel/PostgreSQL | Si | `ia4_app` | Rol con permisos limitados. |
| `DB_PASSWORD` | Laravel/PostgreSQL | Si | secreto gestionado | Migraciones autentican. |
| `DB_SSLMODE` | Laravel/PostgreSQL | Depende | `prefer` | Revisar politica del operador. |
| `REDIS_HOST` | Laravel/Redis | Si | `redis` | `queue:work` conecta. |
| `REDIS_PORT` | Laravel/Redis | Si | `6379` | Conexion interna. |
| `REDIS_USERNAME` | Laravel/Redis | Depende | `default` | Si se usan ACL. |
| `REDIS_PASSWORD` | Laravel/Redis | Recomendado | secreto gestionado | Redis no acepta acceso anonimo si la plataforma lo soporta. |
| `REDIS_DB` | Laravel/Redis | Si | `0` | Sesion/cola separables. |
| `REDIS_CACHE_DB` | Laravel/Redis | Si | `1` | Cache separada. |
| `SESSION_DRIVER` | Laravel | Si | `redis` | Login persiste tras recarga. |
| `SESSION_STORE` | Laravel | Si con Redis | `redis` | Sesion usa store correcto. |
| `SESSION_SECURE_COOKIE` | Laravel | Si | `true` | Cookies solo por HTTPS. |
| `SESSION_SAME_SITE` | Laravel | Si | `lax` | Login normal funciona. |
| `CACHE_STORE` | Laravel | Si | `redis` | Cache no usa array en produccion. |
| `QUEUE_CONNECTION` | Laravel | Si | `redis` | Jobs entran en Redis. |
| `REDIS_QUEUE` | Laravel | Recomendado | `default` | Worker escucha la misma cola. |
| `REDIS_QUEUE_RETRY_AFTER` | Laravel | Recomendado | `1800` | Mayor que timeout de jobs largos; con el aprendizaje, mayor que 1200. |
| `DB_QUEUE_RETRY_AFTER` | Laravel | Si con cola `database` | `1800` | Mayor que timeout de jobs largos; con el aprendizaje, mayor que 1200. |
| `MAIL_MAILER` | Laravel | Si para correo | `smtp` | Reset de contrasena envia. |
| `MAIL_HOST` | Laravel/SMTP | Si para correo | `smtp.example.org` | Conexion al proveedor. |
| `MAIL_PORT` | Laravel/SMTP | Si para correo | `587` | STARTTLS habitual. |
| `MAIL_USERNAME` | Laravel/SMTP | Si para correo | usuario proveedor | Autenticacion correcta. |
| `MAIL_PASSWORD` | Laravel/SMTP | Si para correo | secreto gestionado | No aparece en logs. |
| `MAIL_ENCRYPTION` | Laravel/SMTP | Si para correo | `tls` | Envio cifrado. |
| `MAIL_FROM_ADDRESS` | Laravel/SMTP | Si para correo | `no-reply@app.example.org` | Remitente permitido. |
| `MAIL_FROM_NAME` | Laravel/SMTP | Si para correo | `IA4Sustainability` | Nombre visible. |
| `AUTH_REQUIRE_EMAIL_VERIFICATION` | Laravel | Recomendado | `true` | Usuarios no verificados no pasan rutas protegidas. |
| `TURNSTILE_SITE_KEY` | Laravel/frontend | Opcional | clave publica de ejemplo | Registro muestra widget si se configura. |
| `TURNSTILE_SECRET` | Laravel | Opcional | secreto gestionado | Verificacion antiabuso. |
| `SOCIAL_LOGIN_ENABLED` | Laravel | Opcional | `false` | OAuth desactivado por defecto. |
| `GOOGLE_CLIENT_ID` | Laravel/Google | Si Google | id de app | Redirect se inicia. |
| `GOOGLE_CLIENT_SECRET` | Laravel/Google | Si Google | secreto gestionado | Callback autentica. |
| `GOOGLE_REDIRECT_URI` | Laravel/Google | Si Google | `https://app.example.org/auth/google/callback` | Coincide exactamente en Google Cloud. |
| `MICROSOFT_CLIENT_ID` | Laravel/Entra | Si Microsoft | id de app | Redirect se inicia. |
| `MICROSOFT_CLIENT_SECRET` | Laravel/Entra | Si Microsoft | secreto gestionado | Callback autentica. |
| `MICROSOFT_REDIRECT_URI` | Laravel/Entra | Si Microsoft | `https://app.example.org/auth/microsoft/callback` | Coincide exactamente en Entra. |
| `MICROSOFT_TENANT_ID` | Laravel/Entra | Si Microsoft | `common` | Ajustar si el operador restringe tenants. |
| `CHARACTERIZATION_GATEWAY` | Laravel | Si | `api` | No usar `mock` para operacion real. |
| `CHARACTERIZATION_API_BASE_URL` | Laravel/FastAPI | Si | `http://ai-service:8001` (contenedores) o `http://127.0.0.1:8001` (instalación nativa) | `/healthz` responde desde red privada. |
| `CHARACTERIZATION_API_TIMEOUT` | Laravel | Si | `60` | Timeout suficiente para prediccion. |
| `CHARACTERIZATION_AI_MODEL_PROFILE` | Laravel/FastAPI | Si | `new_format_732_v1_gpt41` | `/model-profiles` lo lista. |
| `CHARACTERIZATION_API_TOKEN` | Laravel/FastAPI | No usado por FastAPI incluido | vacio | No asumir autenticacion si el servicio no la implementa. |
| `CHARACTERIZATION_PREDICTION_MAPPING_PATH` | Laravel | Opcional | vacio o ruta interna | Mapping de claves existe si se personaliza. |
| `ESRS_MATTER_DR_MAPPING_PATH` | Laravel | Si para P9/P10 completo | `/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json` | `esrs:validate-matter-dr-mapping`. |
| `ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH` | Laravel | Solo candidato XHTML/iXBRL | ruta absoluta a manifiesto privado | El manifiesto verifica perfil, entrypoint, ZIP externo y SHA-256. |
| `ESRS_ARELLE_COMMAND` | Laravel | Solo candidato XHTML/iXBRL | ruta absoluta a `arelleCmdLine` | Debe ser una ruta absoluta a un ejecutable, no un nombre resuelto por `PATH`: Arelle se ejecuta offline con un entorno mínimo (`PATH=/usr/local/bin:/usr/bin:/bin`). Una ruta relativa o ausente bloquea el candidato. |
| `ESRS_ARELLE_CACHE_DIRECTORY` | Laravel | Opcional, candidato XHTML/iXBRL | ruta absoluta externa | Caché aprovisionada de dependencias públicas XBRL/W3C para validar sin red; ruta absoluta, fuera del repositorio. |
| `FILESYSTEM_DISK` | Laravel | Si | `local` | Documentos usan disco local forzado por modelo. |
| `AWS_ACCESS_KEY_ID` y relacionadas | Laravel | Solo otras integraciones | ejemplo no secreto | No habilitan documentos P6 automaticamente. |
| `P6_DOCUMENT_UPLOAD_ENABLED` | Laravel | Si | `false` | Debe seguir `false` hasta existir `/extract-document`. |
| `P6_DOCUMENT_EXTRACT_TIMEOUT` | Laravel | Futuro | `240` | No arregla endpoint ausente. |
| `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT` | Laravel | Futuro | `300` | No arregla endpoint ausente. |
| `LARAVEL_INTERNAL_API_ORIGIN` | Next.js | Si | `http://web:8000` | Origen privado de Laravel para SSR y rewrites; no se expone al navegador. |
| `LARAVEL_CANONICAL_ORIGIN` | Next.js | Si | `https://app.example.org` | Origen HTTPS visible por el navegador que se reenvia a Laravel para enlaces y cookies. |
| `LARAVEL_API_ORIGIN` | Next.js | Compatibilidad | vacio | Alias legado opcional; si se define debe coincidir con `LARAVEL_INTERNAL_API_ORIGIN`. |
| `NEXT_PUBLIC_LARAVEL_API_BASE_URL` | Next.js | Si | `/api` | Navegador usa origen publico. |
| `NEXT_PUBLIC_APP_URL` | Next.js | Si | `https://app.example.org` | Enlaces de frontend correctos. |
| `NEXT_TELEMETRY_DISABLED` | Next.js | Recomendado | `1` | Sin telemetria de framework. |
| Origenes CORS | Proxy/Laravel | Depende | `https://app.example.org` | Mantener mismo origen cuando sea posible. |

## Toggles seguros

Para produccion inicial:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
CHARACTERIZATION_GATEWAY=api
CHARACTERIZATION_API_BASE_URL=http://ai-service:8001
P6_DOCUMENT_UPLOAD_ENABLED=false
SOCIAL_LOGIN_ENABLED=false
LEARNING_DEPLOYED=false
```

OAuth y Turnstile se activan solo cuando sus proveedores estan configurados y verificados. La carga documental permanece desactivada hasta que exista un servicio compatible con `POST /extract-document`.

Con `APP_ENV=production`, el aprendizaje opcional se activa por configuración salvo que `LEARNING_DEPLOYED=false`. Mantenga `LEARNING_DEPLOYED=false` en contenedores (no soportan el aprendizaje) y en instalaciones nativas hasta completar la lista de aprovisionamiento del [runbook de activación](../../learning-activation-runbook.md).

## Aprendizaje opcional

Variables de Laravel y, donde se indica, del servicio de propuestas. Use los mismos nombres que `app/web/.env.production.example`. Una variable definida, incluso vacía, prevalece sobre su valor predeterminado: una asignación vacía (`VAR=`) no hereda el valor de `LEARNING_DEPLOYED`. Los indicadores booleanos vacíos valen `false`, una autoridad vacía no es `consent`, un modo del bandit vacío equivale a `off` y una ruta vacía bloquea su comprobación; comente o elimine las líneas que deban heredar el valor predeterminado. Los valores predeterminados de la columna «Declarada» se aplican con `APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`; en local y en testing todo queda apagado.

| Variable | Servicio | Declarada | Ejemplo no secreto | Validación |
|---|---|---|---|---|
| `LEARNING_DEPLOYED` | Laravel y FastAPI | — | `false` hasta aprovisionar | Fija los valores predeterminados de activación del motor y del ajuste por retroalimentación. |
| `LEARNING_ENGINE_ENABLED` | Laravel y FastAPI | `true` | `true` | Captura, exportación, entrenamiento y servicio de candidatos. |
| `LEARNING_ENGINE_AUTHORITY` | Laravel y FastAPI | `consent` | `consent` | Con `deny` no se usa ningún dato. |
| `LEARNING_ENGINE_SYNTHETIC_ONLY` | Laravel | `false` | `false` | `true` limita el motor a cuentas sintéticas. |
| `LEARNING_ENGINE_SCHEDULED` | Laravel | `true` | `true` | `learning:tick` encola procesamiento y entrenamiento. |
| `LEARNING_ENGINE_SERVING_ENABLED` | Laravel y FastAPI | `true` | `true` | El servicio puede usar un candidato promovido. |
| `LEARNING_ENGINE_SHADOW_ENABLED` | FastAPI | `false` | `false` | Registro en sombra; dejar en `false` salvo evaluación expresa. |
| `LEARNING_BANDIT_MODE` | Laravel y FastAPI | `active` | `active` | `off`, `shadow` o `active`. |
| `LEARNING_ENGINE_ROOT` | Laravel y FastAPI | `storage/app/private/learning-engine` (no apto) | ruta absoluta persistente fuera del checkout | El panel exige ruta absoluta, real, sin enlaces y escribible; misma ruta y usuario en todos los procesos. |
| `LEARNING_ENGINE_SOURCE` | Laravel | `../ai-service/src` | ruta absoluta a `app/ai-service/src` | Solo si el layout difiere del checkout. |
| `LEARNING_ENGINE_PYTHON` | Laravel | `../ai-service/.venv-learning/bin/python` | ruta absoluta al Python 3.13 de `.venv-learning` | El panel ejecuta `status` con ese intérprete. |
| `LEARNING_ENGINE_CODE_COMMIT` | Laravel | vacío | SHA de 40 caracteres del commit desplegado | Necesario para entrenar y promover. |
| `LEARNING_ENGINE_HELDOUT_PATH` y `_SHA256` | Laravel | vacío | archivo privado fuera de Git y su SHA-256 | Necesario para entrenar y promover. |
| `LEARNING_ENGINE_GOLD_PATH` y `_SHA256` | Laravel | vacío | opcional; ambos o ninguno | Se registra `unavailable` si falta. |
| `LEARNING_ADMIN_USER_IDS` | Laravel | vacío | `12,34` | IDs numéricos con correo verificado que usan el panel. |
| `LEARNING_ENGINE_ADMIN_IDS` | Laravel | vacío | mismos IDs | Respaldo del panel; lo leen `learning:promote` y `learning:rollback`. |
| `I4S_AI_NEW_FORMAT_SCORE_THRESHOLD` | FastAPI | `0.95` | sin definir | Si se define, mismo valor en FastAPI y en el entorno real de PHP-FPM y del worker. |
| `I4S_AI_ARTIFACT_DIR` | FastAPI | resolución automática | sin definir | Igual que la fila anterior. |

El aprendizaje requiere además `CHARACTERIZATION_GATEWAY=api` con `CHARACTERIZATION_API_BASE_URL=http://127.0.0.1:8001`, una cola `database`, `redis` o `beanstalkd` con `*_QUEUE_RETRY_AFTER=1800` (el panel exige un valor mayor que 1200; los valores predeterminados de Laravel son menores), un `CACHE_STORE` compartido distinto de `array`, un worker `queue:work --queue=default,learning --timeout=1200 --tries=1` y `schedule:run` cada minuto. Consulte [Servicios y despliegue](services-deployment.md).

### Métricas del control de promoción

Estos valores son iguales en local y en entornos desplegados; no heredan la activación de `LEARNING_DEPLOYED`. Laravel los pasa explícitamente al puente Python. La política queda congelada antes del ajuste y se vuelve a comprobar al promover. Consulte [Métricas del control de aprendizaje](../../learning-engine-production.md#métricas-del-control-de-promoción).

| Variable | Predeterminado | Validación y función |
|---|---|---|
| `LEARNING_GATE_METRICS_ENABLED` | `false` | Exige activación explícita; requiere mejora de precisión y conservación de cada positivo conocido. |
| `LEARNING_GATE_MIN_COMPANIES` | `30` | Entero de 1 a 1.000.000; empresas distintas en el conjunto reservado. |
| `LEARNING_GATE_MIN_POSITIVES` | `30` | Entero de 1 a 1.000.000; soporte positivo conocido mínimo. |
| `LEARNING_GATE_MIN_NEGATIVES` | `30` | Entero de 1 a 1.000.000; soporte negativo conocido mínimo. |
| `LEARNING_GATE_PRECISION_DELTA` | `0.01` | Finito, 0 ≤ valor < 1; el límite inferior del intervalo debe superarlo estrictamente. |
| `LEARNING_GATE_REPLICATES` | `2000` | Entero de 2 a 10.000; remuestreo pareado por empresa. |
| `LEARNING_GATE_CONFIDENCE` | `0.95` | Finito, 0 < valor < 1; intervalo percentil bilateral. |
| `LEARNING_GATE_SEED` | `17` | Entero de 0 a 2³²−1; repetibilidad sin nuevos sorteos. |
| `LEARNING_SERVING_RUNTIME` | `public` | Único valor admitido; usa el alias público `services`, también en FastAPI. |

Los conjuntos fijos configurados se verifican por SHA-256 y sus empresas se excluyen antes de exportar revisiones. El panel muestra agregados, intervalos y motivos de bloqueo; un valor indefinido aparece como «No disponible». Un empate no supera el control. Los mínimos son decisiones de política, no una garantía de calidad; los recibos anteriores no permiten nuevas promociones con las métricas activadas. Los cambios de código o contrato siguen invalidando candidatos con el indicador apagado.

## Módulos opcionales

Todos están apagados por defecto. La identificación XBRL y la comparación sectorial exigen además indicadores independientes en Python; los demás se gestionan en Laravel. Laravel comunica la capacidad al frontend por la sesión (identificación XBRL, iXBRL, estudio, Taxonomía UE) o por la propia respuesta (importación del ADM, relaciones, comparación sectorial, integridad del alcance del informe); la superposición XBRL no tiene interfaz. Ejecute las migraciones antes de activarlos y renueve la caché de configuración.

| Variable | Predeterminado | Función | Guía |
|---|---|---|---|
| `DMA_IMPORT_ENABLED` | `false` | Importación CSV del análisis de doble importancia relativa a P8 | [Importación del ADM](../../dma-import.md) |
| `DMA_NLP_ENABLED` | `false` | Laravel: propuestas desde textos IRO; requiere importación ADM y trazabilidad IRO revisada | [Análisis de textos](../../dma-import.md#análisis-local-de-textos-iro) |
| `I4S_DMA_NLP_ENABLED` | `false` | Python: ruta autenticada de análisis, independiente; un único proceso ASGI | Ídem |
| `I4S_DMA_NLP_MODEL_DIR` | sin valor | Python: directorio absoluto externo del paquete JSON con `catalogues/`; exige la identidad del manifiesto público | Ídem |
| `CONCEPT_RELATIONS_ENABLED` | `false` | Sugerencias informativas de temas y datos relacionados en P8 y P9 | [Relaciones entre conceptos](../../../ai-service/docs/concept-relations.md) |
| `SECTOR_COMPARISON_ENABLED` | `false` | Laravel: comparación informativa durante la revisión y confirmación de temas | [Comparación sectorial](../../sector-comparison.md) |
| `ESRS_SECTOR_COMPARISON_ENABLED` | `false` | Python: activación independiente del aprendizaje | Ídem |
| `ESRS_SECTOR_COMPARISON_PATH` | sin valor | Python: instantánea JSON autorizada y externa, máximo 5 MiB; se carga al arrancar | Ídem |
| `ESRS_SECTOR_COMPARISON_SHA256` | sin valor | Python: SHA-256 exacto de la instantánea; una configuración inválida inhabilita la comparación | Ídem |
| `XBRL_SUGGESTION_OVERLAY_ENABLED` | `false` | Aplica al mapa de conceptos las sugerencias XBRL aceptadas por un operador | [Sugerencias de conceptos XBRL](../../../ai-service/docs/xbrl-concept-mapping.md) |
| `XBRL_RUNTIME_IDENTIFICATION_ENABLED` | `false` en Laravel y Python | Identificación de conceptos con revisión y evidencia; independiente del aprendizaje y la superposición | [Identificación XBRL](../../../ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución) |
| `XBRL_RUNTIME_MANIFEST_PATH` | sin valor | Laravel: manifiesto externo del modelo servido | Ídem |
| `XBRL_RUNTIME_CATALOG_PATH` | sin valor | Laravel: catálogo externo opcional, vinculado al paquete autorizado | Ídem |
| `XBRL_RUNTIME_MODEL_DIR` | sin valor | Python: directorio absoluto externo del modelo; carga una vez por proceso | Ídem |
| `IXBRL_PACKAGE_UI_ENABLED` | `false` | Paquete candidato iXBRL en P10 y fechas del periodo en P5; apagado, `GET /api/report/ixbrl-package` devuelve 404 | [Paquete candidato iXBRL](../../ixbrl-candidate-package.md) |
| `IXBRL_TAXONOMY_PROFILE` | `esrs_set1_2023-12-22` | Perfil de taxonomía del paquete | Ídem |
| `IXBRL_2026_DRAFT_ENABLED` | `false` | Perfil provisional de 2026; sigue bloqueado sin correspondencias | Ídem |
| `REPORT_SCOPE_INTEGRITY_ENABLED` | `false` | Versiones v2 vinculadas al corpus/mapa y descarga XHTML de presentación; exige nueva aprobación de v1, mantiene controles de v2 al desactivarse | [Integridad del alcance](../../report-scope-integrity.md) |
| `USABILITY_STUDY_ENABLED` | `false` | Kit de estudio de usabilidad con consentimiento | [Protocolo de usabilidad](../../usability-study-protocol.md) |
| `USABILITY_STUDY_ADMIN_USER_IDS` | vacío | IDs que pueden exportar resultados del estudio | Ídem |
| `EU_TAXONOMY_ELIGIBILITY_ENABLED` | `false` | Cribado orientativo de elegibilidad en la Taxonomía de la UE | [Elegibilidad en la Taxonomía de la UE](../../eu-taxonomy-eligibility.md) |

Las guías enlazadas están en castellano.

La comparación sectorial reutiliza `CHARACTERIZATION_API_BASE_URL/TOKEN` en Laravel e `I4S_AI_API_TOKEN` en Python. Configure las variables Python en el entorno de su servicio; el archivo Laravel no configura FastAPI. Mantenga el servicio privado, aprovisione la instantánea fuera de Git y reinicie Python al cambiarla. Ambos indicadores están apagados independientemente del aprendizaje; no se distribuye un corpus sectorial real. Laravel requiere la extensión PHP `curl`.

La identificación XBRL reutiliza `CHARACTERIZATION_API_TOKEN` / `I4S_AI_API_TOKEN` y exige `CHARACTERIZATION_API_BASE_URL` en HTTP con loopback numérico. Los manifiestos y catálogos deben coincidir byte a byte en ambos procesos; sustituir el modelo requiere reiniciar Python. La topología de red entre contenedores incluida no cumple la restricción de loopback. Sin catálogo bilingüe autorizado, el modelo solo admite descripciones inglesas y se abstiene ante texto español. No se redistribuyen artefactos ni taxonomías.

### Candidato XHTML/iXBRL

- `GET /api/report/xhtml-ixbrl-candidate` y `GET /api/report/ixbrl-package` exigen fechas explícitas de inicio y fin del periodo y un LEI válido (MOD-97) en la versión factual aprobada. P5 solo captura esas fechas con `IXBRL_PACKAGE_UI_ENABLED=true`: con los valores predeterminados, aunque la taxonomía y Arelle estén aprovisionados, el candidato responde 409 con `xhtml_ixbrl_context_missing`. Las versiones aprobadas antes de capturar las fechas deben aprobarse de nuevo.
- `ESRS_ARELLE_COMMAND` y `ESRS_ARELLE_CACHE_DIRECTORY` deben ser rutas absolutas fuera del repositorio. Laravel ejecuta Arelle sin shell y con un entorno mínimo (`PATH=/usr/local/bin:/usr/bin:/bin`, `LANG=C.UTF-8` y un `TMPDIR` privado), sin las variables de la aplicación.
- El validador pasa a Arelle `--validationExitCode`, `--disablePersistentConfig`, `--xdgConfigHome` (directorio temporal), `--logFile` con un registro JSON, `--packages` y, si está configurada, `--cacheDirectory`. Estas opciones corresponden a `arelle-release` 2.46.1, la versión fijada en `app/ai-service/scripts/ixbrl/requirements-validation.txt`; `app/web/requirements-arelle.txt`, que instala la imagen web, fija 2.45.1. No se ha comprobado esa combinación: verifique que el Arelle instalado acepta estas opciones antes de activar el candidato. Si no las acepta, el candidato se bloquea con `xhtml_ixbrl_arelle_validation_failed`.

Detalles: [Taxonomía EFRAG externa](external-efrag-taxonomy.md).
