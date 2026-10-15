# Sugerencias de conceptos XBRL

Matcher offline de indicadores IG-3 hacia los QNames ya disponibles en el mapa estático de Laravel. No descarga taxonomías ni llama a servicios externos. No modifica el modelo servido de P6 ni activa aprendizaje con datos empresariales. No requiere dependencias nuevas: usa scikit-learn, NumPy y SciPy.

## Construcción y evaluación offline

Desde `app/ai-service`, con el entorno Python del proyecto:

```sh
PYTHONDONTWRITEBYTECODE=1 OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 python scripts/xbrl_mapping/matcher.py
```

Entradas: `app/web/data/esrs_datapoints_ig3.json` y `app/web/data/atomizer_xbrl_concepts_v1.json` (opciones `--ig3` y `--concept-map`). Los nombres locales se separan por camel case y acrónimos; el mapa no contiene etiquetas oficiales. El texto del indicador usa `name`, `description` cuando existe, y `dr`; el IG-3 actual no tiene campo `description`. No se inventan etiquetas ni descripciones.

TF-IDF de palabras (unigramas y bigramas) y de caracteres (`char_wb`, 3–5), coseno, solapamiento de tokens y producto de similitudes. La recuperación combina el coseno de palabras y caracteres con priors positivos de DR y norma. La regresión logística aprende las similitudes con positivos conocidos, negativos difíciles y negativos aleatorios, con semilla fija. Los priors de DR y norma se añaden como ajustes positivos fijos de log-odds al reranker; no se aprenden como atajos basados en la etiqueta positiva de entrenamiento. Todos los pesos y parámetros se registran en el informe de evaluación.

Validación cruzada agrupada por QName: todos los indicadores que comparten un concepto quedan en el mismo fold. Cada correspondencia se puntúa una vez contra todo el inventario fijo de conceptos, sin pares positivos compartidos entre entrenamiento y prueba. El inventario candidato permanece disponible; ningún texto de un indicador retenido se usa para ajustar el TF-IDF. Las asociaciones de DR y norma se calculan solo con el entrenamiento. La comparación por DR no es aplicable a conceptos completamente retenidos, porque no existe un metadato DR independiente en estos activos, y se elimina ese baseline de la evaluación de descubrimiento por QName.

Un protocolo **separado de reutilización por DR** agrupa con GroupKFold el texto normalizado completo del indicador (los textos idénticos nunca cruzan folds). Otros indicadores de entrenamiento pueden describir el mismo concepto. El prior DR binario solo usa correspondencias de entrenamiento; los empates se resuelven por QName. Se comparan DR, coseno y reranker en el mismo subconjunto: consultas cuyo concepto tiene una asociación con ese DR en el entrenamiento. La verdad retenida se usa solo para declarar la aplicabilidad y calcular métricas, nunca para ajustar el modelo. El informe publica asignaciones, cobertura y exclusiones. Esta comparación no se extrapola a indicadores nuevos sin asociación DR conocida. La prueba sintética `test_dr_only_retrieves_concept_from_an_independent_training_mapping` demuestra una recuperación correcta por DR usando otro indicador de entrenamiento.

Ambos protocolos incluyen Top-1, Top-5 y MRR, asignaciones, versiones y hashes. Son evaluaciones exploratorias de desarrollo, sin un conjunto de evaluación independiente reservado.

Salidas:

- `app/web/data/xbrl_concept_suggestions_v1.json` (versionada): candidatos ordenados por el reranker, con ID `DATAPOINT::RANK`, versión, métricas y estado `pending_review`. Volver a generarla cambia sus bytes y, con ello, caduca las aceptaciones registradas. Su campo `model_version` es un identificador técnico estable de la receta del matcher; se conserva tal cual y no describe un estado de aprobación.
- `app/ai-service/artifacts/xbrl-concept-mapping-eval.json` y `.md`: informe de evaluación local, titulado «Evaluación offline de sugerencias de conceptos XBRL (<fecha>)». `app/.gitignore` excluye `ai-service/artifacts/`; no lo versione.

La confianza es una probabilidad de par **sin calibrar**, ajustada por prior y estimada con negativos muestreados; no equivale a la probabilidad de que el concepto sea correcto. No se almacena un pickle ni se evalúan indicadores sin verdad conocida. Las métricas del archivo versionado no son resultados con personas usuarias reales.

## Revisión offline en Laravel y activación

Artisan es una herramienta de operador bajo la autoridad del sistema operativo; no hay un endpoint HTTP nuevo ni acceso desde Next o Python. Restrinja el acceso al comando y al almacenamiento privado a operadores autorizados. Use un alias institucional, sin nombre, correo ni datos personales; el alias lo declara el operador y no es una autenticación de Laravel. `--list` no escribe decisiones. Desde `app/web`:

