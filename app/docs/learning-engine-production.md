# Motor de aprendizaje supervisado en modo operativo

El [runbook de activación](learning-activation-runbook.md) documenta el panel `/admin/learning`, el ajuste por retroalimentación, los valores predeterminados de una instalación declarada y el despliegue. Cuando esta nota y el runbook difieren en valores predeterminados u operación, prevalece el runbook. Esta nota describe la composición del motor supervisado, sus conjuntos fijos, el control de promoción y la protección de datos.

El control impide promover pérdidas de sensibilidad sobre los conjuntos reservados evaluados. Con las métricas opcionales activadas exige además una mejora predeclarada de precisión; no garantiza utilidad ni ausencia de pérdidas fuera de esos conjuntos.

## Composición y límites

Laravel conserva autenticación, sesiones, consentimiento, titularidad de las respuestas, exportación y administración. La vía operativa usa `LearningFeedbackDataset` → `TrainLearningFeedback` → `LearningPythonBridge` → `wp13_feedback_bridge.production_rows` → `wp13_feedback_training.train_production_feedback` → `ProductionCandidateRegistry`. No requiere cambiar `APP_ENV` a `testing`. Las factorías, los relojes aislados y los recibos `learning-case-v1` conservan sus restricciones sintéticas. Los recibos sintéticos no se convierten en permisos reales y el lote sintético `learning:batch` no se ejecuta en producción.

El consentimiento se recoge explícitamente en `/profile`, en castellano o inglés, separado de la revisión P8 y de la aceptación del servicio. Solo las cuentas con correo verificado, consentimiento vigente y pertenencia empresarial verificada entran en la exportación. La autoridad configurable sustituye la denegación incondicional para la finalidad `feedback:supervised-learning`.

P8 incorpora el sello `training_source` al confirmar después del consentimiento. El exportador comprueba generación, huella P5, catálogo y foto P6. Usa las etiquetas del universo expresamente revisado o decisiones guiadas explícitas; no interpreta ausencias como negativas. P9 añade su sello y vuelve a validar la autoridad del corpus y la revisión P8. Exporta únicamente el identificador canónico, `relevant` y `selected_to_answer`; excluye notas, razones libres y valores del informe. Una revisión P9 obsoleta no se exporta.

El entrenamiento usa el codificador del payload de predicción de Laravel y una transformación única de esos campos en entrenamiento e inferencia. Se excluyen de las variables predictoras el nombre, el LEI, los textos, los documentos y los identificadores. La forma jurídica libre también se excluye. El vocabulario se ajusta solo con el entrenamiento. El RF enmascarado aprende únicamente claves NEIS con correspondencia AR16 singleton aprobada y ejemplos conocidos de ambas clases; las restantes claves conservan la salida del modelo base. **P9 se incluye en la huella y en el recuento de evidencia; no se convierte en etiquetas de materialidad ni cambia obligaciones normativas.** No hay aprendizaje por refuerzo en este motor, aprendizaje online, `partial_fit` ni selección automática de taxonomía.

Cada exportación se limita a 5000 filas; si se supera, falla sin truncar en silencio. El entrenamiento es una tarea completa en cola, con un solo intento y exclusión mutua. El modelo candidato y su manifiesto se guardan juntos en una transacción SQLite privada, con hashes de datos, mapa, modelo y código, commit declarado por la instalación, versiones de dependencias, soporte de etiquetas, métricas, grupos reservados y procedencia. No usa MLflow ni servicios externos.

## Configuración

Valores para local y testing. En una instalación declarada (`APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`) se aplican los valores del runbook.

| Ajuste | Local/testing | Función |
|---|---|---|
| `LEARNING_ENGINE_ENABLED` | `false` | Captura, exportación y entrenamiento operativos; también debe habilitarse en el servicio de propuestas |
| `LEARNING_ENGINE_AUTHORITY` | `deny` | `consent` en Laravel y en su subproceso |
| `LEARNING_ENGINE_SYNTHETIC_ONLY` | `true` | Solo cuentas marcadas por el servidor como sintéticas; sus candidatos nunca se promueven |
| `LEARNING_ENGINE_SCHEDULED` | `false` | Permite que el tick encole el entrenamiento diario |
| `LEARNING_ENGINE_SERVING_ENABLED` | `false` | El servicio de propuestas puede usar el candidato promovido |
| `LEARNING_ENGINE_SHADOW_ENABLED` | `false` | El servicio de propuestas registra candidato y salida servida sin cambiar la respuesta |

