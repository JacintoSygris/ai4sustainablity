# Activación y operación del aprendizaje

Este runbook describe cómo se activa, opera y apaga el aprendizaje opcional desde el panel `/admin/learning`. Complementa [el motor de aprendizaje supervisado en modo operativo](learning-engine-production.md), el contrato [P8 → aprendizaje incremental](../contracts/api/p8-feedback-bandit-v1.md) y la descripción del [ajuste por retroalimentación](../ai-service/docs/feedback-bandit.md). La guía general está en [Motor de aprendizaje opcional](es-ES/learning-engine.md) ([en-GB](en-GB/learning-engine.md)).

Dos circuitos comparten configuración, consentimiento y panel:

- **Motor de aprendizaje supervisado**: entrena candidatos completos con revisiones P8/P9 consentidas, los evalúa contra conjuntos reservados y solo los sirve después de una promoción manual.
- **Ajuste por retroalimentación**: actualiza de forma incremental una política acotada (bandit) con las confirmaciones P8 consentidas; cada versión también requiere promoción manual.

El panel `/admin/learning` dedica una sección a cada circuito, con esos mismos nombres: «Motor de aprendizaje supervisado» y «Ajuste por retroalimentación». Ninguno de los dos sustituye los artefactos del modelo base ni promueve candidatos de forma automática.

## Configuración y autoridad

En Laravel y en el servicio de propuestas, `APP_ENV=production` o `staging` hace que los valores predeterminados sean los de una instalación declarada. `LEARNING_DEPLOYED=true` lo declara explícitamente en ambos procesos sin cambiar su `APP_ENV` real; `LEARNING_DEPLOYED=false` lo mantiene apagado aunque `APP_ENV=production`. En local, en testing y en la composición incluida (`compose.public.yml`) el aprendizaje permanece apagado y sintético.

Una variable definida prevalece sobre el valor predeterminado, también cuando está vacía (`VAR=`): una asignación vacía no hereda el valor de `LEARNING_DEPLOYED`. Los indicadores booleanos vacíos valen `false`, una autoridad vacía no es `consent`, un modo del bandit vacío equivale a `off` y una ruta vacía bloquea su comprobación. Comente o elimine las líneas que deban heredar el valor predeterminado. El despliegue debe reconciliar las variables OFF anteriores sin sobrescribir el resto del entorno.

| Variable | Instalación declarada | Local/testing |
|---|---|---|
| `LEARNING_ENGINE_ENABLED` | `true` | `false` |
| `LEARNING_ENGINE_AUTHORITY` | `consent` | `deny` |
| `LEARNING_ENGINE_SYNTHETIC_ONLY` | `false` | `true` |
| `LEARNING_ENGINE_SCHEDULED` (Laravel) | `true` | `false` |
| `LEARNING_ENGINE_SERVING_ENABLED` (Laravel y servicio de propuestas) | `true` | `false` |
| `LEARNING_ENGINE_SHADOW_ENABLED` (servicio de propuestas) | `false` | `false` |
| `LEARNING_BANDIT_MODE` | `active` | `off` |
| `LEARNING_ADMIN_USER_IDS` y `LEARNING_ENGINE_ADMIN_IDS` (Laravel) | vacío: nadie autorizado | vacío |

El panel lee `LEARNING_ADMIN_USER_IDS` y, si falta, `LEARNING_ENGINE_ADMIN_IDS`; los comandos `learning:promote` y `learning:rollback` leen solo `LEARNING_ENGINE_ADMIN_IDS`. Configure ambas con los mismos IDs numéricos de cuentas existentes con correo verificado. El panel no acepta un actor enviado por el navegador. Todas las acciones usan sesión Laravel, CSRF, límites de frecuencia, motivo obligatorio y auditoría durable anterior a encolar. La promoción del motor supervisado vuelve a ejecutar el control de promoción y exige un candidato real, vigente y autorizado.

