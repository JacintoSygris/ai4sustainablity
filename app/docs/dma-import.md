# Importación del ADM a P8

Importa el registro IRO cumplimentado de las plantillas P7 y, opcionalmente, el registro de consultas. Laravel valida, guarda y agrega los datos por ID AR16. El panel «Resultados importados del ADM» permite aplicar cada sugerencia al borrador P8. **Nunca confirma automáticamente**: la confirmación existente mantiene su revisión optimista, sus requisitos y la explicación de exclusión de E1.

## Activación

`DMA_IMPORT_ENABLED=false` por defecto, en `config/services.php`. Antes de habilitarlo, ejecute la migración `2026_10_08_000001_create_dma_imports_table.php` (`php artisan migrate --force`) y actualice la caché de configuración. Establezca `DMA_IMPORT_ENABLED=true` únicamente en el entorno autorizado. El frontend obtiene la capacidad de Laravel; no necesita otra variable ni una compilación específica. Con la variable apagada, los endpoints devuelven 404 y el panel no se monta. La evidencia ya confirmada sigue disponible en los paquetes autenticados.

## Entrada y sugerencias

CSV UTF-8 (BOM opcional), con coma o punto y coma, encabezados completos de las plantillas P7 en castellano o inglés, o sus claves canónicas; se pueden reordenar. Admite celdas entrecomilladas, comillas escapadas y saltos de línea. Límites por archivo: 1 MiB, 1000 filas de datos y 2000 bytes por celda. Extensión `.csv` y MIME de texto/CSV; máximo 20 importaciones por caracterización. La carga es atómica: cualquier error invalida el conjunto y devuelve 422 con claves como `iro_file.row.2.ar16_topic_id`. Los números de fila son las líneas físicas de inicio, con el encabezado en la línea 1.

Registro IRO:

- ID AR16 existente, descripción obligatoria y tipo `impacto/riesgo/oportunidad` o `impact/risk/opportunity`.
- Puntuaciones: números no negativos hasta 1000000000, con punto o coma decimal y sin separadores de miles; o indicadores `material/no material`, `no_material/not material`, `sí/si/yes/no`, `true/false`; o vacío. La cota numérica es técnica, no normativa.
- Resultado frente al umbral: `material`, `no material/no_material/not material`, `revisar/review` o vacío. Vacío exige revisión.
- Un IRO material basta para sugerir material; si todos son no materiales, sugiere no material. Si quedan pendientes y ninguno es material, sugiere revisar. Una contradicción entre el resultado y los indicadores explícitos exige revisión. Se conservan puntuaciones, indicadores y filas de apoyo; no se aprende ni se inventa un umbral ni se comparan escalas.
- La dimensión solo se propone a partir de indicadores positivos explícitos de los IRO materiales. Las puntuaciones numéricas no establecen una dimensión. Las cuatro señales de P8 no se fabrican.

Consultas: fecha `AAAA-MM-DD` válida y no futura; grupo, fuente y resumen obligatorios. En «Tema o IRO afectado» se usa el ID AR16, `AR16:ID` o `IRO:número-de-fila-CSV` del registro. No se infieren enlaces del texto libre. Se cuentan las consultas y los apoyos u objeciones cuyo efecto declare explícitamente `material/no material`; el texto restante se conserva como evidencia. Las consultas no sustituyen el resultado del IRO ni confirman una decisión.

## API, trazabilidad y seguridad

Rutas bajo la sesión Laravel, con CSRF para escritura, autenticación y verificación de cuenta y comprobación de propietario:

- `POST /api/characterizations/{id}/dma-imports`: multipart con `iro_file` obligatorio y `stakeholder_file` opcional; 5 solicitudes por minuto; respuesta 201.
- `GET /api/characterizations/{id}/dma-imports`: última importación propia o `null`; 30 por minuto.
- `GET /api/characterizations/{id}/dma-imports/{import}/iro.csv`: reexportación IRO con neutralización de fórmulas en todas las celdas; 10 por minuto.
- `PUT /api/materiality-confirmation`: añade el mapa opcional `dma_import_refs` (`ID AR16 -> ID de importación`). Laravel resuelve las referencias contra importaciones del mismo propietario y caracterización y contra los temas del universo revisado. El cliente no puede enviar evidencia arbitraria. Solo el guardado explícito confirma P8. El contrato OpenAPI v0 (`app/contracts/api/frontend-characterization-openapi-v0.json`) todavía no describe este campo ni las rutas de importación; esta guía es la referencia de la API del módulo.

La tabla `dma_imports` conserva filas, sugerencias, nombre original, SHA-256 de cada archivo, tamaño, fecha y usuario. No almacena archivos ejecutables ni llama a Python o a servicios externos. El texto original se conserva sin traducción y React lo escapa. La reexportación antepone un apóstrofo a las fórmulas, incluso con espacios, tabuladores o BOM delante.