```sh
php artisan xbrl:concept-suggestions --list
php artisan xbrl:concept-suggestions --accept='BP-2_01::1' --reviewer=equipo-revisor
php artisan xbrl:concept-suggestions --reject='BP-2_01::1' --reviewer=equipo-revisor
```

El ejemplo muestra la sintaxis; no aprueba ese candidato. Revise concepto, dimensiones, unidad, tipo y periodo contra la taxonomía externa autorizada antes de aceptar. Cada aceptación o rechazo añade al historial la fecha UTC real, el alias, el QName, la versión y el hash exacto del archivo de sugerencias. Solo una sugerencia vigente por indicador puede aceptarse; rechace la anterior antes de aceptar otra. Un rechazo posterior revoca la aceptación. El historial se serializa mediante `flock` y se publica por reemplazo atómico de un archivo temporal privado. Conserve copias del archivo de decisiones y no lo edite a mano.

Configuración: `app/web/config/xbrl_suggestions.php`.

- `XBRL_SUGGESTION_OVERLAY_ENABLED=false` por defecto. Las decisiones pueden revisarse y registrarse con la variable apagada, pero no cambian el mapa.
- Para activarla, establezca `XBRL_SUGGESTION_OVERLAY_ENABLED=true` en la configuración privada y renueve la caché de configuración de Laravel.
- Overlay predeterminado: `storage/app/private/xbrl-concept-decisions.json`. Debe persistirse en almacenamiento privado compartido entre releases y nunca en Git.
- `XbrlConceptMap` consulta únicamente aceptaciones válidas para indicadores sin correspondencia estática. Las sugerencias pendientes o rechazadas y los IDs desconocidos no alteran el mapa. Las correspondencias estáticas se conservan.
- Se validan los hashes de fuente y el inventario de QNames. Cambiar cualquier byte del archivo de candidatos caduca las aceptaciones anteriores, y `--list` muestra esa caducidad. Revise de nuevo después de regenerar candidatos y actualice los activos offline fuera de las operaciones de revisión concurrentes.
- Los datos corruptos o las aceptaciones en conflicto bloquean la lectura habilitada. Cada operación captura una revisión inmutable del mapa; las consultas, la versión y el SHA-256 usan esa misma captura aunque otro operador decida durante el renderizado.
- Con la variable activa, cada snapshot guarda el mapa efectivo completo (QNames, estados, versión y SHA-256) en `source_manifest.xbrl_mapping`. El manifiesto queda incluido en `snapshot_hash` y en la aprobación. Las exportaciones aprobadas y el renderizador iXBRL usan exclusivamente esa captura, aunque el overlay cambie, se corrompa o se apague la variable. La integridad rechaza cambios del mapa congelado.
- Los snapshots creados sin captura conservan el mapa estático; habilitar después la variable no incorpora sugerencias a una aprobación antigua. Cree y apruebe un snapshot nuevo para adoptar decisiones nuevas. Las decisiones no invalidan ni reescriben snapshots anteriores. Apagar la variable afecta solo a los snapshots y previsualizaciones nuevos; las aprobaciones con captura conservan su mapa.
- La detección de obsolescencia por caracterización, hechos o perfil no vuelve a leer las decisiones: una exportación aprobada no depende del estado operativo del overlay.

## Pruebas y límites del matcher offline

```sh
# app/ai-service
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_xbrl_mapping*.py'
# app/web
php artisan test --filter='XbrlConceptMapTest|XbrlConceptSuggestionsTest|ReportIrBuilderTest|ReportSnapshotBuilderTest|XhtmlIxbrlCandidateRendererTest'
```

Se usa `--filter` porque `ReportIrBuilderTest` depende de helpers compartidos definidos en otros archivos de prueba del informe, que así se cargan.

Las pruebas Python cubren tokenización, características, aislamiento de folds, priors, métricas, empates, la reconciliación del archivo de sugerencias con las entradas actuales y los candidatos pendientes. `test_local_evaluation_report_reconciles_to_payload` compara además el informe local con el archivo versionado; se omite (skip) mientras no exista `app/ai-service/artifacts/xbrl-concept-mapping-eval.json`, por ejemplo en un checkout limpio. Las pruebas de Laravel cubren la variable apagada, aceptación, revocación, auditoría, conflictos, fuente o candidato alterados, corrupción y opciones de Artisan, la captura entre consultas y el recorrido aprobación → aceptación o rechazo → exportación HTML/iXBRL, la compatibilidad con snapshots anteriores y la integridad del mapa congelado.