`LEARNING_ENGINE_ROOT` debe ser un directorio privado **absoluto, real (sin enlaces simbólicos en ningún componente de la ruta) y persistente**, fuera del checkout y de los directorios de release, con modo `0700`. La aplicación web, el worker, el scheduler y el servicio de propuestas lo comparten con la misma ruta y el mismo usuario del sistema operativo: el registro y el catálogo se crean con modo `0600` y se abren con bloqueo. El valor predeterminado (`storage/app/private/learning-engine`) está dentro del checkout y no es apto para una instalación declarada: un `git clean` o una release nueva lo perderían. Nunca contiene los artefactos del modelo base.

`LEARNING_ENGINE_PYTHON` apunta al ejecutable de `app/ai-service/.venv-learning`, construido con Python 3.13 a partir de `requirements-learning.lock.txt`. `LEARNING_ENGINE_SOURCE` apunta a `app/ai-service/src`. Sus valores predeterminados corresponden al layout del checkout (`../ai-service/src` y `../ai-service/.venv-learning/bin/python` respecto de `app/web`); defínalos solo si el layout es distinto. El panel no recibe rutas, comandos ni archivos subidos.

El puente es un subproceso local con argumentos fijos, un entorno sin credenciales heredadas, tiempo y salida acotados y sin acceso de red. Del entorno real del proceso PHP solo reenvía `PATH`, `LANG`, `LC_ALL`, `SYSTEMROOT`, `I4S_AI_NEW_FORMAT_SCORE_THRESHOLD`, `I4S_AI_ARTIFACT_DIR` y dos rutas de políticas opcionales que el servicio público ignora; los valores que solo figuran en `.env` no le llegan después de `config:cache`. Las variables `LEARNING_*` las añade el propio puente desde la configuración de Laravel. No crea endpoints administrativos en el servicio de propuestas, que sigue escuchando en la red privada. En `app/ai-service/src`, el paquete `services` es un alias de `public_runtime/services`: el puente y el servicio de propuestas cargan los mismos archivos de servicio. Una imagen Laravel sin el código Python ni el entorno de aprendizaje aparece bloqueada en el panel.

Las variables no pueden instalar migraciones, un intérprete, servicios ni conjuntos reservados. Por eso el panel separa «configurado activo» de «disponible» y cada requisito ausente bloquea las acciones que dependen de él. No es seguro inventar conjuntos reservados, grupos empresariales ni un modelo inicial. El motor supervisado requiere además `LEARNING_ENGINE_HELDOUT_PATH`/`_SHA256`, el SHA completo en `LEARNING_ENGINE_CODE_COMMIT`, membresías empresariales verificadas y artefactos base compatibles; sin ellos no hay entrenamiento apto ni promoción. El ajuste por retroalimentación solo necesita el consentimiento y los requisitos comunes. Su preparación pertenece al despliegue; después, las acciones del panel no requieren acceso al servidor.

| Comprobación del panel | Condición | Acciones bloqueadas si falla |
|---|---|---|
| `migrations` | Tablas y columnas de aprendizaje presentes | Todas |
| `state_directory` | `LEARNING_ENGINE_ROOT` absoluto, real, sin enlaces y escribible | Todas |
| `python` | El puente ejecuta `status` con `LEARNING_ENGINE_PYTHON` | Todas |
| `queue` | Latido del worker de menos de 20 minutos y conexión distinta de `sync` | Todas |
| `queue_configuration` | Driver `database`, `redis` o `beanstalkd`, `retry_after` mayor que 1200 y caché distinta de `array` | Todas |
| `scheduler` | Latido de `learning:tick` de menos de 3 minutos | Todas salvo revertir |
| `authority_lease` | Autorización de servicio vigente (90 segundos) | Todas salvo revertir |
| `heldout` | Archivo reservado real, sin enlace, con el SHA-256 configurado | Entrenar y promover el motor supervisado |
| `code_commit` | `LEARNING_ENGINE_CODE_COMMIT` con 40 caracteres hexadecimales | Entrenar y promover el motor supervisado |

## Ciclo, privacidad y límites