Aplicar una sugerencia cambia únicamente el borrador: selección, razones `new_data/threshold`, dimensión cuando se conoce y referencia de importación. Elimina la respuesta guiada anterior de ese tema para evitar contradicciones, conserva los demás temas y deja pendiente la revisión global. Se pueden aplicar temas de importaciones distintas. La recuperación guarda las referencias junto con el borrador validado por revisión y por la propuesta P6.

Un borrador validado contiene el mapa completo de dimensiones y tiene autoridad al recargar, aunque el mapa esté vacío. Una dimensión eliminada porque el IRO no la determina no se recupera del servidor. Sin borrador válido se utiliza la confirmación del servidor; una revisión incompatible sigue enviando el borrador a recuperación.

La confirmación congela la evidencia utilizada en `materiality_confirmation.dma_import_evidence`, con `basis=imported_adm`, IDs, procedencia, sugerencias y filas. Se incluye en la hoja de decisión, en el paquete de evidencias ordinario y en el de la versión aprobada de P10, y forma parte del hash de la versión. La importación no incorpora por sí sola el texto de IRO y consultas al HTML, DOCX, XML ni XHTML del informe. Las importaciones se eliminan en cascada al eliminar la caracterización o la cuenta desde el producto.

## Contexto IRO en la presentación del informe

La presentación compartida de informes con integridad del alcance conserva el contexto IRO revisado cuando la instantánea aprobada contiene `iro_context_v1`. HTML, DOCX y XHTML de presentación muestran el mismo bloque después de los avisos y antes de los capítulos: descripción revisada, identificadores IRO y AR16, enlaces a datos normativos, SHA-256 de origen, fila y revisión P8. Las etiquetas siguen el idioma activo; el texto de la persona usuaria y los identificadores se conservan. Cada formato escapa el contenido y la proyección no modifica los hechos, las evidencias ni el hash aprobado. Si no hay tarjetas de contexto, no se añade el bloque.

Esta compatibilidad de presentación requiere que la integración aporte el contexto revisado a la instantánea. El backend de esta edición todavía no genera `iro_context_v1`; habilitar `DMA_IMPORT_ENABLED` o `REPORT_SCOPE_INTEGRITY_ENABLED` no completa ese flujo. La proyección no convierte las filas importadas en hechos ni modifica las obligaciones NEIS.

## Pruebas y límites

Frontend, desde `app/frontend`: `node --test tests/*.mjs` y `node_modules/.bin/tsc --noEmit --incremental false -p .`. Cubren correspondencias, referencias, recuperación real, la variable de activación, localización y controles accesibles.

Laravel, desde `app/web`: `php artisan test tests/Feature/Api/DmaImportApiTest.php tests/Feature/Api/ReportApiTest.php`. El segundo archivo carga los helpers compartidos de preparación del informe. Cubren castellano e inglés, delimitadores, formato y cotas, errores por fila, fórmulas, autenticación e IDOR, límites de ruta, enlace de consultas, agregación conservadora, referencias P8, revisión, borrado y evidencia de versiones aprobadas.

Presentación, desde `app/web`: `php artisan test tests/Unit/Report/ReportPresentationParityTest.php`. Comprueba con datos sintéticos el orden y la conservación del contexto revisado en HTML, DOCX y XHTML, en castellano e inglés, el escape del texto y la ausencia de cambios cuando no hay contexto.

No se admiten XLSX ni PDF ni se leen matrices gráficas. La importación no confirma decisiones automáticamente ni constituye por sí misma una evaluación normativa completa.


## Análisis local de textos IRO

El análisis opcional propone temas AR16, requisitos de información (DR) e indicadores IG-3 a partir de las descripciones originales de los IRO. Cada nivel se puntúa por separado con recuperación TF-IDF de palabras y caracteres, con igual peso, y devuelve hasta tres candidatos. Los indicadores se comparan directamente con el texto del catálogo; no se generan ampliando un tema mediante el mapa normativo. El mapa aprobado relaciona únicamente candidatos ya recuperados y comprueba su compatibilidad.

Cada propuesta muestra identificador, etiqueta en el idioma activo, similitud, cita literal, IRO de origen y estado de revisión. La similitud no es una probabilidad y la cita es apoyo léxico, no verificación de un hecho. La negación, la incertidumbre y la evidencia insuficiente provocan abstención conservadora. Las descripciones de la persona usuaria nunca se traducen.

### Configuración y modelo

`DMA_NLP_ENABLED=false` en Laravel y `I4S_DMA_NLP_ENABLED=false` en Python son controles independientes, apagados por defecto. El flujo completo requiere importación ADM y trazabilidad IRO revisada (`DMA_IMPORT_ENABLED` e `IRO_TRACEABILITY_ENABLED`), además de la migración `2026_10_09_200003_create_dma_nlp_tables.php`. No se activa por el entorno ni por habilitar aprendizaje.