`learning_synthetic` no es asignable por formularios. Para datos reales se requiere `LEARNING_ENGINE_SYNTHETIC_ONLY=false`, además del consentimiento y la habilitación. Ninguna variable promueve modelos por sí sola. La interfaz de consentimiento solo aparece con la autoridad activa o cuando existe un consentimiento previo que retirar. La retirada sigue disponible con el motor apagado. El borrado de cuenta tampoco depende de las variables.

## Preparación de una instalación

1. Ejecutar las migraciones normales de Laravel con `php artisan migrate --force`, después de una copia de seguridad. Son aditivas y tienen `down()`: campos de consentimiento, ejecuciones, solicitudes de borrado y el reloj de revisiones P9. Revertirlas destruye los datos añadidos; antes habría que procesar los borrados pendientes y volver al modelo base.
2. Construir `app/ai-service/.venv-learning` con Python 3.13 desde `requirements-learning.lock.txt` (instalación con `--require-hashes`). Configurar `LEARNING_ENGINE_PYTHON` con su ejecutable si el layout difiere del checkout y `LEARNING_ENGINE_ROOT` con un directorio privado absoluto y persistente, compartido por Laravel, el entrenador y el servicio de propuestas. No usar la carpeta de los artefactos base, una ruta pública, enlaces simbólicos ni almacenamiento compartido por red. Directorio `0700` y archivos `0600`; no admitir subidas de modelos ni escritores ajenos a esas identidades operativas. Los pickles solo proceden del entrenador local de confianza; un hash no hace seguro un pickle de terceros.
3. Configurar `LEARNING_ENGINE_CODE_COMMIT` con el SHA completo del commit desplegado, y `LEARNING_ADMIN_USER_IDS` y `LEARNING_ENGINE_ADMIN_IDS` con las mismas cuentas administrativas verificadas. El SHA declarado complementa los hashes de código calculados realmente; el despliegue es responsable de que corresponda a ese commit.
4. Mantener el servicio de propuestas en la red privada y ejecutarlo con el mismo código Python y el mismo entorno `.venv-learning` que el entrenador. El adaptador requiere el perfil base `new_format_732_v1_gpt41`. Mantener idénticos `I4S_AI_NEW_FORMAT_SCORE_THRESHOLD` e `I4S_AI_ARTIFACT_DIR` en ambos procesos, o sin definir en ambos: el control compara la identidad completa del modelo y de la política servida.
5. Configurar un worker para las colas `default` y `learning` con `--timeout=1200 --tries=1`, y un `retry_after` mayor, por ejemplo `DB_QUEUE_RETRY_AFTER=1800`. Ejecutar el scheduler cada minuto. `learning:maintain-privacy` se programa siempre, aunque las variables de aprendizaje estén apagadas. No ejecutar workers concurrentes con configuraciones incoherentes.
6. Verificar que el proxy dirige `PUT /profile/learning-consent` a Laravel, con la misma protección exterior que `/profile`, y no a Next. El endpoint aplica sesión, CSRF, limitación de frecuencia, versión de consentimiento y propietario de la sesión.
7. Usar `php artisan learning:assign-company` con evidencias privadas para vincular las cuentas de la misma empresa al mismo grupo seudónimo verificado (véase el runbook). No crear grupos distintos para que una empresa aparezca a la vez en entrenamiento y prueba. El grupo debe concordar también con el de los conjuntos reservados. La exportación incluye una huella de membresía, grupo y evidencia verificada; el registro exige la misma huella en la autorización vigente al registrar, promover y consumir. `learning:assign-company revoke` invalida la autorización local inmediatamente; cada renovación vuelve a leer las membresías y no renueva las revocadas.
8. Informar a las personas usuarias y recoger su consentimiento. Volver a confirmar P8 y las revisiones P9 que se deseen utilizar; la falta de sello no se subsana inventando procedencia histórica. Puede mantenerse `LEARNING_ENGINE_SERVING_ENABLED=false` hasta revisar el primer candidato: sin un puntero promovido, el servicio usa el modelo base en cualquier caso.

## Conjuntos fijos y control de promoción