P8 captura dentro de la transacción del propietario. Reutiliza la validación P5/P6/P8 existente y `learning_topic_labels`; exige la base del modelo congelada y correspondencias AR16 singleton aprobadas en ambas direcciones. Comprueba también la coherencia con la propuesta mostrada. `revisar`, la ausencia de respuesta, la ambigüedad y los negativos no propuestos no producen eventos. No se recuperan revisiones anteriores al consentimiento o sin base congelada. El arranque sin estado promovido usa un prior vacío en memoria: permite congelar la base sin cambiar la recomendación y no crea ni promueve un archivo al inferir.

La captura depende de que la respuesta del servicio de propuestas incluya `mapping_metadata.feedback_bandit.basis`. Esa base solo existe cuando Laravel usa `CHARACTERIZATION_GATEWAY=api` contra el servicio incluido con el ajuste por retroalimentación activo (o en sombra) y la autorización vigente. Con el gateway `mock` no se capturan eventos ni se sirven candidatos, aunque el panel muestre los requisitos en verde.

`learning_bandit_events` conserva identificadores seudónimos, una clave única por sujeto/caracterización/generación/revisión/tema y el evento estructurado. No exporta IDs de cuenta, nombres, documentos, notas ni valores P9. La secuencia consecutiva se asigna únicamente bajo el bloqueo del escritor Python. `learning:tick`, programado cada minuto, encola el procesamiento de eventos como máximo cada cinco minutos y el entrenamiento del motor supervisado una vez al día. El panel puede encolarlos también. Nunca existe una promoción programada.

La tarea envía el registro permitido, acotado a 20.000 eventos. Un prefijo idéntico solo aplica los eventos nuevos mediante `update_event`; un reintento no duplica. Una retirada, corrección o caducidad obliga a reconstruir desde los eventos aún válidos. Los archivos de versión inmutables y el catálogo atómico hacen recuperable una interrupción entre Python y el marcado de eventos en Laravel. El historial se conserva hasta su caducidad o retirada; no se conservan versiones derivadas de sujetos revocados. El catálogo tiene un límite de lectura/escritura de 32 MB: agotarlo bloquea nuevas versiones y obliga a revisar la capacidad y el volumen, sin truncar el registro.

El servicio de propuestas lee solo el puntero activo y comprueba integridad, perfil, esquema, identidad del modelo base, caducidad y autorización de los sujetos y revisiones. Una autorización dura como máximo 90 segundos y la renueva `learning:tick`. Si cualquier comprobación falla, se conserva la salida base. La promoción de una versión del bandit cambia la identidad de la política servida: puede invalidar un candidato supervisado evaluado contra la anterior y exigir entrenar y evaluar otro. El candidato supervisado conserva la base del modelo para futuros eventos; las propuestas registradas son las realmente mostradas, no acciones hipotéticas en sombra. La recompensa es la concordancia con P8, no una medida causal de calidad ni una garantía de sensibilidad.

Consentimiento: versión `learning-feedback-bandit-2026-10-08`, con la finalidad `feedback:supervised-learning`. Un consentimiento anterior no autoriza por sí solo esta ampliación. El perfil y la política de privacidad en castellano e inglés explican la seudonimización y permiten retirarlo. La invitación posterior a P8 no bloquea el flujo. P9 sigue siendo evidencia estructurada del motor supervisado, no etiquetas normativas para el bandit.

Eventos, candidatos y auditoría operativa caducan a los 30 días. Las versiones del bandit caducan también al vencer el evento más antiguo que contienen. Las exportaciones temporales se eliminan al terminar y las huérfanas a los 20 minutos. La retirada invalida el permiso inmediatamente cuando el almacenamiento responde; si no, expira en 90 segundos. No existe una resta exacta del agregado ni se afirma un «desentrenamiento»: se bloquean o eliminan las versiones afectadas y se genera un candidato nuevo, que necesita aprobación manual. El borrado físico requiere el mantenimiento programado (`learning:maintain-privacy`, cada minuto), incluso con las variables OFF. Las copias de seguridad deben reconciliar las retiradas antes de renovar permisos. El registro del motor supervisado conserva su auditoría mínima y los tombstones de retirada descritos en [su documentación](learning-engine-production.md).

## Comportamiento operativo

