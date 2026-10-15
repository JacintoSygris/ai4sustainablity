# Paquete candidato iXBRL provisional

Paquete técnico con un informe Inline XBRL candidato, generado desde una versión factual aprobada en P10. No es una presentación ESEF/ESAP oficial ni acredita el cumplimiento de las NEIS o de la obligación de etiquetado. La taxonomía EFRAG ESRS Set 1 es la taxonomía final de referencia; la revisión de 2026 sigue siendo provisional. Esta función no activa aprendizaje ni cambia modelos.

## Configuración y uso

| Configuración | Valor por defecto | Efecto |
|---|---|---|
| `IXBRL_PACKAGE_UI_ENABLED` | `false` | Oculta el botón de P10, deshabilita la descarga del paquete (404) y la captura de fechas del periodo en P5. |
| `IXBRL_TAXONOMY_PROFILE` | `esrs_set1_2023-12-22` | Selección explícita del perfil por el operador. |
| `IXBRL_2026_DRAFT_ENABLED` | `false` | El perfil `esrs_2026_draft_2026-08-30` sigue bloqueado incluso al activarlo: carece de correspondencias de conceptos. |
| `ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH` | sin configurar | Manifiesto externo con la ruta y la huella del paquete oficial. |
| `ESRS_ARELLE_COMMAND` | sin configurar | Ruta absoluta a `arelleCmdLine`, no un nombre resuelto por `PATH`: Arelle se ejecuta con un entorno mínimo (`PATH=/usr/local/bin:/usr/bin:/bin`). La imagen web pública lo instala en `/opt/arelle/bin/arelleCmdLine`. |
| `ESRS_ARELLE_CACHE_DIRECTORY` | sin configurar | Ruta absoluta, fuera del repositorio, de una caché aprovisionada de dependencias públicas XBRL/W3C para validar sin red. |

El registro de perfiles está en `app/web/config/ixbrl.php`. Mantiene separados el perfil de taxonomía y el perfil factual aprobado `esrs-2023-preparatory-v1`, y no modifica las versiones aprobadas existentes. La correspondencia de conceptos debe pertenecer a los activos verificados del perfil factual. No hay reutilización automática de conceptos Set 1 para el borrador de 2026. La instalación del paquete EFRAG y del manifiesto se describe en [Taxonomía EFRAG externa](es-ES/technical/external-efrag-taxonomy.md).

Para usarlo, aprovisione Arelle, la taxonomía y la caché **fuera del repositorio**, configure las rutas anteriores y active `IXBRL_PACKAGE_UI_ENABLED`. En P5, introduzca las fechas reales de inicio y fin del periodo y el LEI; después revise los datos y apruebe una nueva versión factual en P10. Modificar las fechas invalida la versión anterior mediante el control existente de cambios de caracterización. No se deducen fechas a partir del año. Las versiones sin fechas explícitas, o sin un LEI válido, bloquean el candidato hasta renovar su aprobación. Como las fechas solo se capturan con `IXBRL_PACKAGE_UI_ENABLED=true`, con la variable apagada el candidato XHTML/iXBRL existente también se bloquea por falta de contexto: responde 409 con `xhtml_ixbrl_context_missing`, aunque la taxonomía y Arelle estén aprovisionados.

El botón «Candidato iXBRL provisional» de P10 solo aparece cuando se cumplen a la vez cuatro condiciones: `IXBRL_PACKAGE_UI_ENABLED=true`, el flujo completo, el contenido del informe preparado y el estado `verified` de `GET /api/report/taxonomy`. Mientras la taxonomía externa está bloqueada, el botón permanece oculto y P10 muestra el motivo del bloqueo.

`GET /api/report/ixbrl-package` usa la sesión Laravel, consulta únicamente las versiones de la persona usuaria, exige el flujo completo y una versión aprobada vigente, y comparte el límite de solicitudes del candidato XHTML. Revalida la vigencia después de Arelle. Un error de perfil, representación o validación devuelve 409 con un código controlado. Desactivar la opción vuelve a ocultar el botón y bloquea la ruta. La ruta `/api/report/xhtml-ixbrl-candidate` sigue disponible y comparte las mismas reglas de representación.