El operador proporciona `LEARNING_ENGINE_HELDOUT_PATH` y su `LEARNING_ENGINE_HELDOUT_SHA256`. Si dispone de un conjunto gold fuera de muestra, proporciona también `LEARNING_ENGINE_GOLD_PATH` y `LEARNING_ENGINE_GOLD_SHA256`. Los conjuntos no se descubren ni se sustituyen automáticamente. Los archivos quedan fuera de Git, se entregan mediante el mecanismo de secretos del despliegue y tienen esta forma:

```json
{
  "schema": "learning-heldout-v1",
  "unit": "keys",
  "cases": [
    {
      "group": "<clave seudónima del grupo empresarial verificado>",
      "p5_sha256": "<huella del perfil reservado>",
      "input": "<objeto CompanyData, sin nombres reales>",
      "truth": "<objeto: cada clave del modelo servido -> 0, 1 o null si desconocida>"
    }
  ]
}
```

El esquema es ilustrativo, no un conjunto de datos. El conjunto principal usa `unit=keys`. El conjunto gold puede usar `unit=standards` y las claves de normas presentes en el perfil, por ejemplo `E1`, con valores desconocidos `null`; así no se inventan subtemas a partir de una verdad definida por norma. El operador conserva la procedencia y los derechos de los conjuntos reservados y los excluye del entrenamiento. El motor rechaza solapamientos por grupo, huella P5 o vector de entrada normalizado antes del ajuste. Rechaza conjuntos vacíos y ejes incompletos. Sin métricas opcionales también rechaza la ausencia total de positivos; con ellas registra soporte insuficiente y bloquea la promoción. No acredita la ausencia de duplicados si la identidad empresarial externa está mal verificada.

Se ejecutan inferencias reales del modelo base con sus políticas actuales y, si existe, del candidato servido. Para **cada caso y cada positivo conocido**, el nuevo candidato debe conservar todos los aciertos de ambos comparadores: una mejora global no puede ocultar pérdidas por informe. La promoción vuelve a ejecutar la evaluación, exige las mismas huellas de conjuntos que al registrar y comprueba que nadie cambió la selección mientras se evaluaba. Un candidato activo incompatible, caducado o sin autoridad se resuelve con el mismo fallback que en el servicio de propuestas y registra el evento `comparator_fallback`; permite entrenar y evaluar su sustituto conservando la comprobación del puntero original. Si falta el conjunto gold se registra `unavailable`, no `PASS`.

## Operación, promoción y rollback

La operación habitual se realiza desde la sección «Motor de aprendizaje supervisado» del panel `/admin/learning` (véase el runbook). Los comandos equivalentes, para administración con acceso al sistema operativo, se ejecutan desde `app/web`:

```sh
php artisan learning:train
# Revisar learning_engine_runs.receipt y el manifiesto privado del candidato.
php artisan learning:promote <id-candidato> --admin=<id-administrador>
php artisan learning:rollback base --admin=<id-administrador>
php artisan learning:rollback <id-previamente-promovido> --admin=<id-administrador>
php artisan learning:maintain-privacy
```

`--admin` debe figurar en `LEARNING_ENGINE_ADMIN_IDS`. La promoción no cambia variables: solo con `LEARNING_ENGINE_SERVING_ENABLED=true` en el servicio de propuestas se consume el puntero. No existe un endpoint HTTP de promoción o reentrenamiento fuera del panel autenticado. El CLI Python es un protocolo local interno de Laravel y queda bajo el mismo control administrativo del sistema operativo, no accesible a las personas usuarias del producto.

El registro conserva los eventos `registered`, `promoted`, `rollback`, `erased` y `comparator_fallback`, el actor administrativo y la selección anterior. El rollback a `base` funciona con el motor apagado y sin cargar artefactos candidatos. Un rollback a otro candidato requiere promoción previa, bytes íntegros, consentimiento vigente, compatibilidad y modelo base idéntico; no depende de que siga siendo el último lote.

El servicio de propuestas verifica los hashes antes de deserializar, la compatibilidad de código y dependencias, la política base, el mapa vigente de Laravel, la caducidad y la autorización. Ante un fallo vuelve al modelo base; no lo sustituye por un candidato arbitrario. El modelo base debe estar instalado y sano: la variable no puede reparar artefactos ausentes. Una respuesta servida por un candidato usa `model_profile=learning:<id-candidato>`, con `mapping_metadata.serving_identity` y `learning_base_identity`; el eje de claves es el del perfil base.