- Promover una versión ya seleccionada es una operación idempotente dentro del bloqueo y la transacción de cada registro, y conserva el destino anterior de reversión. El panel deshabilita esa promoción redundante.
- El panel distingue entre el modelo seleccionado y el modelo efectivo, muestra el motivo del fallback y la identidad base calculada por el puente local. Al promover una versión del bandit incompatible, el candidato supervisado previo deja de aparecer como efectivo y apto. Las métricas son históricas e identifican su base y su comparador; los manifiestos que no registraban el comparador efectivo muestran «No registrado».
- El puente no consulta el proceso del servicio de propuestas: **no certifica que su entorno coincida**. El despliegue debe mantener iguales variables, políticas, versiones y código en ambos procesos y comprobarlo con las lecturas de paridad de la sección (b).
- Laravel pasa explícitamente `LEARNING_ENGINE_SERVING_ENABLED` al puente; su valor predeterminado sigue a `LEARNING_DEPLOYED`.
- Una colisión de trabajos devuelve la reserva a la cola durante 30 segundos, con un máximo de 45 reservas. La clase del trabajo declara este límite, que prevalece sobre `queue:work --tries=1`. El estado durable impide ejecutar de nuevo una acción completada; un fallo de ejecución sigue cerrado, sin reintento automático de una promoción parcialmente terminada. El tick conserva la caducidad de trabajos atascados.
- `learning:tick` está siempre programado. La admisión del entrenamiento depende de las variables del motor; el procesamiento de eventos depende además del modo del bandit.

## (a) Instalación nativa

Esta sección resume la preparación de un servidor con Laravel, Next y el servicio de propuestas instalados directamente desde un checkout. Use rutas absolutas reales, no enlaces de tipo `current`. Los valores entre `<...>` son del operador.

```bash
# Variables del operador: rutas absolutas reales.
export I4S_CHECKOUT='<ruta-absoluta-del-checkout>'
export I4S_LEARNING_STATE='<ruta-absoluta-persistente-fuera-del-checkout>/learning-engine'
export I4S_RUNTIME_USER='<usuario-del-servicio>'

sudo install -d -m 0700 -o "$I4S_RUNTIME_USER" -g "$I4S_RUNTIME_USER" "$I4S_LEARNING_STATE"
cd "$I4S_CHECKOUT/app/ai-service"
sudo -u "$I4S_RUNTIME_USER" python3.13 -m venv .venv-learning
sudo -u "$I4S_RUNTIME_USER" .venv-learning/bin/python -m pip install --require-hashes -r requirements-learning.lock.txt
sudo -u "$I4S_RUNTIME_USER" .venv-learning/bin/python -B -c 'import sklearn, optuna, numpy, joblib, scipy, fastapi, uvicorn; print("learning imports OK")'
```

Después aplique los pasos 3 a 9 de la sección (b), con las mismas variables en Laravel y en el servicio de propuestas. El paso 7 incluye la copia de seguridad de la base **antes** de `php artisan migrate --force`. Al terminar esos pasos, ejecute:

```bash
cd "$I4S_CHECKOUT/app/web"
sudo -u "$I4S_RUNTIME_USER" php artisan config:cache
sudo -u "$I4S_RUNTIME_USER" php artisan schedule:list
sudo -u "$I4S_RUNTIME_USER" php artisan queue:restart
```

Una entrada cron del usuario del servicio ejecuta el scheduler cada minuto:

```cron
* * * * * cd <ruta-absoluta-del-checkout>/app/web && php artisan schedule:run >> <ruta-privada-de-logs>/scheduler.log 2>&1
```

No elimine otras entradas cron. Si ya existe un timer o cron del scheduler, conserve uno solo. Tras activar la configuración, entre con la autenticación habitual y abra `/admin/learning`. El primer tick envía una sonda a la cola: un worker declarado activo que no consume esa sonda no cuenta como disponible. El latido de la cola tolera un entrenamiento largo; la caída del worker puede tardar hasta 20 minutos en verse.