## Representación y paquete

El generador consulta `periodType`, tipo y carácter abstracto en el esquema del paquete externo cuyo SHA-256 está verificado. Usa las fechas congeladas del informe para los contextos de duración y el fin del periodo para los contextos instantáneos. El LEI se comprueba con MOD-97 y se emite con `http://standards.iso.org/iso/17442`; el lector admite el antiguo esquema HTTPS en versiones inmutables y normaliza únicamente la salida.

Los valores decimales exactos se conservan como cadenas, sin truncarlos a una precisión fija. Los negativos usan `sign="-"`, con el signo también visible fuera del hecho. Los porcentajes requieren la unidad explícita `%` (escala -2, ajuste de decimales) o `ratio` (fracción ya normalizada); `pure` no se interpreta como porcentaje. Las unidades admitidas son las monedas del catálogo de P5, `pure` para recuentos y decimales, y las unidades UTR `tCO2e`, `MWh`, `kg`, `t`, `m3` y `ha` con su tipo correspondiente. No se convierten otras unidades ni unidades compuestas. Los hechos duplicados idénticos se consolidan; los valores en conflicto o los datos normativos distintos que comparten concepto se bloquean.

Todo texto y atributo se escapa mediante DOM. `ix:header` está oculto, `xml:lang` identifica el idioma y no se emite el atributo XHTML incompatible `lang`. El título del DOCX también usa el escape de PhpWord, incluida la razón social introducida por la persona usuaria.

Las correspondencias `MDR-A_11` y `MDR-A_12` declaran `required_context: ["future_period_or_time_horizon"]`. El generador bloquea cualquier correspondencia con requisitos de contexto pendientes, aunque sea el único hecho y las dimensiones estén vacías. No se convierte un gasto futuro en un gasto del ejercicio. La huella del mapa se actualiza junto con el mapa; las versiones aprobadas ligadas a la huella anterior deben renovarse. Esta restricción no certifica semánticamente las demás correspondencias.

El ZIP contiene exactamente:

```text
candidate/
  META-INF/reportPackage.json
  reports/candidate.xhtml
  evidence.json
```