El modo sombra requiere además `learning_shadow_authorized=true`, que Laravel deriva del consentimiento vigente de la cuenta que solicita la predicción, y `learning_shadow_subject`, el UUID de ese consentimiento, que Python comprueba contra su autorización vigente antes de guardar. Almacena ambos vectores de salida, la versión candidata y el UUID seudónimo; no guarda nombre ni perfil de empresa. La exportación de entrenamiento excluye estos campos. Un fallo del modo sombra no altera la respuesta.

## Protección de datos, retirada y conservación

La base jurídica elegida para esta finalidad opcional es el consentimiento específico, informado, afirmativo y retirable sin pérdida de servicio. La revisión de temas P8 no equivale a un consentimiento jurídico. Esta separación y la retirada por la misma interfaz siguen las [directrices del CEPD sobre el consentimiento](https://www.edpb.europa.eu/sites/default/files/files/file1/edpb_guidelines_202005_consent_en.pdf). El responsable de la instalación debe completar su información de privacidad, encargados, ubicación, derechos, medidas y evaluación de riesgos antes de activarla; esta implementación no certifica el cumplimiento del RGPD ni autoriza tratar datos personales de terceros incluidos por una empresa.

Los UUID se renuevan tras retirar y volver a consentir. Son **seudónimos, no datos anónimos**. La exportación usa solo una lista de campos estructurados y se borra al terminar, incluso tras errores. El mantenimiento elimina las exportaciones abandonadas por trabajadores terminados después de 1200 segundos. Los candidatos caducan a los 30 días; el modo sombra está limitado a 10.000 filas y 30 días. Las ejecuciones de Laravel se purgan a los 30 días. No se guardan conjuntos de datos ni modelos en Git.

Retirar el consentimiento o borrar una cuenta escribe una solicitud durable, elimina los sellos de aprendizaje e intenta quitar inmediatamente la autorización de servicio. La autorización caduca como máximo 90 segundos después del inicio de su lectura de cuentas; sin renovación se usa el modelo base. El mantenimiento, independiente de las variables, borra **todos los modelos derivados del sujeto**, sus manifiestos con sujetos y sus registros en sombra, y desactiva sus punteros. También borra los registros en sombra por el UUID del solicitante, aunque no entrenara ningún candidato. Los tombstones impiden que una petición en vuelo recree esos registros. El mantenimiento migra de forma aditiva los registros anteriores y purga los registros en sombra antiguos sin dueño. Conserva un tombstone opaco para impedir que un trabajo anterior vuelva a registrar el mismo sujeto, y una auditoría de versiones sin datos de filas. No se simula un desentrenamiento.

La eliminación de cuenta no requiere las tablas de aprendizaje anteriores ni una cadena histórica íntegra: un registro incoherente se cierra sin conceder permisos y se anota una advertencia sin valores. La política de copias de seguridad debe aplicar la misma eliminación; restaurar una copia exige reconciliar las retiradas antes de renovar autorizaciones.

## Monitorización y pruebas

Vigile `learning_engine_runs` (`running`, `registered`, `failed_closed`, `no_eligible_feedback`), los fallos de la cola `learning`, la antigüedad de `learning_erasure_requests`, la edad de `authority.json`, el espacio del registro y los mensajes `learning.registry_unavailable_using_base`, `learning.shadow_unavailable` y `learning.erasure_incoherent_ledger`. Una solicitud de borrado no consumida es una incidencia operativa aunque el fallback ya proteja el servicio. La caducidad de un modelo puede provocar el fallback sin entrenamiento nuevo: no la renueve manualmente.

El intérprete de los lanzadores es `LEARNING_ENGINE_PYTHON`; sin esa variable se usa la ruta exacta `app/ai-service/.venv-learning/bin/python` (`Scripts/python.exe` en Windows), sin segmentos `..` ni sustitución por un `python3` del `PATH`. Si falta una dependencia, el proceso hijo registra `learning_batch.dependency_missing:<módulo>` para una lista cerrada de módulos, sin imprimir rutas, entradas, entorno ni trazas.

```sh
# app/ai-service, con el entorno de aprendizaje:
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_learning_production*.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_learning_batch_launch.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_service_predict.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_masked_multilabel.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_feature_encoding.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_feedback_bandit*.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_public_learning_wiring.py'
# app/ai-service, runtime público con y sin los módulos de aprendizaje (pytest se instala aparte):
PYTHONDONTWRITEBYTECODE=1 .venv-learning/bin/python -m pytest -q -p no:cacheprovider tests/test_public_learning_hooks.py
# app/web:
php artisan test --filter=LearningEngineProduction
php artisan test --filter=LearningAccountDeletion
php artisan test tests/Unit/LearningEngineInterpreterConfigTest.php
# app/frontend:
node --test tests/*.mjs
node_modules/.bin/tsc --noEmit --incremental false -p .
```

Las pruebas usan datos sintéticos y un comparador sustituido explícitamente para verificar la mecánica, sin cargar un modelo privado. `learning-consent-proxy.test.mjs` ejercita el transporte HTTP real de los rewrites de Next contra un receptor Laravel simulado: concesión y retirada conservan el método, la cookie de sesión, el token CSRF y las cookies de respuesta. No sustituye una sesión de navegador real. `LearningEngineInterpreterConfigTest` comprueba la configuración del intérprete en un proceso PHP separado, con y sin una ruta explícita que contiene espacios.


## Métricas del control de promoción

`LEARNING_GATE_METRICS_ENABLED=false` conserva la evaluación y presentación anteriores. Este indicador no se activa por `APP_ENV=production|staging` ni por `LEARNING_DEPLOYED`. Activarlo no inicia entrenamiento ni promoción. Antes de usar datos reales, el responsable aprueba la población de evaluación, las etiquetas independientes, las membresías reservadas y la política predeclarada.

### Configuración explícita

| Variable | Predeterminado | Validación |
|---|---:|---|
| `LEARNING_GATE_METRICS_ENABLED` | `false` | Activación explícita, también en entornos desplegados |
| `LEARNING_GATE_MIN_COMPANIES` | 30 | Entero, 1–1.000.000 |
| `LEARNING_GATE_MIN_POSITIVES` | 30 | Entero, 1–1.000.000 |
| `LEARNING_GATE_MIN_NEGATIVES` | 30 | Entero, 1–1.000.000 |
| `LEARNING_GATE_PRECISION_DELTA` | 0.01 | Finito, 0 ≤ valor < 1 |
| `LEARNING_GATE_REPLICATES` | 2000 | Entero, 2–10.000 |
| `LEARNING_GATE_CONFIDENCE` | 0.95 | Finito, 0 < valor < 1 |
| `LEARNING_GATE_SEED` | 17 | Entero, 0–2³²−1 |
| `LEARNING_SERVING_RUNTIME` | `public` | Único runtime admitido en esta edición |

Son valores conservadores de política, no resultados ni garantía de potencia estadística. Laravel pasa los valores al subproceso con un entorno limitado. El adaptador carga perezosamente el mismo `services` que FastAPI: el alias de `src/services` apunta a `public_runtime/services` en una instalación nativa. En la imagen, el paquete público se instala directamente como `services`. `CompanyData`, las predicciones y los hashes usan esos mismos archivos; un fallo de importación o predicción no selecciona otro runtime.

Los hashes incluyen los módulos de métricas, el adaptador, la proyección de claves a normas y el [contrato de métricas](../contracts/api/learning-gate-metrics-v1.schema.json). Actualizar código o contrato invalida candidatos anteriores, incluso con el indicador apagado. La imagen incluye una copia byte a byte del contrato en su contexto de construcción; el aprendizaje en contenedores sigue sin estar soportado y permanece apagado.

### Empresas reservadas y protocolo previo al ajuste

El operador proporciona los conjuntos `LEARNING_ENGINE_HELDOUT_PATH/SHA256` y, opcionalmente, `LEARNING_ENGINE_GOLD_PATH/SHA256` descritos arriba. Cada archivo tiene como máximo 32 MiB, ruta absoluta sin enlaces y un hash de sus bytes originales. Un conjunto gold configurado pero ausente también bloquea el trabajo. Se requiere una fila por grupo empresarial, ejes completos y etiquetas enteras 0/1 o `null` desconocido. El conjunto principal usa claves canónicas; gold puede usar normas con verdad explícita. Nunca se deducen negativos de temas a partir de la verdad por norma.

`LearningReservedGroups::snapshot()` congela la unión de grupos antes de exportar. Excluye todas las cuentas con membresía verificada en esos grupos antes de extraer sus revisiones. `reserved_exclusions` queda en el recibo del trabajo, separado del esquema estricto de entrenamiento. Una exportación sin filas no ajusta ningún modelo. Los archivos se vuelven a verificar antes del ajuste; Python conserva los controles independientes de grupo, huella del perfil y características normalizadas.

La tabla SQLite idempotente `gate_protocols` guarda un protocolo inmutable por SHA-256 antes del ajuste: política, hashes de conjuntos y entrenamiento, ejes, comparador seleccionado y efectivo, identidad completa de servicio, hashes de código y hora de creación. No requiere migración Laravel. La identidad pública vincula artefactos, umbral y política de retroalimentación; los controles no disponibles en esta edición siguen declarados inactivos. Solo se usa el comparador activo si el servicio de candidatos está habilitado y `effective_active` lo acepta; en caso contrario se compara con el modelo base.

El protocolo congela también un `decision_id` opaco por caso, derivado del hash del conjunto, su nombre y la posición del caso. La política de retroalimentación reutiliza esas decisiones durante la evaluación y la reevaluación para promoción. Su semilla y estado siguen vinculados a la identidad completa de servicio. El contexto se restaura incluso si hay una excepción; las propuestas ordinarias conservan sus decisiones aleatorias. Cambiar las decisiones invalida el protocolo.

### Recibos, criterio y límites

Los recibos `learning-gate-metrics-v1` incluyen `protocol_sha256`, `policy`, `report`, `primary`, `zero_positive_loss` y `failure_reasons`, conservando los campos históricos de sensibilidad. Los recibos antiguos no se reescriben y no permiten nuevas promociones con el indicador activado.

Para base, comparador efectivo y candidato se calculan TP/FP/FN/TN, soporte positivo, negativo y desconocido, y precisión, sensibilidad y F1, micro y por clave. Un denominador cero produce `null` y un motivo, nunca un cero medido. Las predicciones quedan fijadas antes del bootstrap: se remuestrean empresas completas con reemplazo, usando los mismos índices para los tres predictores. Los intervalos percentiles son bilaterales y emplean cuantiles NumPy `linear`. Con menos del 95 % de réplicas válidas el intervalo no está disponible; no se repite el sorteo para buscar un resultado favorable.

El único criterio primario es la **precisión micro por clave en el conjunto reservado**. El límite inferior del intervalo de la diferencia candidato menos comparador debe ser **estrictamente mayor** que el umbral declarado. Debe haber suficiente soporte y conservarse cada positivo conocido propuesto por el modelo base **o** el comparador, en cada empresa de todos los conjuntos. Un empate falla. Los resultados por tema y gold son descriptivos y no sustituyen el criterio primario. El soporte insuficiente o la precisión o el intervalo primarios indefinidos producen `insufficient_evidence`.

La promoción manual reproduce el protocolo guardado y vuelve a evaluar. Cambios de política, conjuntos o comparador requieren un nuevo candidato y protocolo. Se comprueba la identidad durante la evaluación y se mantiene la comprobación transaccional del puntero activo. La reversión al modelo base sigue disponible con el aprendizaje desactivado.

### Administración y pruebas

El panel `/admin/learning` muestra métricas agregadas y detalle por tema en castellano e inglés. Se mantienen sesión, CSRF, administradores con correo verificado y los límites de 20 lecturas y 5 acciones por minuto. `GET /api/admin/learning` admite un único `candidate` hexadecimal de 32 caracteres para consultar un candidato, incluso fuera de los treinta recientes. Un candidato desconocido o borrado devuelve 404. La promoción consulta también ese identificador individual y el CLI vuelve a comprobar la elegibilidad al ejecutar la acción.

La respuesta contiene agregados y claves canónicas, sin grupos, perfiles de entrada, etiquetas de referencia, sujetos ni rutas. El puente conserva el límite de 1 MiB: bloquea resultados excesivos sin truncarlos. El panel distingue evaluación histórica, elegibilidad actual y configuración local; esas métricas no verifican el servicio remoto. Consentimiento, caducidad, borrado y vigencia de las revisiones siguen siendo obligatorios. Las respuestas del informe aportan procedencia, nunca verdad de doble importancia relativa.

Las pruebas sintéticas comprueban la aritmética, la repetibilidad y los controles; no acreditan mejora con empresas reales. Ejecute además de las pruebas anteriores:

```sh
# Desde app/ai-service, con el entorno de aprendizaje:
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_learning_gate_metrics.py'
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src .venv-learning/bin/python -m unittest discover -s src/tests -p 'test_learning_serving_adapter.py'
# Desde app/web:
php artisan test --filter='LearningEngineProduction|LearningActivation'
# Desde app/frontend:
node --test tests/*.mjs
pnpm typecheck
pnpm lint
pnpm build
```