Complete después los pasos 10 a 12 de la sección (b), que también son obligatorios en una instalación nativa: membresías empresariales con `php artisan learning:assign-company`, ejecutado directamente como el usuario del servicio (sin ellas, las ejecuciones del motor supervisado terminan como «Sin revisiones aptas»); reinicio del servicio de propuestas; y comprobaciones de paridad de `runtime_sha256` y `serving_identity_sha256` con todas las comprobaciones del panel en verde.

## (b) Despliegue con Jenkins

El job de Jenkins despliega el repositorio público sobre un servidor con instalación nativa (checkout, servicio de propuestas como unidad systemd, Laravel, worker y cron). Lo opera la persona responsable de la instalación. El aprendizaje **no está soportado en contenedores**: la imagen web no incluye `app/ai-service/src` ni `.venv-learning`, no existe un volumen de estado compartido y la imagen del servicio de propuestas usa otra versión de Python y otras dependencias, lo que impide la paridad de versiones. `compose.public.yml` y `Dockerfile.public` quedan con el aprendizaje apagado.

**Primer despliegue.** Con `APP_ENV=production`, el aprendizaje se activa por configuración en cuanto se instala esta versión. Si la lista siguiente no está completa, despliegue con `LEARNING_DEPLOYED=false` en Laravel y en el servicio de propuestas y actívelo después; de lo contrario, el panel mostrará requisitos bloqueados y el tick fallará de forma cerrada cada minuto.

Lista de aprovisionamiento, en orden:

1. **Construcción normal.** `composer install` de despliegue, `pnpm install --frozen-lockfile` y `pnpm build`, con los tests en la fase de validación del runner.
2. **Entorno de aprendizaje.** Construir `app/ai-service/.venv-learning` con Python 3.13 desde el archivo con hashes y comprobar las importaciones:

   ```bash
   python3.13 -m venv app/ai-service/.venv-learning
   app/ai-service/.venv-learning/bin/python -m pip install --require-hashes -r app/ai-service/requirements-learning.lock.txt
   app/ai-service/.venv-learning/bin/python -B -c 'import sklearn, optuna, numpy, joblib, scipy, fastapi, uvicorn'
   ```

   Construya en una ruta nueva y cámbiela de forma atómica, o detenga el servicio de propuestas mientras se reconstruye. `app/.gitignore` excluye `.venv-learning/`; no lo elimine con `git clean -x`.
3. **Servicio de propuestas desde ese entorno.** La unidad del servicio ejecuta FastAPI con el mismo intérprete que el entrenador, **con el mismo usuario del sistema operativo** que PHP-FPM, el worker y el cron, y sin un `PYTHONPATH` heredado:

   ```ini
   [Service]
   User=<usuario-del-servicio>
   WorkingDirectory=<ruta-absoluta-del-checkout>/app/ai-service
   ExecStart=<ruta-absoluta-del-checkout>/app/ai-service/.venv-learning/bin/python -m uvicorn public_model_app:app --host 127.0.0.1 --port 8001
   ```

   Si el servicio usa otro entorno (por ejemplo, uno construido desde `requirements.public.txt`), las versiones de Python, NumPy, scikit-learn, joblib o SciPy no coinciden con el manifiesto del candidato y los candidatos promovidos vuelven en silencio al modelo base.
