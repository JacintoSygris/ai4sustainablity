# Integridad del alcance y presentación del informe

Las versiones aprobadas vinculan su alcance al corpus de datos normativos IG-3 y al mapa AR16 → requisitos de información. HTML, DOCX y XHTML comparten el contenido aprobado y su orden.

## Activación y compatibilidad

`REPORT_SCOPE_INTEGRITY_ENABLED=false` es el valor predeterminado de `web/config/report_scope.php`. Activarlo permite crear `report_snapshot_v2`, habilita `GET /api/report/xhtml` y la presentación compartida. No requiere migraciones, dependencias nuevas ni cambios Python.

Las versiones v1 conservan payloads, hashes y aprobaciones. Con la bandera activa, `scope_identity_missing` impide su aprobación/exportación actual: hay que preparar y revisar una v2. No se rellenan retrospectivamente identidades desconocidas. Con la bandera apagada, v1 mantiene la vía anterior; v2 sigue verificando su alcance y usando la presentación compartida. Desactivar la bandera oculta la nueva descarga y devuelve 404 en su ruta, pero no permite degradar v2.

## Identidad e invalidación

`ReportScopeAssets::capture()` lee y valida el corpus IG-3 configurado por el operador (`report_scope.corpus_path`) y el mapa aprobado de `services.esrs_datapoints.matter_dr_mapping_path`. No admite rutas ni identidades del cliente. `EsrsDatapointCorpusBuilder::buildCaptured()` calcula el alcance con esos mismos valores decodificados; `build()` conserva su interfaz y la selección de temas existente. La caché acotada del corpus depende del contenido y cada captura vuelve a leerlo.

Ambas copias de `source_manifest.scope`, en la columna y en `snapshot_json`, contienen:

- `version=report_scope_v1`, `corpus_sha256`, `mapping_sha256` y el testigo normalizado `mapping_snapshot_digest`;
- `scope_sha256`: temas ordenados, identificadores de datos normativos incluidos ordenados y cobertura;
- `generation`: entero no negativo para distinguir sucesoras de estados idénticos ya obsoletos.

Los hashes de activos proceden del JSON decodificado: claves de objetos ordenadas recursivamente; orden de listas, objetos vacíos y tipos numéricos conservados. Espacios, sangrado o CRLF de formato no invalidan una aprobación. El manifiesto no almacena rutas. Se incluye en el hash de la versión y se validan sus dos copias antes de confiar en él.

Los motivos nuevos son `scope_identity_missing`, `ig3_corpus_changed`, `ar16_dr_mapping_changed`, `report_scope_changed` y `scope_assets_unavailable`. Entradas ausentes o malformadas se bloquean con 409 saneado. Una transición a obsoleta y su auditoría se guardan en la misma transacción; nunca se revierte esa transición por restaurar activos. Preparar el mismo estado obsoleto crea una sucesora con la generación siguiente, sin heredar aprobación. Prepararla de nuevo devuelve la sucesora vigente.

El orden de bloqueo es caracterización → versión. Se comprueba el alcance al aprobar, construir el IR y justo antes de publicar, después de renderizar/validar. Si cambia durante la generación, se devuelve 409 sin adjunto. Los payloads, hashes y registros de aprobación históricos permanecen intactos; únicamente cambia el estado de obsolescencia y se añade auditoría. No se promete reconstruir documentos históricos cuyos activos ya no existen.

## Presentación y etiquetado

`ReportDisplayProjection::ordered()` alimenta HTML, DOCX y XHTML con el mismo orden de capítulos, apartados, bloques, valores, unidades, explicación climática y narrativa de omisiones. Las decisiones revisadas/aprobadas fuera del cuerpo aparecen en un anexo explícito con aplicabilidad, valor registrado, dimensiones, motivo de ausencia y referencias. No se convierten en afirmaciones del cuerpo. Las referencias son texto escapado sin enlaces activos. Los identificadores técnicos permanecen en metadatos estructurales.

Los códigos de dimensiones reconocidos conservan sus traducciones; las parejas válidas desconocidas se muestran literalmente como `eje: miembro`, con orden determinista. Texto incompatible con XML y formas inválidas producen errores controlados. En la presentación nueva, los valores numéricos conservan sus dígitos para que el texto visible y el dato etiquetado no difieran por redondeo de presentación.

`renderPresentation()` no requiere taxonomía externa, contexto de entidad ni Arelle. `/api/report/xhtml` exige sesión Laravel, verificación según la política existente, propiedad, flujo/contenido completo y aprobación vigente; tiene `throttle:10,1,report-xhtml-presentation`. Devuelve un adjunto `application/xhtml+xml` con `X-Report-Publication-State: presentation_only`. La verificación de correo conserva el comportamiento existente: 409 `email_unverified` para solicitudes JSON.

El plan de etiquetado evalúa cada afirmación independientemente de su visibilidad. Texto, dimensiones, contextos exigidos por el mapeo y datos sin correspondencia siguen visibles sin etiqueta. Los números admitidos mantienen validación de concepto, tipo, unidad, período, precisión y conflictos. La etiqueta se inserta en el valor visible; se conserva cada aparición aunque se deduplique el hecho de máquina. Los motivos estables incluyen `non_numeric`, `nil_unsupported`, `dimensions_unsupported`, `mapping_required_context`, `unmapped` y `duplicate_machine_fact`.

Las rutas de candidato y paquete mantienen `throttle:2,1,report-xhtml-ixbrl`, taxonomía externa y Arelle obligatorio. Sin números etiquetables devuelven 409 `xhtml_ixbrl_no_taggable_claims` y `next_step=/api/report/xhtml`. Con etiquetas, la evidencia del paquete y los metadatos XHTML enumeran `tagged_claim_ids` y `untagged_claims` con motivos, sin valores. Solo tras validar se emite `validated_candidate`; nunca se afirma preparación para una presentación oficial. La descarga de presentación puede ser exclusivamente narrativa si cumple las reglas de contenido existentes.

## Comprobaciones

Las pruebas usan datos sintéticos y comprueban captura de activos, invalidación independiente, conservación de versiones históricas, generaciones sucesoras, paridad entre formatos, texto escapado, controles de acceso y bloqueos de validación. No acreditan calidad con datos reales ni aceptación regulatoria.

Desde `app/web`, configure un directorio temporal externo al repositorio para los archivos generados y ejecute:

```bash
export REPORT_SCOPE_TEST_SCRATCH_DIR="$(mktemp -d)"
export TMPDIR="$REPORT_SCOPE_TEST_SCRATCH_DIR"
php artisan test --filter='ReportSnapshotBuilderTest|ReportScopeIntegrityTest|ReportPresentationParityTest|XhtmlIxbrlCandidateRendererTest|ReportScopeIntegrityApiTest|HtmlReportRendererTest|DocxRendererTest|ReportApiTest'
php artisan test
```

Desde `app/frontend`:

```bash
node --test tests/*.mjs
pnpm typecheck
pnpm lint
pnpm build
```

La aprobación factual, la validación técnica del candidato y la presentación oficial son operaciones distintas. La descarga XHTML de presentación no necesita una taxonomía; las rutas con etiquetado conservan el [flujo de taxonomía externa](es-ES/technical/external-efrag-taxonomy.md).