El modelo se ajusta exclusivamente con los catálogos públicos `esrs_topics.json`, `esrs_datapoints_ig3.json` y `esrs_datapoint_labels_es_v1.json`. El manifiesto público `web/data/dma_nlp_model_v1.json` registra sus SHA-256; Laravel y Python fijan la identidad de ese manifiesto. No contiene importaciones, textos de usuarios ni datos de empresas. Los activos y sus fuentes requieren revisión de derechos antes de distribuirlos.

Desde `app/ai-service`, genere un paquete en un directorio externo de su elección. `DMA_MODEL_DIR` y `DMA_EVALUATION_FILE` representan rutas absolutas externas proporcionadas por el operador:

```bash
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python scripts/dma_processing/build_model.py --output-dir "$DMA_MODEL_DIR"
```

El paquete contiene `model.json`, `manifest.json` y `catalogues/`. Configure `I4S_DMA_NLP_MODEL_DIR` con ese directorio en el proceso Python; en un contenedor, monte el paquete de solo lectura. Los módulos de servicio están incluidos en `Dockerfile.public`; los modelos generados no están en la imagen ni en Git. No se cargan objetos pickle. La ausencia de activos o un hash incompatible bloquean el análisis.

Mantenga el servicio en `127.0.0.1`, con un único proceso ASGI. Configure el mismo secreto mediante `I4S_AI_API_TOKEN` en Python y `CHARACTERIZATION_API_TOKEN` en Laravel, con `CHARACTERIZATION_API_BASE_URL` apuntando al servicio local. El adaptador usa conexión de dos segundos, plazo total de doce, respuesta máxima de 256 KiB y ninguna redirección ni reintento. Apagar cualquiera de los controles de análisis detiene las nuevas inferencias o revisiones sin borrar las instantáneas aprobadas.

### Revisión y trazabilidad

La interfaz pagina todos los IRO en lotes de hasta veinte. El navegador envía sus identidades; Laravel resuelve las descripciones y el propietario desde la importación guardada. En la revisión de temas, aceptar un vínculo añade una asociación al borrador; la confirmación explícita de doble importancia relativa sigue siendo obligatoria. Los requisitos de información son informativos. En la recogida de datos, una ejecución nueva vinculada a la confirmación vigente permite aceptar indicadores dentro del alcance del IRO material revisado. Cada vínculo aceptado añade una entrada a la lista y abre el editor existente del indicador. Las propuestas no cambian obligaciones, aplicabilidad, respuestas ni hechos.

Las decisiones son inmutables; repetir la misma decisión es idempotente. Rechazar una propuesta no añade una entrada a la lista. Los cambios de contexto invalidan resultados pendientes y asociaciones activas. La procedencia conserva importación, IRO, descripción, modelo, catálogo y contexto revisado. Los informes leen evidencias congeladas de la instantánea, sin consultar las tablas de propuestas activas.

Las rutas de sesión para crear, leer y revisar análisis, sus límites y los estados HTTP se describen en [el contrato de análisis](../contracts/api/dma-processing-v1.md) y su [esquema JSON](../contracts/api/dma-processing-v1.schema.json). La API interna autentica antes de analizar el cuerpo, limita la carga real a 64 KiB y ejecuta un solo trabajador durante un máximo de diez segundos. Los fallos de infraestructura devuelven un error, nunca una abstención satisfactoria. No hay proveedor externo, entrenamiento con textos de usuario, caché compartida de resultados ni registro de cuerpos.

### Evaluación y límites

Las pruebas incluyen casos sintéticos en castellano e inglés para consumo y vertido de agua, energía y emisiones, reparabilidad, residuos peligrosos y radiactivos, negaciones, ambigüedad y citas Unicode. Las etiquetas de referencia son provisionales y requieren revisión humana. Estos casos comprueban comportamiento acotado; no establecen eficacia con empresas reales.

La política de evaluación en `src/tests/fixtures/dma_processing/evaluation-policy.json` fija el modelo público, los casos y los umbrales antes de ejecutar la evaluación: sensibilidad de indicadores a tres candidatos de al menos 0,80 por idioma, precisión de candidatos emitidos de 0,60, cobertura de 0,75, abstención esperada de 0,90 y todos los contrastes E5-5. El evaluador compara recuperación mixta, palabras y expansión del tema declarado, y separa métricas por nivel e idioma. No se distribuyen resultados generados ni se afirma que el control de calidad esté superado.

```bash
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python scripts/dma_processing/evaluate.py --split heldout --model-dir "$DMA_MODEL_DIR" --output "$DMA_EVALUATION_FILE"
```

La activación operativa requiere validar el flujo completo con las dependencias de trazabilidad, revisar las etiquetas y comprobar la calidad del modelo. Publicar el código no realiza estas operaciones.
