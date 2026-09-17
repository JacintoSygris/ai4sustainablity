# Contrato de mapeo AR16 a claves ESRS del modelo v0

Estado: contrato público activo para convertir predicciones del servicio
incluido en temas candidatos P6.

## Alcance

La aplicación guarda selecciones de temas ESRS como inventario AR16-like. El
servicio de predicción devuelve claves `esrs_*`. Son vocabularios relacionados,
pero no idénticos: algunas claves del modelo son agregadas, otras son subtemas
detallados y otras no deben convertirse automáticamente en temas P6.

Este contrato evita que una coincidencia textual o de prefijo cree una
propuesta engañosa. El mapeo runtime es explícito y fail-closed.

## Fichero runtime

Laravel consume:

```text
app/web/data/ar16_to_python_esrs_mapping.json
```

Cada fila conecta un tema AR16-like con cero, una o varias claves del modelo:

```json
{
  "ar16_topic_id": 1,
  "web_esrs": "E1",
  "web_label_en": "Adaptation to climate change",
  "python_esrs_keys": ["esrs_e1_adaptation_to_climate_change"],
  "mapping_status": "approved",
  "notes": ""
}
```

Valores permitidos de `mapping_status`:

- `approved`
- `needs_review`
- `review_only`
- `aggregate_only`
- `out_of_scope`

Las claves del modelo que no corresponden a un tema candidato AR16 único se
declaran en el objeto superior `python_key_statuses`.

## Reglas runtime

- Un tema P6 solo puede emitirse desde una fila `approved`.
- Cada clave positiva del modelo debe estar mapeada o tener una clasificación
  explícita en `python_key_statuses`.
- Las claves `aggregate_only` pueden informar la revisión, pero no crean por sí
  mismas temas candidatos AR16.
- Las claves `needs_review`, `review_only` y las claves positivas desconocidas
  se exponen en `review_required_prediction_keys`.
- Una clave residual como `esrs_e3_other` no debe asignarse a un tema AR16
  específico si eso sobredeclara materialidad.
- P9 usa una cadena distinta y determinista:
  AR16 -> ESRS -> Disclosure Requirement -> datapoint.

## Salvaguardas

- Si el fichero de mapeo falta, es inválido o no cubre una clave positiva, el
  adaptador debe fallar cerrado para esa conversión.
- El prefijo ESRS no basta para crear temas candidatos.
- La propuesta P6 es soporte para revisión del usuario; la materialidad final
  se confirma posteriormente en P8.
- El mapeo no afirma materialidad, no sustituye una revisión de doble
  materialidad y no habilita datapoints P9 sin la cadena AR16 a DR aplicable.
