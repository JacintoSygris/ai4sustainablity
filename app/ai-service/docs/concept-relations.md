# Relaciones entre conceptos NEIS

Calcula asociaciones entre temas y claves NEIS a partir de las etiquetas de entrenamiento del perfil servido, y agrupa los datos normativos IG-3 por similitud. Las relaciones se muestran como sugerencias informativas en P8 y P9 cuando se activa `CONCEPT_RELATIONS_ENABLED`. No son redes neuronales, aprendizaje continuo, tendencias ni el resultado de una evaluación con personas usuarias.

## Método y procedencia

El script `scripts/concepts/build_relations.py` lee las etiquetas silver GPT-4.1 utilizadas para entrenar el perfil `new_format_732_v1_gpt41`. Esas etiquetas no se distribuyen en este repositorio; el operador que regenere el artefacto debe aportarlas con `--labels`. El artefacto no incluye informes, nombres de empresas, etiquetas individuales ni modelos. Registra los SHA-256 de las etiquetas, de IG-3, del mapa AR16, del generador y de las funciones métricas.

Para cada par binario se calculan phi, Jaccard, soporte conjunto, lift y confianza direccional. Con la tabla de contingencia `(a=ambos, b=solo origen, c=solo destino, d=ninguno)`: phi = `(ad−bc)/sqrt((a+b)(a+c)(b+d)(c+d))`, Jaccard = `a/(a+b+c)`, soporte = `a/n`, lift = `an/((a+b)(a+c))`, confianza = `a/(a+b)`. Los valores indefinidos son `null` y no pasan los umbrales. Se exigen simultáneamente 30 coincidencias, soporte ≥0,05, phi ≥0,2, lift ≥1,1 y confianza ≥0,6. Estos umbrales son parámetros de diseño, no garantías estadísticas. Se conservan las relaciones entre claves hoja y entre temas AR16: cada tema es el OR de sus claves aprobadas en `ar16_to_python_esrs_mapping_new_format_732_v1.json`. Se excluyen los resúmenes y las claves de revisión; no se inventa evidencia para temas sin correspondencia.

IG-3 se representa con TF-IDF de nombres y descripciones disponibles (unigramas y bigramas, stopwords inglesas, `min_df=2`, frecuencia sublineal), más variables one-hot del requisito de divulgación (DR) y de la norma, ambas con peso 0,5. Se normaliza con L2. Se ejecutan KMeans (`random_state=42`, `n_init=10`, distancia euclídea) y agrupación aglomerativa (coseno, enlace medio), para k = 8, 12, 16, 24, 32, 48, 64, 80. Se elige el máximo de la silueta coseno calculada sobre todos los datos; los empates favorecen al primer candidato. Los hermanos del grupo elegido se ordenan por similitud coseno decreciente y, en caso de empate, por ID.

## Resultados del artefacto distribuido

Los valores completos están en los campos `source_sha256`, `parameters` y `metrics` de `app/web/data/esrs_concept_relations_v1.json`.

| Medida | Resultado |
|---|---:|
| Informes etiquetados | 732 |
| Claves / claves hoja | 102 / 92 |
| Temas AR16 con correspondencia aprobada | 88 |
| Relaciones direccionales hoja / AR16 tras los umbrales | 680 / 648 |
| Datos normativos IG-3 | 1184 |
| Datos con campo `description` | 0 |
| Características TF-IDF / totales | 2466 / 2577 |
| Mejor KMeans | k=80; silueta 0,21610397399900477; pureza DR 0,8302364864864865 |
| Mejor aglomerativo | k=80; silueta 0,1920267961375565; pureza DR 0,4983108108108108 |

Se seleccionó KMeans. El generador es determinista: una segunda ejecución produce el mismo contenido salvo `generated_at`. La pureza es la proporción ponderada de la clase DR mayoritaria por grupo. **El DR ya es una característica de entrada: la pureza no es una validación independiente.** La silueta es descriptiva sobre los datos usados para ajustar y seleccionar, no una medida de calidad de recomendación fuera de muestra. El máximo está en el extremo superior del rango explorado; no se afirma un k óptimo global. El catálogo actual no tiene descripciones separadas, por lo que se usan sus nombres. Las asociaciones reflejan las etiquetas silver, sus sesgos y coapariciones, no causalidad ni una verdad humana de doble importancia relativa.

`source_sha256.ar16_mapping` identifica el archivo de correspondencias distribuido en este repositorio. El generador solo lee de ese archivo la clave Python, su estado y sus temas AR16, y esa proyección es la misma con la que se calcularon las relaciones.

## Artefacto, variable y API

