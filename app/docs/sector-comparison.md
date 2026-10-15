# Comparación sectorial informativa

La comparación muestra etiquetas de informes por tema AR16 y sección NACE Rev.2.1/CNAE-2025 durante la revisión y confirmación de temas NEIS. Presenta población, soporte y procedencia sin ordenar, seleccionar ni confirmar temas. Laravel conserva autenticación y propiedad; Python recibe únicamente versión, secciones y temas. Es una consulta de solo lectura, independiente del aprendizaje, sin tablas nuevas, exportaciones ni incorporación de datos de usuarios al corpus.

## Entradas privadas y selección

El operador debe aprobar derechos de uso, población, identidad empresa–informe–sector, selección representativa y procedencia antes de construir un artefacto real. No se descubre corpus ni se infiere identidad, sector o ejercicio de nombres. Los manifiestos y artefactos reales permanecen fuera de Git; agregación no equivale a anonimización.

Los CSV `labels` y `masks` contienen `file` y **todas** las claves `python_esrs_key` del mapa indicado, sin otras columnas. Celdas estrictamente `0`/`1`; máscara `0` significa desconocido. Duplicados idénticos se concilian; contradictorios se rechazan. Ambos CSV deben vincular el mismo conjunto de informes, sin filas no vinculadas al manifiesto.

El manifiesto JSON tiene exactamente esta estructura (ejemplo sintético, repetir la empresa según corresponda):

```json
{
  "version": "sector-comparison-v1",
  "sector_taxonomy": "NACE Rev.2.1 / CNAE-2025",
  "provenance": {
    "label_kind": "synthetic",
    "source_reference": "synthetic-fixture",
    "authorization_reference": "test-only"
  },
  "companies": [{
    "company_id": "synthetic-company",
    "identity_verified": true,
    "report_links_verified": true,
    "sections_verified": true,
    "report_ids": ["synthetic-report", "synthetic-extra"],
    "selected_report_id": "synthetic-report",
    "period": {"start": "2025-01-01", "end": "2025-12-31"},
    "sections": ["K", "L"],
    "sector_taxonomy": "NACE Rev.2.1 / CNAE-2025",
    "verification_reference": "synthetic-check"
  }]
}
```

`company_id` es opaco y único; la verificación y selección son declaraciones explícitas del operador. Cada informe pertenece a una empresa; el seleccionado debe estar vinculado y presente en los CSV. Informes adicionales no aumentan población ni soporte; se contabiliza su exclusión agregada. `period` describe el informe seleccionado. `label_kind`: `automatic`, `human`, `mixed` o `synthetic`. Las referencias públicas son códigos opacos `[A-Za-z0-9_-]`, hasta 128 caracteres; no introducir nombres, rutas ni textos individuales. El manifiesto privado conserva la referencia de verificación; el artefacto no la publica.

Solo se admiten secciones A–V. Rev.2 necesita conversión **verificada previa**; productos A–L no son actividades. Secciones sin flags entrenados siguen disponibles si existen comparables, sin sustituciones (J, K, L, M y V mantienen su identidad).

## Construcción reproducible

Desde `app/ai-service`, con las dependencias existentes. Defina `SECTOR_INPUT_DIR` como un directorio autorizado fuera del repositorio y `SECTOR_OUTPUT_PATH` como un archivo nuevo fuera del repositorio:

```sh
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python scripts/concepts/build_sector_comparison.py \
  --labels "$SECTOR_INPUT_DIR/labels.csv" --masks "$SECTOR_INPUT_DIR/masks.csv" \
  --companies "$SECTOR_INPUT_DIR/companies.json" \
  --encoding ../contracts/mappings/p5-gpt41-feature-encoding-v1.json \
  --topic-mapping ../web/data/ar16_to_python_esrs_mapping_new_format_732_v1.json \
  --output "$SECTOR_OUTPUT_PATH"
```

El destino debe ser nuevo y su directorio existir. Escritura atómica sin sobrescritura; JSON sin pickle ni dependencias nuevas. Se validan claves, tipos, recuentos y compatibilidad de taxonomías. Los hashes describen exactamente las entradas leídas; reordenar filas preserva recuentos, pero cambia sus hashes. La construcción registra la fecha UTC actual.

Cada empresa aporta un informe. Un tema es positivo si alguna clave aprobada es positiva conocida; negativo solo si todas sus claves aprobadas son negativas conocidas; en otro caso desconocido. Se excluyen resúmenes y claves no aprobadas; temas sin correspondencia tienen soporte cero. Vocabulario AR16 completo, incluida la abstención. El runtime público contiene la agregación en `public_runtime/services/sector_comparison.py` y reutiliza `build_propensity(retain_all=True)`. El alias `src/services` resuelve estos mismos módulos para el CLI y las pruebas; la imagen pública incluye el directorio completo. La comparación no llama al recomendador de propuestas.

**Población:** empresas seleccionadas de la sección, evaluadas o desconocidas. **Soporte (`n_scored`):** empresas con evaluación conocida del tema. **Materiales (`n_material`):** positivas conocidas. Siempre `0 ≤ n_material ≤ n_scored ≤ population_companies`. Una empresa cuenta una vez globalmente y una vez en cada sección verificada; nunca se suman secciones. La cobertura temporal es el intervalo de los informes seleccionados, no una serie de tendencias.