4. **Estado persistente.** `LEARNING_ENGINE_ROOT` absoluto, persistente, sin enlaces simbólicos, fuera del checkout y de las releases, con modo `0700`, propiedad del usuario del servicio e idéntico en Laravel y en el servicio de propuestas. PHP-FPM debe permitir `proc_open`.
5. **Variables en Laravel y en el servicio de propuestas.** `LEARNING_DEPLOYED=true` (o `APP_ENV=production`), `LEARNING_ENGINE_ENABLED=true`, `LEARNING_ENGINE_AUTHORITY=consent`, `LEARNING_ENGINE_SYNTHETIC_ONLY=false`, `LEARNING_ENGINE_SERVING_ENABLED=true`, `LEARNING_BANDIT_MODE=active` y el mismo `LEARNING_ENGINE_ROOT`. En el servicio de propuestas, además, `LEARNING_ENGINE_SHADOW_ENABLED=false`. No defina `ESRS_FEEDBACK_BANDIT_PATH`: el modo gestionado lee el catálogo privado. Deje `I4S_AI_NEW_FORMAT_SCORE_THRESHOLD` e `I4S_AI_ARTIFACT_DIR` sin definir en todos los procesos, o defínalos con el mismo valor en la unidad del servicio de propuestas **y** en el entorno real de PHP-FPM y del worker.
6. **Variables solo de Laravel.**
   - `LEARNING_ENGINE_CODE_COMMIT=$GIT_COMMIT` (SHA completo de 40 caracteres del commit desplegado) y `LEARNING_ENGINE_SCHEDULED=true`.
   - `LEARNING_ADMIN_USER_IDS` **y** `LEARNING_ENGINE_ADMIN_IDS` con los mismos IDs verificados.
   - `CHARACTERIZATION_GATEWAY=api` y `CHARACTERIZATION_API_BASE_URL=http://127.0.0.1:8001`. Con `mock` no hay captura de eventos ni candidatos servidos.
   - `CHARACTERIZATION_AI_MODEL_PROFILE=new_format_732_v1_gpt41` y `CHARACTERIZATION_PREDICTION_MAPPING_PATH` vacío, para que Laravel use la correspondencia del perfil 732 en la captura y el entrenamiento. Sin ese perfil se usa la correspondencia heredada y ninguna comprobación del panel lo detecta.
   - `LEARNING_ENGINE_HELDOUT_PATH`/`_SHA256` y, opcionalmente, `LEARNING_ENGINE_GOLD_PATH`/`_SHA256` (ambos o ninguno). Los archivos se entregan mediante credenciales de Jenkins (archivo secreto) a rutas absolutas sin enlaces, fuera de Git, de hasta 32 MB.
   - `LEARNING_ENGINE_SOURCE`/`LEARNING_ENGINE_PYTHON` solo si el layout difiere del checkout.

   Conjuntos reservados, corpus, modelos derivados, credenciales y evidencias de pertenencia **no** entran en Git ni en el repositorio público.
7. **Base de datos.** Copia de seguridad privada y después `php artisan migrate --force`. Nunca `migrate:fresh` ni el `public-entrypoint.sh` de la composición local. Las migraciones son aditivas; no use sus `down()` para revertir código. Después, `php artisan config:cache` en el directorio final.
8. **Cola.** `QUEUE_CONNECTION` `database`, `redis` o `beanstalkd` con `DB_QUEUE_RETRY_AFTER=1800` (o `REDIS_QUEUE_RETRY_AFTER=1800`/`BEANSTALKD_QUEUE_RETRY_AFTER=1800`, según el driver); el valor predeterminado es inferior al tiempo de los trabajos de aprendizaje. Un worker supervisado que se reinicie en cualquier salida:

   ```bash
   php artisan queue:work --queue=default,learning --timeout=1200 --tries=1
   ```

   Use un `CACHE_STORE` compartido por web, worker y scheduler, nunca `array`. No arranque otro worker con una configuración distinta.
9. **Scheduler.** Una única entrada `* * * * * php artisan schedule:run`, ejecutada desde `app/web` con el mismo usuario. Sin el tick, la autorización de 90 segundos caduca: el servicio vuelve al modelo base y el bandit se apaga.
10. **Membresías empresariales.** Una etapa de Jenkins parametrizada ejecuta `php artisan learning:assign-company` en el servidor, para no depender de un acceso interactivo:

    ```bash
    # Crea un grupo seudónimo nuevo con esta cuenta:
    php artisan learning:assign-company create account <id-de-cuenta> --evidence-digest=<sha256-de-la-evidencia-privada> --evidence-type=<tipo>
    # Añade otra cuenta de la misma empresa a un grupo existente:
    php artisan learning:assign-company assign account <id-de-cuenta> --group-id=<id-de-grupo> --evidence-digest=<sha256> --evidence-type=<tipo>
    # Revoca la pertenencia con la huella de la evidencia de revocación:
    php artisan learning:assign-company revoke account <id-de-cuenta> --evidence-digest=<sha256> --evidence-type=<tipo>
    ```

    `--evidence-digest` es el SHA-256 (64 caracteres hexadecimales en minúsculas) de la evidencia privada y `--evidence-type`, un token no personal en minúsculas. La evidencia queda fuera del repositorio; solo se registra su huella. Asigne las cuentas de una misma empresa al mismo grupo y no cree grupos distintos para que una empresa aparezca a la vez en entrenamiento y prueba. Sin membresías verificadas, las ejecuciones del motor supervisado terminan como «Sin revisiones aptas». Los IDs de administración existen solo cuando las cuentas existen y tienen el correo verificado.