Artefacto: `app/web/data/esrs_concept_relations_v1.json`; checksum: `.json.sha256`, con un digest hexadecimal y un salto de línea. `php artisan report:validate-assets` verifica su checksum y su estructura y coherencia con los datos instalados, incluso con la función apagada. No contiene bytes de la taxonomía XBRL externa de EFRAG.

**`CONCEPT_RELATIONS_ENABLED=false` por defecto**, únicamente en la configuración de Laravel (`services.concept_relations.enabled`). Para habilitarla, establézcala a `true` en la configuración operativa y refresque la caché de configuración de Laravel. No hay variable de Next ni servicio Python en línea. Para deshabilitarla, vuelva a `false` y refresque esa caché.

Laravel conserva la autoridad, la autenticación, la validación y la propiedad de los endpoints existentes; no se añade ningún endpoint. El repositorio valida checksum, versión, estructura y SHA-256 de IG-3 y del mapa. Cachea la decodificación por contenido y comprueba los bytes y las fuentes antes de reutilizarla. Cada decoración carga una vez el artefacto. La ausencia, la corrupción o la deriva de fuentes devuelven sugerencias vacías y mantienen disponible el flujo.

- P8 `/api/materiality-confirmation`: `topics[].often_reported_with`, hasta cinco objetos `{id, esrs_code, label, lift, confidence}`, ordenados por lift y confianza. Se presentan en las filas propuestas, en los temas añadidos guardados en modo directo (la fila «Nuevos (añadidos por ti)» muestra las relaciones devueltas por Laravel después de guardar y recargar) y en la revisión guiada. Los temas añadidos solo en un borrador local reciben sugerencias cuando forman parte del universo devuelto por Laravel. La etiqueta procede del catálogo y del idioma de la sesión.
- P9 `/api/esrs-datapoints`: `data.blocks.*.datapoints[].related_datapoints`, hasta cinco objetos `{id, label}`. Se filtran al alcance actual **antes** del límite; los IDs de los hermanos no dan acceso a respuestas de otras personas usuarias. La etiqueta usa el catálogo español existente o el nombre inglés.

Con la variable apagada estos campos se omiten y no se leen los datos nuevos. Con ella activada, el frontend muestra chips informativos en castellano o inglés; React escapa las etiquetas. No son controles de decisión. Los campos no se guardan y no modifican la confirmación P8, la aplicabilidad P9, los digests de autoridad, las respuestas, el mapa determinista, el entrenamiento ni las exportaciones de informes o CSV. La hoja JSON de decisión P8 puede incluir los campos informativos al reutilizar los resúmenes de temas.

## Reproducir y comprobar

Desde `app/ai-service`, con scikit-learn 1.7.0, NumPy y SciPy (fijados en `requirements.public.txt` y en el lock de aprendizaje):

```bash
OMP_NUM_THREADS=1 OPENBLAS_NUM_THREADS=1 PYTHONDONTWRITEBYTECODE=1 python scripts/concepts/build_relations.py \
  --labels /ruta/externa/etiquetas-gpt41.csv \
  --output /ruta/externa/esrs_concept_relations_candidato.json \
  --evidence /ruta/externa/concept-relations-run.json
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_concept_relations*.py'
```

Indique siempre `--evidence` fuera del repositorio (o bajo `app/ai-service/artifacts/`, que Git ignora) y `--output` fuera del repositorio. Sus valores predeterminados escriben dentro del checkout: `--output` sustituiría el artefacto distribuido, y `--evidence` apunta a `app/ai-service/docs/concept-relations-run-2026-10-08.json`. Ese valor se mantiene porque el artefacto registra la huella SHA-256 del generador, que no se modifica; `app/.gitignore` excluye ese patrón para que la evidencia local no se versione por accidente. `--datapoints`, `--mapping` y `--k` permiten producir un candidato separado. Para adoptar un artefacto nuevo, use una ruta versionada nueva y actualice su `.sha256`. No hay llamadas externas ni carga del modelo servido.

Desde `app/frontend`:

```bash
node --test tests/*.mjs
node_modules/.bin/tsc --noEmit --incremental false -p .
```

Desde `app/web`:

```bash
php artisan test tests/Unit/ConceptRelationsRepositoryTest.php tests/Feature/Api/ConceptRelationsApiTest.php tests/Feature/Console/ValidateReportAssetsTest.php
php artisan report:validate-assets
```

Las pruebas Python cubren métricas exactas con fixtures sintéticas, casos indefinidos, umbrales direccionales y la integridad del artefacto distribuido. Las pruebas de frontend renderizan los chips y comprueban idiomas, ausencia de controles y escape de HTML. Las pruebas de Laravel cubren la variable apagada y encendida, autenticación, propiedad, idiomas, alcance, límites, digests, datos ausentes, checksum, corrupción y caché caliente, incluido el estado persistido de un tema añadido en P8.