## Activación y operación privada

Todo está apagado por defecto, independientemente del aprendizaje:

- Laravel: `SECTOR_COMPARISON_ENABLED=false` (`services.sector_comparison.enabled`).
- Python: `ESRS_SECTOR_COMPARISON_ENABLED=false`; para activar requiere `ESRS_SECTOR_COMPARISON_PATH` y `ESRS_SECTOR_COMPARISON_SHA256`, SHA-256 exacto del JSON aprovisionado privadamente.
- Bearer obligatorio: Python `I4S_AI_API_TOKEN`; Laravel reutiliza `services.characterization.api.base_url/token` (`CHARACTERIZATION_API_BASE_URL/TOKEN`). Mantener FastAPI en `127.0.0.1`; no publicar este endpoint.

Python carga una instantánea validada por proceso durante el arranque, con máximo 5 MiB. Cambios requieren configuración y reinicio. Artefacto inválido solo inhabilita la comparación (`503`); no cambia `/predict`. Peticiones estrictas sin campos adicionales: versión, secciones únicas (máximo 22), temas AR16 únicos como strings (máximo 89), cuerpo máximo 16 KiB. Bearer incorrecto `401`; token sin configurar `503`; límite global 60/minuto por proceso (`429`), dos ejecuciones simultáneas (`503`), con `Retry-After`.

Laravel sirve `GET /api/characterizations/{id}/sector-comparison` con sesión, verificación configurada, propiedad y límite 30/minuto. ID numérico acotado; flag apagado/ajeno/inexistente `404`. No admite query ni cuerpo: el sector viene de `nace_code` y los temas del catálogo Laravel. Conexión 1 s, total 3 s, sin redirects ni reintentos, respuesta máxima 256 KiB. Valida contratos, hashes locales de codificación/mapa, denominadores, proporciones y fallback. La codificación se verifica contra la copia de despliegue existente `web/data/p5-gpt41-feature-encoding-v1.json`, idéntica al contrato, sin depender de un checkout completo en el servidor. Relee propiedad y huella sectorial al terminar HTTP, sin transacción durante la llamada: desaparición/propiedad cambiada `404`, entradas cambiadas `409`. Fallos upstream `503` con código estable, sin detalles internos. Respuestas privadas `no-store`.

Las respuestas de propuesta y confirmación anuncian capacidad y huella **solo al activar**; sus GET principales no consultan Python. La UI carga una vez por pantalla/huella, cancela y descarta respuestas antiguas y muestra contexto junto a cada tema, también fuera de la propuesta en los modos directo y guiado. Errores solo afectan al panel. Copia ES/EN, textos escapados, actividad, fecha, periodo, versión, procedencia y etiquetado explícito de datos automáticos/sintéticos; sin controles de aplicación.

Con soporte sectorial ≥5 se muestra proporción sectorial. Por **cada tema**, si falta soporte se conserva el recuento sectorial y se muestra referencia global de baja confianza cuando hay ≥5 evaluaciones globales. Si tampoco existen, abstención con proporción nula. Población global siempre identificada aparte; cero positivos con soporte es diferente de cero soporte. Sin actividad verificada se muestra solo referencia global o abstención, población sectorial cero.

## Pruebas y requisitos de ejecución

Las pruebas usan datos sintéticos. La fixture compartida `app/web/tests/Fixtures/sector-comparison-response.json` se genera mediante el CLI con las copias públicas de codificación y mapa, y no contiene identidades. Los recuentos sintéticos verifican el funcionamiento; no acreditan derechos sobre un corpus real ni calidad para usuarios reales.

- Python, desde `app/ai-service`: `PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=src python -m unittest discover -s src/tests -p 'test_sector_comparison*.py'`. La compatibilidad del agregador se comprueba con el patrón `test_materiality_recommender.py`; las pruebas de runtime público, con `python -m pytest -q tests`.
- Frontend, desde `app/frontend`: `node --test tests/*.mjs`, `pnpm typecheck`, `pnpm lint` y `pnpm build`. Se comprueban presentación en castellano e inglés, carga independiente y descarte de respuestas antiguas.
- Laravel, desde `app/web`: `php artisan test tests/Feature/Api/SectorComparisonApiTest.php tests/Feature/Services/SectorComparisonGatewayTest.php`; la suite completa se ejecuta con `php artisan test`.

El adaptador requiere la extensión PHP `curl` y falla cerrado si falta. El plazo total incluye cabeceras y todos los fragmentos de respuesta; el búfer rechaza el fragmento que superaría 256 KiB antes de almacenarlo. Las pruebas de transporte requieren `proc_open` y sockets locales para crear un servidor sintético en loopback. Verifican retrasos de cabeceras, respuestas fragmentadas, límites de tamaño y lecturas repetidas con flujos independientes.

## Desactivación

Apague **ambos indicadores**, renueve la configuración Laravel según el procedimiento de la instalación y reinicie Python. Conserve las entradas y el artefacto externo conforme a la política del operador. El aprovisionamiento y la activación requieren una población autorizada, conciliación empresarial y revisión de derechos; no se suministra un artefacto sectorial real con el código.