Declara `https://xbrl.org/report-package/2023`, coherente con la extensión `.zip` del tipo *unconstrained* de [Report Package 1.0](https://www.xbrl.org/Specification/report-package/REC-2023-09-22/report-package-REC-2023-09-22.html). Contiene un único informe Inline XBRL y no usa el identificador `/xbri` con extensión ZIP. El `schemaRef` apunta al punto de entrada seleccionado. Los identificadores, el estado y el punto de entrada del perfil quedan en el XHTML y en las evidencias; no se incluyen archivos de taxonomía ni rutas privadas. Las fechas del ZIP se fijan para que sea reproducible, sin atribuirles la fecha de creación del informe.

Arelle valida el ZIP exacto, sin red y con las fórmulas del perfil activas. Se comprueban la salida del proceso **y** el registro JSON de severidades; una línea que diga «validated» no basta. Se conservan los límites de tiempo, tamaño y concurrencia, y el proceso hijo recibe un entorno mínimo sin credenciales de la aplicación. Laravel pasa `--validationExitCode`, `--disablePersistentConfig`, `--xdgConfigHome` con un directorio temporal, `--logFile` con el registro JSON, `--packages` y, si está configurada, `--cacheDirectory`. Estas opciones corresponden a `arelle-release` 2.46.1, la versión de la herramienta de evidencia; `app/web/requirements-arelle.txt` fija 2.45.1 para la imagen web, una combinación no comprobada. Verifique que el Arelle instalado las acepta antes de activar la función; si no, el candidato se bloquea con `xhtml_ixbrl_arelle_validation_failed`. `validated_candidate` expresa una validación técnica del candidato, nunca `filing_ready`.

El supervisor comprueba durante la ejecución el tamaño **agregado** de stdout, stderr y `validation.json`, incluido el último sondeo antes de aceptar la salida del hijo. El JSON se crea con permisos privados antes de lanzar el proceso. Al superar el presupuesto se termina el hijo, se limpian los archivos y se libera el bloqueo. El control es por sondeo: no constituye una cuota de disco del sistema operativo.

## Herramienta de evidencia con Arelle

`app/ai-service/scripts/ixbrl/validate_with_arelle.py` genera una muestra **sintética** con las clases PHP del producto y la valida con Arelle sin red. Es una herramienta aislada de operador, no una dependencia del servicio. Requisitos: Python con `venv` y `pip`, PHP con DOM y ZipArchive, y acceso a PyPI para instalar la versión de `arelle-release` fijada en `app/ai-service/scripts/ixbrl/requirements-validation.txt`. La imagen web usa su propia versión fijada en `app/web/requirements-arelle.txt`; compare ambas antes de comparar resultados.

La herramienta no descarga la taxonomía EFRAG. El operador obtiene el ZIP oficial bajo los derechos aplicables, lo guarda fuera del repositorio y confirma expresamente esos derechos:

```bash
python app/ai-service/scripts/ixbrl/validate_with_arelle.py \
  --php /ruta/absoluta/a/php \
  --taxonomy-package /ruta/externa/ESRS-Set1-XBRL-Taxonomy.zip \
  --confirm-external-taxonomy-rights \
  --scratch /ruta/externa/arelle-scratch \
  --evidence-prefix /ruta/externa/evidencias/arelle-validation
```

Sin `--confirm-external-taxonomy-rights` la herramienta termina antes de generar nada. Rechaza un paquete de taxonomía, un directorio de trabajo o un prefijo de evidencia situados dentro del repositorio; si no se indica `--evidence-prefix`, la evidencia se escribe en `<scratch>/evidence/arelle-validation.json` y `.md`. El resumen registra `taxonomy_package_source: operator_supplied` y la huella SHA-256 del paquete, sin URL de descarga de la taxonomía. Crea un entorno virtual en el directorio de trabajo externo, instala Arelle, descarga la UTR pública, resuelve las dependencias públicas XBRL/W3C en una caché externa y ejecuta después la validación completa sin red y un diagnóstico sin fórmulas. El código de salida corresponde a la validación completa; el diagnóstico con `--formula=none` nunca habilita la descarga del producto.

La evidencia, el entorno, la caché, la muestra y los registros se escriben **fuera del repositorio** y no deben versionarse: los mensajes de error de Arelle citan etiquetas de la taxonomía EFRAG. La validación completa de la muestra sintética falla por divulgaciones ausentes; no se inventan datos para eliminar esos errores, y la API bloquearía esa muestra.

## Pruebas

```bash
cd app/frontend
node --test tests/*.mjs
node_modules/.bin/tsc --noEmit --incremental false -p .
```

Desde `app/web`, `php artisan test` con `tests/Unit/Report/IxbrlPackageTest.php`, `tests/Unit/Report/XhtmlIxbrlCandidateRendererTest.php`, `tests/Unit/Report/ArelleXhtmlIxbrlValidatorTest.php` y las pruebas de API `tests/Feature/Api/ReportApiTest.php`, `tests/Feature/Api/GuidedReportApiTest.php` y `tests/Feature/Api/CharacterizationApiTest.php`. Usan metadatos sintéticos explícitos y no sustituyen una ejecución real de Arelle.

Desde `app/ai-service`, `PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_ixbrl*.py'` comprueba el resumen de errores y la negativa a afirmar una validación completa sin registro.

## Límites

El etiquetado se limita a hechos numéricos sin dimensiones. El texto narrativo se muestra escapado, sin etiquetar; otros tipos no numéricos quedan en las evidencias. No se generan dimensiones, enumeraciones, hechos nulos, extensiones de taxonomía, unidades compuestas ni divulgaciones faltantes. La tabla de correspondencias no adquiere una certificación semántica completa por esta muestra.

La validación sigue siendo síncrona con un bloqueo global. No se cubren las normas de presentación de ESMA, Word o Windows nativos, informes reales ni la integración con ESAP. La prueba positiva con un validador simulado verifica el contrato de descarga; no es una validación real positiva completa. El borrador de 2026 no tiene mapa ni evidencia de validación.
