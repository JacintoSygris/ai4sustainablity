# Codificación P5 → modelo GPT-4.1

Laravel convierte las respuestas de P5 al vocabulario con el que se entrenó el perfil `new_format_732_v1_gpt41`: sector, productos, región y tamaño de plantilla. Laravel conserva la autoridad del payload y el servicio de propuestas sigue en la red privada. No hay variable de activación: es una corrección del codificador. Se aplica al usar `CHARACTERIZATION_AI_MODEL_PROFILE=new_format_732_v1_gpt41` y no cambia el perfil configurado, los pesos ni el umbral.

La corrección tiene dos mitades que deben desplegarse juntas: el gateway de Laravel con `app/web/data/p5-gpt41-feature-encoding-v1.json` y el aviso de P6, y el codificador del servicio de propuestas en `app/ai-service/public_runtime/services/service_predict.py` (también accesible como `services.service_predict` desde `app/ai-service/src`). Si solo se despliega una mitad, las letras de producto antiguas se interpretarían como códigos de producto A–L.

## Vocabulario comprobado

Las categorías proceden de los artefactos de entrenamiento del perfil, leídos con scikit-learn 1.7.0. El snapshot `app/ai-service/src/tests/fixtures/gpt41_feature_vocabulary.json` contiene las columnas de entrada en orden, las categorías del **codificador ajustado** y los SHA-256 de cinco artefactos de entrenamiento. Los cuatro distribuidos en `app/ai-service/model-artifacts/gpt41` coinciden con esos hashes; el de columnas de producto no se distribuye, pero sus categorías figuran como columnas `product_*` de entrada del clasificador. El snapshot no incluye pesos ni registros de empresas.

El pipeline de entrenamiento, que este repositorio no distribuye, define los sectores según NACE Rev. 2.1 (J publicación y audiovisual, K telecomunicaciones e informática, L finanzas y seguros, M inmobiliario, N servicios profesionales) y binariza literalmente las letras del conjunto de 732 empresas; no las convierte a NACE Rev. 2.

- Sector: `A B C D E F G H I J K L M N O R S T`.
- Producto: `A B C D E F G H I K L R`. **No es NACE**: A fabricación, B distribución y logística, C comercio, D servicios profesionales y técnicos, E administrativos y financieros, F ciencia y tecnología, G instalación y mantenimiento, H hostelería y turismo, I formación y servicios sociales, J agricultura, K energía, agua y residuos, L ninguna de las anteriores. J no aparece en los artefactos. R es una anomalía del conjunto extraído, sin significado en la taxonomía de productos; el gateway no la emite y el codificador no la interpreta como salud.
- Región: `(APAC) (EU) (LATAM) (NA) APAC EM EMEA EU LATAM MENA NA SSA`. Las opciones de P5 usan los códigos canónicos sin paréntesis; no se eliminan columnas históricas del modelo. EU significa Europa en el entrenamiento, por lo que el valor regional por defecto incluye al Reino Unido cuando falta la región.
- Tamaño en el codificador ajustado: `HUGE LARGE SMALL ULTRA`. Los datos de entrenamiento contienen MEDIUM, pero el codificador no lo conoce; MICRO tampoco aparece. El snapshot registra además los países, monedas y formas jurídicas realmente ajustados.

## Correspondencias

La única tabla de producto y región mantenida es `app/contracts/mappings/p5-gpt41-feature-encoding-v1.json`. Su copia de distribución en `app/web/data/` permite instalaciones de Laravel separadas; una prueba exige igualdad byte a byte entre ambas.

| Opción P5 | Producto de entrenamiento |
|---|---|
| physical_product_manufacturing | A |
| physical_product_retail | C |
| professional_services | D |
| technical_services | D, G |
| software_digital_services | F |
| construction_installations | G |
| logistics_transport_storage | B |
| finance_insurance | E |
| energy_utilities | K |
| agrifood | J, sin columna entrenada |
| health_life_sciences | I, F |
| education_training | I |
| hospitality_tourism | H |
| mixed / not_sure / ausente | Sin inferir producto |

Las opciones combinadas de servicios técnicos y de salud y ciencias de la vida conservan sus dos componentes. Agroalimentario no prueba fabricación de alimentos: no se inventa A. L significa «ninguna de las anteriores», no «desconocido». No existe sustitución de producto a partir del sector.

Laravel envía directamente la letra de sección CNAE-2025: L64/L65 → L, K62 → K, J58 → J. El producto procede solo de la opción P5 (p. ej., finance_insurance → E, software_digital_services → F). Para solicitudes con una división numérica, el servicio de propuestas hace la misma conversión Rev. 2.1. También se corrigen los alias ingleses financieros e informáticos.

La plantilla se interpreta según la definición del pipeline de entrenamiento: 1–9 MICRO; 10–49 SMALL; 50–249 MEDIUM; 250–5000 LARGE; 5001–20000 HUGE; más de 20000 ULTRA; cero o desconocido NA. La definición solapa 5000, que se resuelve en la primera banda, LARGE. Los extremos están cubiertos por pruebas. P5 conserva sus estimaciones numéricas: `1000_plus` solo aporta 1000 como límite inferior, por lo que no permite distinguir LARGE, HUGE y ULTRA, y la corrección no inventa esa precisión. Tampoco cambia la estimación de ingresos ni `not_sure` → 0, coherente con el tratamiento de valores ausentes del entrenador.

## Carencias explícitas

No es posible activar una columna inexistente sin cambiar el modelo. P, Q, U y V quedan sin variable sectorial; J, sin variable de producto; MICRO, MEDIUM y NA, sin categoría ajustada de tamaño. No se sustituyen por categorías ajenas. `feature_metadata.derived_fields.encoding_coverage` indica `degraded` y enumera `unsupported_values`; `missing_required_fields` identifica las dimensiones sin ninguna variable activa. P6 muestra una advertencia traducida y mantiene la revisión de temas, incluidos los no propuestos. `complete` solo describe la codificación, no la calidad ni la suficiencia de la propuesta.

La forma jurídica no se recoge en P5: UNKNOWN queda explícitamente fuera del vocabulario, sin asumir PLC. Por eso la cobertura suele ser `degraded`. `Other` como país también se comunica como desconocido. Las opciones amplias de P5 no resuelven actividades más finas. Las propuestas ya almacenadas no se recalculan automáticamente: el cambio afecta a las caracterizaciones nuevas o reenviadas.

## Pruebas

Desde `app/ai-service`, con `PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src`:

```sh
python -m unittest discover -s src/tests -p 'test_feature_encoding*.py'
python -m unittest discover -s src/tests -p 'test_service_predict*.py'
```

La primera prueba funciona sin artefactos y cubre todas las opciones de P5 y el catálogo CNAE, permitiendo únicamente las excepciones documentadas. Las pruebas del servicio usan los artefactos distribuidos.

Laravel, desde `app/web`: `php artisan test --filter=ApiCharacterization`. Frontend, desde `app/frontend`: `node --test tests/*.mjs` y `node_modules/.bin/tsc --noEmit --incremental false -p .`. La prueba de renderizado cubre la advertencia en castellano e inglés y su ausencia con cobertura completa.

Las diferencias de propuestas entre el codificador anterior y el corregido sobre perfiles sintéticos no miden precisión, sensibilidad ni F1: no hay verdad de referencia ni resultados de personas usuarias.
