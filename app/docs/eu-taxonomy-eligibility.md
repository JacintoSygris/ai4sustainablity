# Cribado orientativo de elegibilidad en la Taxonomía de la UE

Separa la elegibilidad del Reglamento (UE) 2020/852 de los indicadores NEIS y de la taxonomía XBRL. Es un cribado orientativo: elegibilidad potencial no equivale a alineamiento.

## Activación

`EU_TAXONOMY_ELIGIBILITY_ENABLED=false` por defecto, gestionado exclusivamente por Laravel en `config/eu_taxonomy.php`. Aplique la migración `2026_10_08_000001_create_eu_taxonomy_declarations_table.php` antes de activar la variable y actualice la caché de configuración con el procedimiento habitual del entorno. La sesión autenticada comunica la capacidad al frontend; no hay otra variable de Next ni servicio Python.

Con la variable desactivada no aparece el panel, las rutas de cribado devuelven 404 a la persona autenticada y los paquetes de evidencias no incluyen el anexo. Desactivarla conserva las declaraciones.

## Datos y fuentes oficiales

`app/web/data/eu_taxonomy_activities_v1.json` contiene un **subconjunto verificado**, con títulos oficiales en inglés y castellano, objetivo, tipo de contribución, códigos NACE textuales, CELEX, anexo y número de actividad. El archivo `.json.sha256` se verifica en tiempo de ejecución; un desacuerdo impide cargar el catálogo. La huella identifica el contenido, no acredita su validez jurídica.

Fuentes fijadas en el catálogo, con URL y SHA-256 del HTML en `sources`:

- [Reglamento 2021/2139 consolidado a 01-01-2024, EN](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:02021R2139-20240101) y [ES](https://eur-lex.europa.eu/legal-content/ES/TXT/HTML/?uri=CELEX:02021R2139-20240101), anexos I y II.
- [Reglamento 2023/2485, EN](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32023R2485) y [ES](https://eur-lex.europa.eu/legal-content/ES/TXT/HTML/?uri=CELEX:32023R2485), modificación incorporada en la consolidación climática utilizada. La consolidación también contiene modificaciones anteriores; no es el acto auténtico publicado en el Diario Oficial.
- [Reglamento 2023/2486, EN](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32023R2486) y [ES](https://eur-lex.europa.eu/legal-content/ES/TXT/HTML/?uri=CELEX:32023R2486), anexos I–IV.

El repositorio no incluye los documentos completos ni bytes de EFRAG. Del texto fuente se extrajeron los encabezados numerados y el párrafo NACE de la descripción de cada actividad seleccionada, contrastando los códigos en las dos lenguas. No se extrajeron códigos de notas ni de tablas DNSH. Los textos y las correspondencias completos no se califican automáticamente: la cobertura es parcial de forma deliberada.

| CELEX | Anexo / objetivo | Actividades incluidas | Entradas incluidas | Encabezados de actividad en el texto fuente |
|---|---|---|---:|---:|
| 32021R2139 | I / mitigación | 5.1, 5.3, 5.5 | 3 | 101 |
| 32021R2139 | II / adaptación | 5.1, 5.3, 5.5 | 3 | 105 |
| 32023R2486 | I / agua | 2.1, 2.2 | 2 | 6 |
| 32023R2486 | II / economía circular | 2.3 | 1 | 21 |
| 32023R2486 | III / contaminación | 2.1 | 1 | 6 |
| 32023R2486 | IV / biodiversidad | 2.1 | 1 | 2 |

El catálogo contiene **11 entradas por actividad y objetivo**, no 11 actividades económicas distintas. Los recuentos de encabezados no califican el catálogo completo. Todas las entradas seleccionadas se clasifican como `own_performance`: su descripción no las designa facilitadoras ni de transición. Es una categoría documental; no demuestra la contribución sustancial de la empresa. No se evalúan modificaciones normativas posteriores a los textos fijados.

## CNAE-2025 y revisiones NACE

P5 usa el catálogo CNAE-2025 con códigos internos como `E36.0.0`. Los anexos citan NACE Rev. 2, por ejemplo `E36.00`. No basta con eliminar puntos ni con conservar letras, porque hay divisiones y clases modificadas en Rev. 2.1.

El catálogo verifica **7 clases sin cambio**: 36.00, 37.00, 38.11, 38.12, 55.10, 55.20 y 55.30. Las filas `crosswalk` registran sus códigos CNAE-2025, internos de P5, Rev. 2.1 y Rev. 2. Fuentes:

- [INE: CNAE-2025 → CNAE-2009](https://www.ine.es/daco/daco42/clasificaciones/cnae25/Correspondencia_CNAE25_CNAE09.xlsx).
- [INE: CNAE-2025 → NACE Rev. 2.1](https://www.ine.es/daco/daco42/clasificaciones/cnae25/Correspondencias_CNAE25_NACE_Rev_2_1.xlsx).
- [Reglamento 1893/2006: estructura NACE Rev. 2](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32006R1893), comprobación de las clases antiguas.

Las clases de construcción no se convierten automáticamente: la correspondencia oficial de 42.99 es parcial y no unívoca. Las clases no verificadas y los códigos amplios de sección, división o grupo devuelven `manual_review_required`, sin candidatos automáticos. El servicio admite NACE Rev. 2 únicamente cuando el llamante declara explícitamente esa clasificación; la API siempre interpreta P5 como CNAE-2025. Para códigos explícitos Rev. 2 admite la coincidencia exacta o un código padre listado en el anexo, y nunca expande un código empresarial amplio a todas sus clases.

La persona usuaria puede revisar y confirmar cualquier entrada del subconjunto aunque no tenga coincidencia automática: los códigos de los anexos son orientativos y no exclusivos. **La ausencia de coincidencias no significa inelegibilidad.**

## API, declaración y evidencias

`GET /api/report/eu-taxonomy` devuelve `{data: null}` sin caracterización, o el catálogo parcial, avisos, correspondencia, huella de origen, revisión y declaraciones. `PUT` sustituye la selección de la persona autenticada:

```json
{
  "source_hash": "<huella devuelta por GET>",
  "revision": 0,
  "activities": [{
    "activity_id": "32021R2139-I-5.1",
    "turnover_share": 12.5,
    "capex_share": null,
    "opex_share": 0
  }]
}
```

El ejemplo es sintético. Una entrada en `activities` confirma que la descripción corresponde a la empresa; una lista vacía retira las confirmaciones. Cada porcentaje admite 0–100; `null` significa no declarado, que es distinto de cero. No hay agregación entre actividades u objetivos ni cálculo de denominadores contables.

Ambas rutas usan la sesión Laravel, autenticación, guardia de verificación y límites separados de 30 lecturas y 10 escrituras por minuto. Las escrituras mantienen CSRF. No aceptan identificadores de propietario ni de caracterización: resuelven exclusivamente la de la persona conectada. Se rechazan IDs desconocidos o duplicados, campos de actividad adicionales y valores fuera de rango.

Las escrituras bloquean usuario y caracterización mediante `CharacterizationStateTransaction`, comparan revisión y huella y devuelven 409 ante cambios concurrentes. La huella vincula caracterización, código, año de referencia, catálogo e identidad y perímetro de P5 (`company_name`, `entity_identifier`, `entity_identifier_scheme` y `reporting_scope`). El mismo `source_context` usado para calcularla se devuelve y se guarda con la declaración, sin modificar los valores de P5. Un cambio en cualquiera de esos campos, incluida la retirada del LEI o el paso de entidad individual a grupo consolidado, conserva la declaración anterior como `stale`, vacía `confirmed_activities` y exige una confirmación nueva con la huella actual. Las escrituras que conservan la huella anterior reciben 409 aunque envíen la revisión actual. Las declaraciones anteriores a la ampliación de la huella también necesitan una confirmación nueva; no se migran como vigentes. Se guardan la fecha y el contexto de origen. El almacenamiento mantiene la declaración más reciente, no un historial completo de revisiones; la eliminación de la caracterización elimina sus declaraciones por clave foránea.

El panel de P10 tiene textos en castellano e inglés y conserva los cambios pendientes al cambiar de idioma. Presenta título, fuente oficial, objetivo, códigos, casilla de confirmación y porcentajes. React escapa los textos; no se aceptan narrativas libres ni se generan CSV, HTML, XML u OOXML nuevos.

Ambos paquetes JSON (`/api/report/evidence-bundle`, dentro de `bundle`, y `/api/guided-report/evidence-bundle`) incorporan `eu_taxonomy_eligibility` cuando la función está activa. Incluye las actividades confirmadas con sus fuentes, los porcentajes declarados, el contexto, la fecha, la cobertura y el mismo aviso. En estado `stale`, las declaraciones antiguas aparecen únicamente en `previous_declarations`, con `declared_context` y `declared_source_hash`; `source_context` y `source_hash` describen el origen actual y `confirmed_activities` queda vacío. Aprobar una nueva versión NEIS no reconfirma este anexo. Es un **anexo de declaraciones actuales fuera de la versión NEIS aprobada**, marcado expresamente como tal; no modifica sus hechos, su huella, su aprobación, el DOCX ni el iXBRL.

## Límites

Elegibilidad potencial no equivale a alineamiento. No se evalúan contribución sustancial, DNSH, garantías mínimas ni criterios técnicos. No se calculan los KPI reglamentarios de volumen de negocios, CapEx u OpEx ni se enlazan datos contables. Los porcentajes carecen de verificación independiente. La lista, las correspondencias y las versiones jurídicas tienen cobertura parcial. No hay actualización normativa automática ni afirmación de cumplimiento legal. No interviene ningún modelo de IA.

## Pruebas

- Frontend, desde `app/frontend`: `node --test tests/*.mjs` y `node_modules/.bin/tsc --noEmit --incremental false -p .`. Cubren porcentajes nulos, cero y fuera de rango, selección explícita y concurrencia del payload, traducciones y la consistencia de catálogo, checksum, códigos y recuentos.
- Laravel, desde `app/web`: `php artisan test tests/Unit/EuTaxonomyEligibilityTest.php tests/Feature/Api/EuTaxonomyEligibilityApiTest.php tests/Feature/Api/ReportApiTest.php tests/Feature/Api/GuidedReportApiTest.php`. Cubren la variable de activación, autenticación, propiedad, validación, concurrencia, cambio de origen, localización, borrado en cascada y ambos paquetes de evidencias, incluidas las regresiones de identidad y perímetro (`stale`, 409 con huella antigua, conservación del contexto anterior y exclusión de confirmaciones antiguas en ambos paquetes).