11. **Reinicios.** Reinicie el servicio de propuestas en cada despliegue (caché de modelo y huellas de código) y ejecute `php artisan queue:restart`.
12. **Comprobaciones posteriores, que hacen fallar el job si no coinciden.**
    - Paridad de versiones y código. El valor esperado, calculado con el entorno de aprendizaje desde `app/ai-service`:

      ```bash
      PYTHONPATH=src .venv-learning/bin/python -c 'import learning_production_registry as r; print(r.digest({"versions": r.versions(), "code_sha256": r.code_hashes()}))'
      ```

      debe ser igual a `learning.runtime_sha256` del servicio en ejecución:

      ```bash
      curl -fsS http://127.0.0.1:8001/healthz | python3 -c 'import json, sys; print(json.load(sys.stdin)["learning"]["runtime_sha256"])'
      ```

      Si el proceso no carga los módulos de aprendizaje, `learning` solo contiene `hooks_available: false` y la orden falla; si la huella no puede calcularse, `runtime_sha256` es `null` y se imprime `None`. Ambos casos cuentan como diferencia.

    - Paridad de identidad de servicio. El campo de primer nivel `serving_identity_sha256` del servicio en ejecución (`null` si no puede calcularse):

      ```bash
      curl -fsS http://127.0.0.1:8001/model-profiles | python3 -c 'import json, sys; print(json.load(sys.stdin)["serving_identity_sha256"])'
      ```

      debe ser igual a la identidad base que calcula el puente de Laravel (`wp13.serving.baseline.serving_identity_sha256` del estado del puente), visible en el panel como «Identidad base actual». Desde `app/web`, como el usuario del servicio:

      ```bash
      php artisan tinker --execute='echo app(App\Services\LearningPythonBridge::class)->run("status")["wp13"]["serving"]["baseline"]["serving_identity_sha256"] ?? "";'
      ```

      Una diferencia indica deriva de entorno (umbral, modo del bandit, raíz de estado, autorización o bytes de artefactos): los candidatos supervisados dejan de servirse y las decisiones del bandit no quedan vinculadas.

    - Configuración efectiva del servicio de propuestas. `/model-profiles` incluye también el objeto `learning`, con `hooks_available`, `engine_enabled`, `serving_enabled` y `bandit_mode`, leídos del entorno del propio proceso, sin rutas ni identificadores:

      ```bash
      curl -fsS http://127.0.0.1:8001/model-profiles | python3 -c 'import json, sys; print(json.dumps(json.load(sys.stdin)["learning"], sort_keys=True))'
      ```

      Con las variables del paso 5 debe imprimir `{"bandit_mode": "active", "engine_enabled": true, "hooks_available": true, "serving_enabled": true}`.

    - `php artisan schedule:list`; esperar a un tick real y a la sonda de la cola, y abrir `/admin/learning`: todas las comprobaciones (`migrations`, `state_directory`, `python`, `scheduler`, `queue`, `queue_configuration`, `authority_lease`, `heldout`, `code_commit`) en verde. Una ejecución manual de `learning:tick` es un diagnóstico, no una prueba de que el cron vaya a seguir funcionando.
13. **Interruptor OFF.** El job ofrece parámetros para aplicar la configuración de la sección (c) en ambos procesos y reiniciarlos.