Solo se sugieren conceptos presentes en el mapa vendorizado, que es una referencia indirecta y puede contener errores. Las correspondencias muchos a uno, las dimensiones, `datatype`, `periodType`, las unidades, la conformidad normativa y la validación iXBRL real quedan fuera de esta función. La función no ejecuta Arelle ni consulta o redistribuye la taxonomía externa de EFRAG.


## Identificación en ejecución

El modelo persistido identifica conceptos candidatos a partir de descripciones nuevas. La revisión de datos normativos y el borrador del informe ofrecen un panel con indicadores predefinidos, hechos vinculados o descripciones libres. Los textos iniciales usan el idioma activo; el texto de usuario se conserva sin traducir. La aceptación registra una identificación revisada: no modifica el mapa global, los hechos, la doble importancia relativa ni el etiquetado del informe. Las revisiones no entrenan el modelo.

La lógica compartida reside en `public_runtime/services/xbrl_concept_matcher.py`; el alias `src/services` permite usarla también desde la herramienta offline. `public_model_app.py` expone `POST /xbrl/concept-candidates`. La imagen pública incluye los módulos mediante su copia de `public_runtime`; no incluye modelos de identificación ni catálogos externos.

### Construcción del modelo

Desde `app/ai-service`, use un entorno con las mismas versiones exactas de Python, NumPy, SciPy y scikit-learn que el proceso de inferencia. Configure `XBRL_BUILD_OUTPUT` con una ruta absoluta nueva fuera del repositorio y use entradas autorizadas:

```sh
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 \
python scripts/xbrl_mapping/train_runtime.py \
  --ig3 ../web/data/esrs_datapoints_ig3.json \
  --concept-map ../web/data/atomizer_xbrl_concepts_v1.json \
  --output-dir "$XBRL_BUILD_OUTPUT" \
  --model-version version-aprobada \
  --min-score 0.5 --min-margin 0.05
```

El directorio debe ser nuevo. JSON y arrays NumPy/SciPy sin pickle conservan vocabularios, IDF, vectores, coeficientes, tokens y asociaciones de requisitos de información y normas. El manifiesto registra versión, revisión del código, fecha UTC, dependencias, características, parámetros, hashes de entradas y archivos, idiomas, perfil/paquete y umbrales. Los hashes de los módulos públicos y `source_dirty` identifican también construcciones con cambios locales.

Los hashes usan **bytes exactos**, incluidos CRLF. El corpus y el mapa deben coincidir con los bytes que valida Laravel; no normalice finales de línea ni sustituya hashes para sortear un desacuerdo. No versione modelos generados, manifiestos de instalación ni catálogos externos.

La carga rechaza versiones, dimensiones, tamaños, valores no finitos y hashes inválidos. La inferencia no llama a `fit()`. Cada proceso carga una sola versión; para sustituirla se reinicia el servicio. Aprovisione el directorio como solo lectura para el proceso y conserve la versión anterior en almacenamiento privado externo al repositorio.

Sin catálogo, el inventario procede del mapa de conceptos y solo admite inglés, porque el texto IG-3 y los nombres locales del entrenamiento son ingleses. Una descripción española produce `unsupported_language`. El idioma del texto se elige explícitamente en el panel; no se traduce para el modelo.

Para habilitar etiquetas o descripciones adicionales, añada `--taxonomy-catalog "$XBRL_BUILD_CATALOG"` con un archivo externo autorizado. Su esquema cerrado contiene `schema_version="xbrl-runtime-identification-v1"`, `taxonomy_profile`, `package_sha256`, `languages` (`es`/`en`) y `concepts` por QName. Cada entrada tiene `labels`, `descriptions`, `type` expandido como `{namespace}localName`, `period_type` y `abstract`. Exige etiquetas no vacías en cada idioma declarado, un catálogo de hasta 16 MiB y un inventario de hasta 20.000 conceptos. Laravel contrasta existencia, tipo, periodo y abstracción con el paquete externo verificado por checksum, sin resolver taxonomías por red. Un tipo desconocido sigue sin verificar. Ampliar el inventario requiere una evaluación propia.

### Configuración, revisión y reversión

| Variable | Proceso | Predeterminado | Uso |
|---|---|---|---|
| `XBRL_RUNTIME_IDENTIFICATION_ENABLED` | Laravel y Python, independientemente | `false` | Habilita la identificación; no hereda la activación del aprendizaje |
| `XBRL_RUNTIME_MANIFEST_PATH` | Laravel | sin valor | Manifiesto de la misma versión servida por Python |
| `XBRL_RUNTIME_CATALOG_PATH` | Laravel | sin valor | Catálogo externo opcional, idéntico al usado en la construcción |
| `XBRL_RUNTIME_MODEL_DIR` | Python | sin valor | Ruta absoluta al directorio del modelo |

