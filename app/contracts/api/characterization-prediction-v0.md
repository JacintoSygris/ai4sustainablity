# Contrato de predicción de caracterización v0

Estado: contrato público activo del servicio de predicción incluido.

## Superficie de runtime

Laravel llama al servicio FastAPI incluido mediante:

- `POST {CHARACTERIZATION_API_BASE_URL}/predict`
- `GET {CHARACTERIZATION_API_BASE_URL}/healthz`
- `GET {CHARACTERIZATION_API_BASE_URL}/model-profiles`

En `compose.public.yml`, Laravel usa:

- `CHARACTERIZATION_GATEWAY=api`
- `CHARACTERIZATION_API_BASE_URL=http://ai-service:8001`
- `CHARACTERIZATION_AI_MODEL_PROFILE=new_format_732_v1_gpt41`

El servicio público expone solo el perfil `new_format_732_v1_gpt41`. Los
valores de perfil desconocidos fallan de forma cerrada.

## Petición de predicción

Laravel envía a `/predict` la caracterización normalizada de la organización.
`model_profile` puede omitirse si el llamador usa el perfil público por defecto.

```json
{
  "model_profile": "new_format_732_v1_gpt41",
  "company_name": "ENTITY_1",
  "sector_list": ["Information technology"],
  "headquarters_country": "ES",
  "num_subsidiaries_countries": 0,
  "subsidiaries_regions": ["EU"],
  "products_services": ["Software"],
  "juridic_form": "LLC",
  "employees_total": 150,
  "annual_turnover_million_euro": 12,
  "stock_listed": false,
  "reporting_currency": "EUR"
}
```

## Respuesta de predicción

`esrs` es un mapa de claves públicas del modelo a valores candidatos binarios.
Los metadatos ayudan a validar el adaptador y a presentar temas que requieren
revisión de usuario.

```json
{
  "esrs": {
    "esrs_e1_climate_change_mitigation": 1
  },
  "model_profile": "new_format_732_v1_gpt41",
  "model_key_count": 102,
  "mapped_key_count": 0,
  "feature_metadata": {
    "derived_fields": {},
    "defaulted_fields": {},
    "missing_required_fields": []
  },
  "mapping_metadata": {
    "mapping_status": "external_laravel_mapping",
    "runtime_activation": "runtime_enabled",
    "new_format_score_threshold": 0.95,
    "raw_positive_key_count": 56,
    "threshold_positive_key_count": 26,
    "excluded_non_candidate_key_count": 2,
    "emitted_positive_key_count": 24
  },
  "evidence_refs": []
}
```

## Reglas del adaptador Laravel

- La salida de predicción propone solo temas candidatos P6. Nunca marca
  materialidad final.
- La materialidad final siempre requiere confirmación del usuario en P8.
- Laravel aplica un fichero explícito de mapeo runtime; el prefijo ESRS por sí
  solo no basta.
- Las claves positivas clasificadas como `needs_review`, `review_only` o
  `aggregate_only`, y las claves positivas desconocidas por el mapeo runtime,
  se devuelven en `review_required_prediction_keys`.
- `esrs_e3_other` es un grupo residual E3 solo para revisión. Por sí mismo no
  crea un tema candidato P6.
- `num_subsidiaries_countries` procede del número explícito de países con
  filiales, no de selecciones amplias de región operativa.
- El número de empleados y la facturación anual deben preferir valores
  derivados de rangos estructurados cuando no haya valores numéricos exactos.
- Una respuesta `200` con `"esrs": {}` es una predicción válida de cero
  candidatos.
- `esrs` ausente, `esrs` no objeto, respuestas no JSON o valores ESRS no
  binarios son deriva de contrato y deben fallar de forma cerrada.
- Los positivos de alta confianza se muestran para revisión; no son
  materialidad final.