Cualquier release que modifique `public_runtime/services/service_predict.py` o `datatypes.py`, los módulos de aprendizaje de `app/ai-service/src`, `src/train/masked_multilabel.py` o la versión de Python del servidor invalida los candidatos supervisados promovidos hasta que se entrenen y promuevan de nuevo. La promoción de una versión del bandit tiene el mismo efecto, por diseño.

Después no hay operación por consola: el administrador entra en `/admin/learning`, registra el motivo, usa «Entrenar ahora» o «Procesar eventos», pulsa «Actualizar estado» y consulta trabajos y auditoría. Examina las métricas por conjunto y el control antes de «Promover» un candidato supervisado; compara versión, hash y eventos antes de «Promover» una versión del bandit. «Revertir» restaura el puntero anterior; si estaba vacío, vuelve a la base o al prior vacío. Un estado anterior caducado o revocado **no puede recuperarse**. Los permisos se revalidan al ejecutar los trabajos en cola.

Si faltan migraciones, Python, directorio o servicios, el panel lo diagnostica, pero no concede permisos de administración del sistema: corrija parámetros o construcción y vuelva a ejecutar Jenkins. No es seguro reparar esos requisitos desde una petición web.

## (c) OFF y reversión

Apagado completo por configuración, aplicado a Laravel, workers y servicio de propuestas mediante Jenkins o el despliegue nativo:

```dotenv
LEARNING_ENGINE_ENABLED=false
LEARNING_ENGINE_SCHEDULED=false
LEARNING_ENGINE_SERVING_ENABLED=false
LEARNING_ENGINE_SHADOW_ENABLED=false
LEARNING_BANDIT_MODE=off
```

Después reconstruya la caché de configuración y reinicie la web, el worker y el servicio de propuestas. No retire el scheduler de privacidad. OFF no equivale a una retirada de consentimiento ni restaura la base de datos.

Para revertir una promoción, use primero «Revertir» en el panel y verifique los hashes de auditoría. Con el motor supervisado apagado, el panel ofrece «Restablecer modelo base»: elimina la selección activa aunque exista un candidato anterior y no intenta autorizar ese candidato con el motor apagado. Con el motor activo, «Revertir» recupera la selección anterior solo si conserva consentimiento, integridad y compatibilidad. El ajuste por retroalimentación mantiene su reversión bajo sus comprobaciones de consentimiento. Estas operaciones requieren el entorno de Python y la cola disponibles. No se revierte a una versión no autorizada ni se deshacen migraciones para revertir código: son aditivas y sus `down()` destruirían evidencias. Si el panel o el worker están caídos, la salida segura es aplicar OFF mediante Jenkins; una interfaz caída no puede modificar su propio entorno. Revertir el código consiste en volver a desplegar el commit público anterior.

## Pruebas

```bash
# app/ai-service, con el entorno de aprendizaje:
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_feedback_bandit*.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_learning_production*.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_service_predict.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_feature_encoding.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_masked_multilabel.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_public_learning_wiring.py'
# app/ai-service, runtime público con y sin los módulos de aprendizaje (pytest se instala aparte):
PYTHONDONTWRITEBYTECODE=1 .venv-learning/bin/python -m pytest -q -p no:cacheprovider tests/test_public_learning_hooks.py
# app/frontend:
node --test tests/*.mjs
node_modules/.bin/tsc --noEmit --incremental false -p .
# app/web:
php artisan test tests/Feature/Services/LearningActivationTest.php
```

`test_public_learning_wiring.py` ejecuta el servicio de propuestas y los comandos del puente en procesos separados, como en una instalación nativa, y recorre el ciclo completo (procesar y promover una versión del bandit, entrenar, promover y revertir un candidato supervisado, borrar un sujeto) con el modelo distribuido, datos sintéticos y un directorio de estado temporal. También comprueba que coinciden las lecturas de paridad de la sección (b). `test_public_learning_hooks.py` arranca el runtime público con y sin los módulos de `src/`. `LearningActivationTest` cubre proyección, consentimiento, captura P8, idempotencia, aislamiento, migraciones ausentes, CSRF, lista de administración, límites, cola y auditoría. Las pruebas usan datos sintéticos: verifican el funcionamiento, no la calidad con personas usuarias reales.