Laravel reutiliza `CHARACTERIZATION_API_BASE_URL` y `CHARACTERIZATION_API_TOKEN`; Python exige `I4S_AI_API_TOKEN` con el mismo secreto. El cliente solo permite HTTP en loopback numérico, sin proxy, redirecciones ni reintentos. Mantenga el servicio ligado a loopback en una instalación nativa. La topología de contenedores incluida usa una dirección de servicio entre contenedores, que este cliente rechaza: incluir los módulos en la imagen no habilita por sí solo esa topología.

Aplique la migración aditiva después de una copia de seguridad, renueve la caché de configuración y reinicie los procesos siguiendo el procedimiento de operación. Active ambas banderas solo con el modelo y, si corresponde, el paquete y catálogo autorizados disponibles. Apagadas, las rutas devuelven 404 y Python no carga los artefactos.

El [contrato de identificación](../../contracts/api/xbrl-runtime-identification-v1.md) describe las rutas, límites y esquemas. La sesión y el servidor determinan propietario, revisor y fecha UTC. Los botones de aceptar/rechazar requieren un motivo y no preseleccionan una decisión. El panel descarta respuestas tras cambios de entrada y ofrece historial paginado. Solo puede mantenerse una aceptación por indicador; se rechaza la anterior antes de aceptar otra.

Las puntuaciones son **puntuaciones de ordenación sin calibrar**. El modelo se abstiene por idioma/contexto no admitido, falta de coincidencia textual, ausencia de candidatos compatibles, puntuación baja o margen insuficiente. Los umbrales pertenecen al modelo, no al navegador. Laravel deriva tipo y unidad de los hechos vinculados, rechaza contradicciones y vuelve a validar candidatos, identidades y umbrales. Tras filtrar conceptos incompatibles, vuelve a aplicar puntuación y margen mínimos; conserva la respuesta original en `service_response`.

Sin taxonomía configurada, la validación se declara `map_only`. Una taxonomía configurada pero inválida bloquea la operación. Los estados `unverified` e `incompatible`, y cualquier abstención, impiden aceptar. Los cambios de contexto o revisiones concurrentes devuelven 409. La identificación no envía identidad de cuenta, valores factuales ni documentos a Python.

Las instantáneas nuevas congelan las identificaciones revisadas, sus entradas, resultados e historial en `source_manifest.xbrl_identifications`, con un máximo de 1.000 registros revisados. La evidencia pasa a la representación del informe y al paquete de evidencias. Las revisiones posteriores requieren una nueva instantánea y aprobación; la comprobación de evidencia histórica no consulta el modelo ni el catálogo vivo. Las instantáneas previas siguen siendo válidas. Los registros privados se eliminan en cascada con la cuenta o caracterización.

Para desactivar, apague la bandera en ambos procesos y renueve configuración/reinicie. Conserve las tablas e instantáneas. Para recuperar una versión anterior, aprovisione el modelo y su manifiesto/catálogo coincidentes y reinicie Python. Las aceptaciones obsoletas pueden rechazarse sin volver a instalar su modelo.

### Pruebas y evaluación

```sh
# app/ai-service
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_xbrl*.py'
python -B -m pytest -q tests
# app/frontend
node --test tests/*.mjs
pnpm typecheck
pnpm lint
pnpm build
# app/web: carga también los helpers compartidos del informe
php artisan test --filter='XbrlRuntime|ReportSnapshotBuilderTest|ReportIrBuilderTest|XhtmlIxbrlCandidateRendererTest'
```

Las pruebas sintéticas verifican persistencia, inferencia, revisión e integridad; no acreditan precisión con indicadores empresariales. La evaluación independiente requiere etiquetas de especialistas ajenas al mapa de entrenamiento y criterios de abstención predefinidos. Mida Top-1, Top-5, MRR, cobertura, abstención y errores por idioma y modo de catálogo. Separe recuperación de candidatos y precisión en los casos contestados. Las métricas del matcher offline no se atribuyen a este uso.

La función no ofrece etiquetado automático, semántica de composición o dimensiones, nuevas vinculaciones factuales, cobertura completa, promoción de modelos, aprendizaje de revisiones ni conformidad regulatoria. La cualificación, el aprovisionamiento y la comprobación de la instalación son operaciones separadas.
