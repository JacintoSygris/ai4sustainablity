# Frontend Characterization API v0

Status: implemented locally in Laravel and wired to the integrated
`app/frontend/` Next runtime for the P5-P10 private-dev workflow.
The historical Airis/Vercel source snapshot remains in the tree, but current
workflow state is owned by Laravel.

## Boundary

- Frontend: integrated Next UI under `app/frontend/`, copied from
  `airis-main.zip` and migrated onto Laravel API/auth ownership for the
  current P5-P10 workflow.
- Backend: Laravel JSON API and persistence.
- AI service: private Python/FastAPI service on localhost; not exposed to the
  frontend.

The frontend must not call the Python service directly. It sends
characterization data to Laravel; Laravel owns persistence, validation, job
dispatch, and Python adapter calls.

## Authentication

Current private dev topology:

- Laravel Auth/session owns frontend and API access.
- When `PRIVATE_DEV_AUTO_LOGIN=true`, Laravel can auto-authenticate the
  configured technical user for private dev verification.

Current implementation caveat: this codebase only defines Laravel's
session-backed `web` guard for API auth. Token auth, Sanctum stateful-SPA
bootstrap, and cross-origin session/CORS/CSRF setup are not implemented yet.
A separately deployed frontend can keep using the payload shapes below, but
production auth transport must be decided and implemented before removing the
private-dev auto-login topology.

## Endpoints

Machine-readable companion contract:
`app/contracts/api/frontend-characterization-openapi-v0.json`.

The OpenAPI file covers the characterization routes below, the workflow
manifest, and the private catalog endpoints `GET /api/nace-codes` and
`GET /api/esrs-topics`, which the separate frontend can use for selectors and
topic lists after the same Laravel auth gate as the rest of the frontend API.

### `GET /api/workflow`

Returns a deterministic workflow and capability manifest for the integrated
frontend. The frontend can use it to discover the current P5-P10 phase
order, primary endpoints, supporting endpoints, implemented capabilities, and
explicit limitations without depending on Laravel Blade routes.

The endpoint does not read user characterization data and does not call Python.
It is still private-authenticated like the rest of the API, because the live
VPS is a protected dev server.

The `frontend_source` block separates current integrated runtime state from the
historical imported source snapshot. It is limited to relative paths, source ZIP
filename, runtime notes, gate status, and secret variable names; real frontend
env values remain outside Git and docs.

```json
{
  "data": {
    "type": "frontend_workflow_manifest",
    "version": "v0",
    "frontend_source": {
      "presence": "present",
      "integration_status": "integrated_laravel_api_runtime",
      "workspace": "app/frontend",
      "source_zip_filename": "airis-main.zip",
      "target_backend_owner": "Laravel",
      "current_runtime": {
        "framework": "Next 16.1.4",
        "auth": "Laravel web session",
        "database": "Laravel persistence",
        "api_routes": "Laravel API via Next rewrites"
      },
      "secrets": {
        "example_file": "app/frontend/.env.example",
        "real_env_files_committed": false,
        "deployment_secret_delivery": "private_operator_handoff",
        "required_private_values": [],
        "optional_private_values": []
      },
      "handoff_warnings": [
        "The received Next /api/wizard/* routes are historical source behavior and are not authoritative IA4S workflow APIs.",
        "Step 2 and Step 3 use client-supplied reportId without userId ownership checks in the received source.",
        "Imported Better Auth and Turso/libSQL modules remain historical source-snapshot code; the current integrated runtime is Laravel-owned."
      ],
      "quality_gates": {
        "build": {
          "command": "corepack pnpm build",
          "status": "pass_smoke",
          "notes": "Next build succeeds as a runability smoke, but next.config.mjs skips TypeScript validation."
        },
        "lint": {
          "command": "corepack pnpm lint",
          "status": "failing",
          "notes": "package.json defines lint: eslint ., but eslint is not installed in the frontend package."
        }
      }
    },
    "workflow": [
      {
        "phase": "P5",
        "title": "Characterization",
        "status": "implemented",
        "primary_endpoint": "/api/characterization",
        "supporting_endpoints": [
          "/api/characterization/options",
          "/api/nace-codes"
        ],
        "summary": "Company profile, operations profile, NACE sector, and progressive disclosure metadata."
      },
      {
        "phase": "P9",
        "title": "ESRS datapoints",
        "status": "implemented_standard_level",
        "primary_endpoint": "/api/esrs-datapoints",
        "supporting_endpoints": [
          "/api/esrs-topics",
          "/api/esrs-datapoints/responses",
          "/api/esrs-datapoints/responses/export.csv",
          "/api/esrs-datapoints/export.csv"
        ],
        "summary": "Deterministic IG3 datapoint corpus with DR grouping, completion plan, phase-in metadata, and pending exact matter mapping."
      },
      {
        "phase": "P10",
        "title": "Report package",
        "status": "readiness_api_implemented_generation_pending",
        "primary_endpoint": "/api/report",
        "supporting_endpoints": [
          "/api/report/draft",
          "/api/materiality-confirmation/decision-sheet",
          "/api/esrs-datapoints/responses/export.csv",
          "/api/esrs-datapoints/export.csv",
          "/characterization/summary?format=pdf"
        ],
        "summary": "Report-package readiness, available downloads, and remaining blockers for the separate frontend report step."
      }
    ],
    "capabilities": {
      "private_dev_auto_login": true,
      "characterization_gateway": {
        "default": "mock"
      },
      "p9": {
        "granularity": "standard_level_partial",
        "matter_mapping_status": "pending"
      },
      "report": {
        "readiness_endpoint": "/api/report",
        "draft_endpoint": "/api/report/draft",
        "generation_status": "not_implemented"
      }
    },
    "limitations": [
      {
        "key": "historical_imported_frontend_source_state",
        "message": "The buildable original Airis/Vercel frontend source remains under app/frontend, but the current integrated P5-P10 runtime uses Laravel auth, persistence, and API ownership. Standalone Next/Turso/Better Auth routes are historical snapshot behavior only."
      },
      {
        "key": "exact_ar16_matter_to_dr_mapping_pending",
        "message": "P9 currently filters topical datapoints at ESRS standard level; exact AR16 matter to Disclosure Requirement mapping is still pending."
      },
      {
        "key": "final_report_generation_pending",
        "message": "The backend exposes report-package readiness and supporting downloads, but final AI report/XBRL generation is not implemented in this release."
      }
    ]
  }
}
```

### `GET /api/characterization/options`

Returns controlled option dictionaries for the future frontend. The endpoint is
private-authenticated like the rest of the characterization API, so the frontend
can build Level A/B/C progressive disclosure without hardcoding backend keys.

```json
{
  "data": {
    "levels": {
      "core": {
        "required_fields": [
          "nace_code",
          "form_data.company_profile.company_name",
          "form_data.company_profile.headquarters_country",
          "form_data.company_profile.reporting_year",
          "form_data.company_profile.reporting_scope",
          "form_data.company_profile.num_subsidiaries_countries",
          "form_data.company_profile.stock_listed",
          "form_data.company_profile.reporting_currency",
          "form_data.company_profile.product_service_type",
          "form_data.operations.regions",
          "form_data.operations.value_chain",
          "form_data.operations.employee_count_range",
          "form_data.operations.revenue_range"
        ],
        "draft_clearable_fields": [
          "nace_code",
          "esrs_topic_ids",
          "form_data.operations.regions",
          "form_data.operations.value_chain"
        ],
        "company_profile": {
          "headquarters_countries": {
            "Spain": "España",
            "Portugal": "Portugal",
            "Other": "Otro"
          },
          "reporting_scopes": {
            "individual": "Entidad individual",
            "consolidated_group": "Grupo consolidado",
            "not_sure": "No estoy seguro"
          },
          "reporting_currencies": {
            "EUR": "EUR",
            "GBP": "GBP",
            "USD": "USD"
          },
          "product_service_types": {
            "software_digital_services": "Software/SaaS/servicios digitales",
            "professional_services": "Servicios profesionales",
            "not_sure": "No estoy seguro / prefiero no decirlo"
          }
        },
        "operations": {
          "regions": {
            "eu": "Unión Europea",
            "north_america": "Norteamérica"
          },
          "value_chain": {
            "upstream": "Actividades aguas arriba",
            "direct_operations": "Operaciones directas",
            "downstream": "Actividades aguas abajo"
          },
          "employee_count_ranges": {
            "50_249": "50-249",
            "1000_plus": "1,000+",
            "not_sure": "No estoy seguro / prefiero no decirlo"
          },
          "revenue_ranges": {
            "2m_to_10m": "EUR 2M-10M",
            "gt_250m": "Más de EUR 250M",
            "not_sure": "No estoy seguro / prefiero no decirlo"
          },
          "numeric_estimates": {
            "employee_count": {
              "field": "form_data.operations.employee_count",
              "type": "integer",
              "minimum": 1,
              "range_field": "form_data.operations.employee_count_range",
              "range_estimates": {
                "50_249": 150,
                "1000_plus": 1000
              }
            },
            "revenue": {
              "field": "form_data.operations.revenue",
              "type": "number",
              "minimum": 0,
              "currency_field": "form_data.company_profile.reporting_currency",
              "range_field": "form_data.operations.revenue_range",
              "range_estimates": {
                "2m_to_10m": 6000000,
                "gt_250m": 250000000
              }
            }
          }
        }
      },
      "csrd_orientation": {
        "enabled_field": "form_data.csrd_orientation.enabled",
        "fields": {
          "listed_entity": "Listed entity",
          "public_interest_entity": "Public interest entity",
          "reports_as_group": "Reports as group"
        },
        "values": {
          "yes": "Yes",
          "no": "No",
          "not_sure": "No estoy seguro"
        },
        "disclaimer": "Orientación informativa únicamente. La determinación del alcance legal depende de la normativa vigente y de asesoramiento especialista."
      },
      "data_readiness": {
        "enabled_field": "form_data.data_readiness.enabled",
        "items": {
          "energy": "Consumo de energía",
          "ghg_emissions": "Emisiones GEI",
          "water": "Consumo de agua"
        },
        "fields": [
          "available",
          "year",
          "source",
          "verified",
          "traceability"
        ],
        "values": {
          "yes": "Yes",
          "no": "No",
          "not_sure": "No estoy seguro"
        },
        "sources": {
          "invoices": "Facturas",
          "erp": "ERP/sistema contable",
          "metering": "Medidores o medición directa"
        },
        "traceability_levels": {
          "high": "Alta",
          "medium": "Media",
          "low": "Baja",
          "unknown": "Desconocida"
        }
      }
    }
  }
}
```

### `GET /api/characterization`

Returns the current authenticated user's characterization or `null`.

```json
{
  "data": null
}
```

When a characterization exists:

```json
{
  "data": {
    "id": 1,
    "status": "draft",
    "nace_code": "A",
    "esrs_topic_ids": [1, 2],
    "form_data": {
      "company_profile": {
        "company_name": "Entidad Demo",
        "nace_code": "A",
        "headquarters_country": "Spain",
        "reporting_year": 2025,
        "reporting_scope": "consolidated_group",
        "num_subsidiaries_countries": 2,
        "stock_listed": false,
        "reporting_currency": "EUR",
        "product_service_type": "software_digital_services"
      },
      "operations": {
        "regions": ["eu"],
        "value_chain": ["direct_operations"],
        "employee_count_range": "50_249",
        "revenue_range": "2m_to_10m"
      },
      "csrd_orientation": {
        "enabled": true,
        "listed_entity": "no",
        "public_interest_entity": "not_sure",
        "reports_as_group": "yes"
      },
      "data_readiness": {
        "enabled": true,
        "items": {
          "energy": {
            "available": "yes",
            "year": 2025,
            "source": "invoices",
            "verified": true,
            "traceability": "high"
          },
          "ghg_emissions": {
            "available": "not_sure"
          }
        }
      },
      "notes": "API ready"
    },
    "result_data": null,
    "last_error": null,
    "retry_count": 0,
    "next_retry_at": null,
    "last_job_attempted_at": null,
    "submitted_at": null,
    "completed_at": null,
    "created_at": "2026-06-04T09:00:00.000000Z",
    "updated_at": "2026-06-04T09:00:00.000000Z"
  }
}
```

### `PUT /api/characterization`

Creates or updates the current user's draft characterization. Returns HTTP
`200`.

Request payload uses the same normalized keys as the existing Laravel
characterization form. For draft saves, partial step payloads are allowed where
the shared `CharacterizationRequest` allows them.

Draft saves may clear the selector fields listed in
`data.levels.core.draft_clearable_fields`: send `nace_code: null`,
`esrs_topic_ids: []`, `form_data.operations.regions: []`, or
`form_data.operations.value_chain: []`. Submit requests still enforce the
non-empty fields listed in `data.levels.core.required_fields`.

```json
{
  "step": "review",
  "nace_code": "A",
  "esrs_topic_ids": [1],
  "form_data": {
    "company_profile": {
      "company_name": "Entidad Demo",
      "headquarters_country": "Spain",
      "reporting_year": 2025,
      "reporting_scope": "consolidated_group",
      "num_subsidiaries_countries": 2,
      "stock_listed": false,
      "reporting_currency": "EUR",
      "product_service_type": "software_digital_services"
    },
    "operations": {
      "regions": ["eu"],
      "value_chain": ["direct_operations"],
      "employee_count_range": "50_249",
      "revenue_range": "2m_to_10m"
    },
    "csrd_orientation": {
      "enabled": true,
      "listed_entity": "no",
      "public_interest_entity": "not_sure",
      "reports_as_group": "yes"
    },
    "data_readiness": {
      "enabled": true,
      "items": {
        "energy": {
          "available": "yes",
          "year": 2025,
          "source": "invoices",
          "verified": true,
          "traceability": "high"
        }
      }
    },
    "notes": "API ready"
  }
}
```

### `POST /api/characterization/submit`

Creates or updates the current user's characterization, marks it as
`submitted`, resets retry metadata, clears stale result fields, sets
`submitted_at`, and dispatches the existing `SubmitCharacterizationJob`. Returns
HTTP `202`.

The endpoint defaults `action=submit` and `step=review`, so the frontend does
not need to send those fields. For the JSON API, `esrs_topic_ids` is optional:
the original Airis frontend can submit the P5 company/operations profile without
preselecting P6 topics, then poll `GET /api/characterization` until the
background job completes.

If the current characterization is already `submitted`, `waiting`, or
`processing`, submit is idempotent: Laravel returns the current state with
`retry_count`, `next_retry_at`, and `last_job_attempted_at` intact and does not
dispatch a duplicate job or mutate the in-flight payload.

When the Python-backed gateway returns `result_data.candidate_topics`, Laravel
persists those candidate `ar16_topic_id` values as both `esrs_topic_ids` and
`form_data.esg_focus.topic_ids`. That persisted set is the P6 AI proposal that
`GET /api/materiality-confirmation` uses as its default P8 confirmation baseline.
If a gateway response does not include `candidate_topics` (for example the local
mock gateway), Laravel preserves the existing topic selection.

### `GET /api/materiality-proposal`

Returns a normalized P6 materiality proposal for the current authenticated
user's characterization, or `null` if no characterization exists. The separate
frontend should use this endpoint for the "propuestos por la IA" screen instead
of parsing raw `result_data`.

The endpoint does not call Python. It reads the stored characterization state:

- `proposal_topic_ids` and `proposal_topics` come from persisted
  `esrs_topic_ids`, with a legacy fallback to `result_data.candidate_topics`
  when older rows have AI candidates but no synchronized topic IDs.
- `source` is `ai_prediction` when `result_data.candidate_topics` exists,
  otherwise `stored_topic_ids`.
- `ready_for_confirmation` is true only when the characterization is completed
  and at least one proposal topic is available. It means a proposal exists for
  P8 confirmation; it does not mean the P6 review is complete or that every
  manual-review prediction key has been resolved.
- `review` exposes the stored P6 review actions. Its `status` is
  `not_started`, `in_progress`, or `reviewed` depending on whether every
  proposed topic has an action. Returned `topic_actions`, `action_reasons`, and
  `action_notes` are filtered to the current proposal topic IDs so stale actions
  from an older proposal are not shown to the frontend.
- `ai.candidate_topics`, `ai.review_required_prediction_keys`, and
  `ai.raw_prediction_key_count` expose the AI evidence needed for review badges
  without forcing the frontend to know the whole raw prediction payload.

Example:

```json
{
  "data": {
    "characterization_id": 1,
    "status": "completed",
    "source": "ai_prediction",
    "proposal_topic_ids": [4, 1],
    "proposal_topics": [
      {
        "id": 4,
        "esrs_code": "E2",
        "theme": { "en": "Environment", "es": "Medioambiente" },
        "subtheme": { "en": "Pollution", "es": "Contaminacion" },
        "subtopic": { "en": null, "es": null }
      }
    ],
    "ready_for_confirmation": true,
    "review": {
      "status": "in_progress",
      "topic_actions": {
        "4": "unsure"
      },
      "action_reasons": {
        "4": ["needs_adm", "stakeholder_input"]
      },
      "action_notes": {
        "4": "Review this topic during the ADM workshop."
      },
      "reviewed_at": "2026-06-04T14:30:00.000000Z"
    },
    "ai": {
      "status": "completed",
      "summary": "AI proposed 2 candidate ESRS topics. 1 predicted ESRS key needs manual review.",
      "candidate_topics": [
        {
          "ar16_topic_id": 4,
          "web_esrs": "E2",
          "web_label_en": "Pollution",
          "python_esrs_keys": ["esrs_e2_pollution"],
          "score_source": "python_predict",
          "suggested": true
        }
      ],
      "review_required_prediction_keys": ["esrs_e3_other"],
      "raw_prediction_key_count": 3
    }
  }
}
```

### `PUT /api/materiality-proposal`

Stores the user's Phase 2 review actions for the P6 AI proposal. This is
traceability for the proposal screen only; it does not overwrite the final P8
materiality confirmation.

Accepted `topic_actions` values:

- `accepted`
- `rejected`
- `unsure`

Accepted `action_reasons` chips:

- `sector_fit`
- `not_relevant`
- `threshold`
- `stakeholder_input`
- `needs_adm`
- `other`

Validation notes:

- `topic_actions` is required.
- The current characterization must be `completed`, and the current P6 proposal
  must contain at least one topic. Otherwise Laravel returns HTTP `422` with a
  validation error keyed by `characterization`.
- Keys in `topic_actions`, `action_reasons`, and `action_notes` must be topic
  IDs from the current P6 proposal. Keys must be canonical positive integer
  strings; malformed keys such as `4abc`, `4.9`, `04`, `0`, or negative values
  are rejected.
- `action_notes` values are optional strings with a 300-character limit.
- The response uses the same state shape as `GET /api/materiality-proposal`.

Example request:

```json
{
  "topic_actions": {
    "4": "unsure",
    "1": "accepted"
  },
  "action_reasons": {
    "4": ["needs_adm", "stakeholder_input"]
  },
  "action_notes": {
    "4": "Review this topic during the ADM workshop."
  }
}
```

### `GET /api/double-materiality-guide`

Returns the P7 double materiality guide for the separate frontend. The response
is static, authenticated, deterministic JSON. It does not call Python and does
not decide materiality.

The endpoint intentionally avoids Markdown so the frontend does not have to
parse free-form formatting. It returns structured sections, steps, checks, and
template column definitions that the frontend can render as cards, tables, or
downloads.

Example response shape:

```json
{
  "data": {
    "type": "double_materiality_guide",
    "phase": "P7",
    "content_format": "structured_json",
    "warning": {
      "en": "The guide accelerates the external double materiality assessment; it does not decide materiality.",
      "es": "La guia acelera la ADM externa; no decide la materialidad."
    },
    "sections": [
      {
        "key": "prepare_scope",
        "title": {
          "en": "Prepare scope",
          "es": "Preparar alcance"
        },
        "steps": [
          {
            "key": "review_p5_p6",
            "title": {
              "en": "Review P5 characterization and P6 proposal",
              "es": "Revisar la caracterizacion P5 y la propuesta P6"
            },
            "checks": [
              "Confirm company perimeter, reporting year, sector, size range, and AI-proposed AR16 topics."
            ]
          }
        ]
      }
    ],
    "templates": [
      {
        "key": "iro_register",
        "title": {
          "en": "IRO register",
          "es": "Registro de IROs"
        },
        "columns": [
          "ar16_topic_id",
          "ar16_topic_label",
          "iro_description",
          "iro_type",
          "value_chain_location",
          "stakeholder_or_financial_channel",
          "impact_materiality_score",
          "financial_materiality_score",
          "threshold_result",
          "evidence_reference"
        ]
      },
      {
        "key": "stakeholder_consultation_log",
        "title": {
          "en": "Stakeholder consultation log",
          "es": "Registro de consulta a stakeholders"
        },
        "columns": [
          "date",
          "stakeholder_group",
          "source",
          "matter_or_iro",
          "input_summary",
          "decision_effect",
          "evidence_reference"
        ]
      }
    ],
    "handoff": {
      "next_phase": "P8",
      "next_api": "/api/materiality-confirmation",
      "note": {
        "en": "After the external ADM, return to P8 to confirm final material topics. P9 then derives datapoints from that final selection.",
        "es": "Despues de la ADM externa, vuelve a P8 para confirmar los temas materiales finales. P9 deriva los datapoints desde esa seleccion final."
      }
    }
  }
}
```

### `GET /api/double-materiality-guide/templates/{template}.csv`

Downloads a header-only CSV template for the off-app ADM work. Valid `template`
values:

- `iro_register`
- `stakeholder_consultation_log`

The endpoint is authenticated, deterministic, and does not call Python. The
frontend can offer these files as direct downloads while still rendering the
same column definitions from `GET /api/double-materiality-guide`.

Example response headers for `iro_register`:

```text
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename=iro-register-template.csv
```

Example body:

```csv
ar16_topic_id,ar16_topic_label,iro_description,iro_type,value_chain_location,stakeholder_or_financial_channel,impact_materiality_score,financial_materiality_score,threshold_result,evidence_reference
```

### `GET /api/materiality-confirmation`

Returns the P8 final materiality confirmation state for the current user's
characterization, or `null` if no characterization exists.

If the user has not confirmed P8 yet, `confirmed_topic_ids` defaults to the P6
proposal stored in `esrs_topic_ids`, so the frontend can render an express
confirmation flow without first writing data.

`is_confirmed=false` and `confirmation_status=defaulted_from_p6` mean the
response is only a preview default. `is_confirmed=true` and
`confirmation_status=confirmed` mean the user has explicitly stored a final P8
confirmation, even when the final topic list is empty.

The `preview` block is an orientative P9 consequence preview. It is derived from
the deterministic P9 corpus builder, but remains marked as
`standard_level_partial` until exact AR16 matter -> Disclosure Requirement
filtering exists.

```json
{
  "data": {
    "characterization_id": 1,
    "is_confirmed": false,
    "confirmation_status": "defaulted_from_p6",
    "p6_anchor_date": "2026-06-04T10:00:00.000000Z",
    "p6_topic_ids": [1, 2],
    "confirmed_topic_ids": [1, 2],
    "delta": {
      "added": [],
      "removed": [],
      "unchanged": [1, 2]
    },
    "topics": [
      {
        "id": 1,
        "esrs_code": "E1",
        "theme": { "en": "Environment", "es": "Medioambiente" },
        "subtheme": { "en": "Climate change", "es": "Cambio climatico" },
        "subtopic": { "en": "Adaptation to climate change", "es": "Adaptacion al cambio climatico" }
      }
    ],
    "confirmation": {
      "change_reasons": {},
      "change_reason_notes": {},
      "e1_not_material_explanation": null,
      "confirmed_at": null
    },
    "preview": {
      "material_topic_count": 2,
      "activated_esrs_standards": ["E1", "E2"],
      "datapoint_estimate": {
        "label": "Orientative standard-level estimate",
        "always_required_datapoint_count": 146,
        "topical_datapoint_count": 265,
        "minimum_disclosure_requirement_datapoint_count": 44,
        "total_datapoint_count": 455,
        "voluntary_datapoint_count": 12,
        "conditional_datapoint_count": 23,
        "phase_in_datapoint_count": 8
      },
      "effort_level": "medium",
      "mapping_granularity": "standard_level",
      "coverage_status": "standard_level_partial"
    }
  }
}
```

### `PUT /api/materiality-confirmation`

Stores the user's final P8 material topic list and optional change-reason chips.
The endpoint does not run the Python model. It only synchronizes the ADM result
back into Laravel so P9 can later generate deterministic datapoints.

Requires a completed P6 characterization with at least one proposed topic.
Otherwise the endpoint returns HTTP `422` with a `characterization` validation
error.

Request:

```json
{
  "confirmed_topic_ids": [2, 10],
  "change_reasons": {
    "10": ["stakeholders", "new_data"],
    "1": ["threshold"]
  },
  "change_reason_notes": {
    "10": "Added after stakeholder review"
  },
  "e1_not_material_explanation": "Climate impacts are below the documented ADM threshold."
}
```

Rules:

- `confirmed_topic_ids` must be present and must be an array of existing ESRS
  topic IDs. The array may be empty when the final external ADM finds no
  topical standards material.
- `change_reasons` and `change_reason_notes` object keys must be canonical
  positive topic-id strings from the current P6/P8 union: P6 proposed topics
  plus submitted final P8 topics. Keys such as `0`, negative numbers, `04`,
  suffix strings, or stale unrelated topic IDs return HTTP `422`.
- `change_reasons` values must use controlled keys:
  `new_data`, `stakeholders`, `scope_change`, `threshold`,
  `sector_requirement`, `other`.
- If P6 proposed any E1 topic and final P8 removes all E1 topics,
  `e1_not_material_explanation` is required, max 280 characters.
- The response uses the same P8 state shape as `GET /api/materiality-confirmation`.

### `GET /api/materiality-confirmation/decision-sheet`

Returns a frontend-ready P8 decision sheet summary for the current user's
characterization, or `null` if no characterization exists.

This endpoint does not generate a PDF and does not call Python. It packages the
confirmed P8 materiality state, delta against the P6 proposal, controlled change
reasons, optional E1 non-material explanation, and the same orientative P9
preview used by `GET /api/materiality-confirmation`. The separate original
frontend can render, print, or download this JSON in its own UI.

Example response shape:

```json
{
  "data": {
    "type": "p8_decision_sheet",
    "characterization_id": 1,
    "is_confirmed": true,
    "confirmation_status": "confirmed",
    "company": {
      "name": "Entidad Demo",
      "reporting_year": 2025,
      "nace_code": "A"
    },
    "p6_anchor_date": "2026-06-04T10:00:00.000000Z",
    "confirmed_at": "2026-06-04T11:00:00.000000Z",
    "summary": {
      "p6_topic_count": 2,
      "confirmed_topic_count": 2,
      "added_count": 1,
      "removed_count": 1,
      "unchanged_count": 1,
      "activated_esrs_standards": ["E2", "S1"],
      "p9_total_datapoint_estimate": 455,
      "effort_level": "medium",
      "coverage_status": "standard_level_partial"
    },
    "changes": {
      "added": [
        {
          "id": 10,
          "esrs_code": "S1",
          "theme": { "en": "Social", "es": "Social" },
          "subtheme": { "en": "Own workforce", "es": "Personal propio" },
          "subtopic": { "en": null, "es": null },
          "change_reasons": ["stakeholders", "new_data"],
          "change_reason_note": "Added after stakeholder review."
        }
      ],
      "removed": [],
      "unchanged": []
    },
    "p9_preview": {
      "material_topic_count": 2,
      "activated_esrs_standards": ["E2", "S1"],
      "datapoint_estimate": {
        "label": "Orientative standard-level estimate",
        "always_required_datapoint_count": 146,
        "topical_datapoint_count": 265,
        "minimum_disclosure_requirement_datapoint_count": 44,
        "total_datapoint_count": 455,
        "voluntary_datapoint_count": 12,
        "conditional_datapoint_count": 23,
        "phase_in_datapoint_count": 8
      },
      "effort_level": "medium",
      "mapping_granularity": "standard_level",
      "coverage_status": "standard_level_partial"
    },
    "e1_not_material_explanation": "Climate impacts are below the documented ADM threshold.",
    "note": "These selections reflect the external double materiality assessment. Evidence remains outside the application."
  }
}
```

When no final P8 confirmation is stored yet, the decision sheet uses
`is_confirmed=false`, `confirmation_status=defaulted_from_p6`, `confirmed_at`
as `null`, and the note: `No final P8 confirmation has been stored yet. Values
are defaulted from the P6 proposal for preview only.`

### `GET /api/esrs-datapoints`

Returns the P9 ESRS datapoint corpus for the current user's characterization, or
`null` if no characterization exists.

This endpoint is deterministic and does not call the Python model. It currently
uses the final P8 `confirmed_topic_ids` when present, otherwise the P6
`esrs_topic_ids` fallback, then builds:

- `always_required`: ESRS 2 General Disclosures, always included.
- `topical`: E/S/G datapoints for the activated ESRS standards.
- `minimum_disclosure_requirements`: ESRS 2 MDR datapoints, included as a
  conditional block when at least one topical standard is material.
- `e1_not_material_explanation`: status of the E1 non-material explanation
  requirement when P6 proposed E1 and final P8 removes all E1 topics.

Each datapoint block also includes `disclosure_requirements`: stable groups by
DR key with `standard`, `dr`, `datapoint_count`, and `datapoint_ids`. The
frontend should use these groups to render P9 by Disclosure Requirement without
re-deriving grouping logic client-side.

Each datapoint also includes an `applicability` block so the frontend can show
why that datapoint appears without rebuilding backend mapping logic. It contains
the source block, reason code, human-readable reason, mapping basis, IG3 source
chain, and visible limitations. For topical datapoints,
`mapping_basis=activated_esrs_standard` means the default standard-level
fallback is active; `mapping_basis=mapped_disclosure_requirements` means a
fully covering valid approved AR16 matter -> DR map is loaded.

The response also includes `completion_plan`, a frontend-ready recommended
order for completing P9: ESRS 2 baseline first, topical material standards
second, ESRS 2 MDR review third, and the E1 non-material explanation when it
applies.

The response also includes `matter_mapping`, a per-material-topic coverage
block. By default it tells the frontend that AR16 matter -> DR mapping is still
pending, identifies the current standard-level fallback, and exposes per-topic
standard-level DR/datapoint counts for transparent UI badges. If the backend is
configured with a fully covering approved map through
`ESRS_MATTER_DR_MAPPING_PATH`, and that map has one row per selected topic and
validates against seeded ESRS topics plus IG3 DR keys, this block switches to
`status=loaded`, `coverage_status=dr_level`, and per-topic
`mapped_disclosure_requirement_keys` / `mapped_datapoint_count`. The runtime
DR-level corpus filter uses selected `(ESRS standard, Disclosure Requirement)`
pairs, not a flat DR-key list.

The response also includes `phase_in_assessment`, which evaluates the current
company employee-count data against the ESRS `<750` phase-in threshold. It does
not remove datapoints from the corpus; it tells the frontend which phase-in
relief counts are potentially applicable for planning.

Source: EFRAG IG 3 List of ESRS Data Points workbook, version `2025-06`,
SHA256 `90F15872C489786D86C445D8DC02E00783EB16ECD998D6E1F5A43AD48EDD8BE9`.
EFRAG IG 3 is non-authoritative implementation guidance; ESRS remains the
source of truth if conflicts exist.

Important limitation: without an approved map configured,
`generation.mapping_granularity` is `standard_level` and
`generation.matter_to_dr_mapping_status` is `pending`. That means topical
datapoints are filtered by activated ESRS standard. When a fully covering
approved map is loaded and valid, the same fields become
`mapping_granularity=disclosure_requirement_level`,
`matter_to_dr_mapping_status=loaded`, and `coverage_status=dr_level`.
If the configured map is partial, has duplicate rows for any selected material
topic, or is invalid for any selected material topic, the endpoint keeps
`mapping_granularity=standard_level`, `matter_to_dr_mapping_status=partial`,
and `coverage_status=standard_level_partial` with a visible limitation.

### `GET /api/esrs-datapoints/responses`

Returns the current user's editable P9 datapoint response state, or `null` if
no characterization exists.

The endpoint does not regenerate the corpus in the response body. It validates
stored state against the same current deterministic corpus used by
`GET /api/esrs-datapoints`, so responses for datapoints no longer applicable to
the current P8 selection are omitted from the returned state. The stale stored
keys may remain in `form_data`, but all public P9 response, CSV, and report
handoff surfaces filter to the current corpus.

Example response:

```json
{
  "data": {
    "characterization_id": 1,
    "schema_version": "v0",
    "updated_at": "2026-06-04T14:00:00.000000Z",
    "responses": {
      "BP-1_01": {
        "datapoint_id": "BP-1_01",
        "status": "draft",
        "value": "Prepared on a consolidated basis.",
        "evidence_reference": "Finance pack 2025",
        "updated_at": "2026-06-04T14:00:00.000000Z"
      },
      "E2.IRO-1_01": {
        "datapoint_id": "E2.IRO-1_01",
        "status": "completed",
        "value": "Pollution IRO screening completed.",
        "note": "Reviewed with operations lead.",
        "updated_at": "2026-06-04T14:00:00.000000Z"
      }
    },
    "summary": {
      "applicable_datapoint_count": 455,
      "response_count": 2,
      "completed_count": 1,
      "draft_count": 1,
      "not_applicable_count": 0,
      "completion_ratio": 0.0022,
      "completion_status": "in_progress"
    }
  }
}
```

### `PUT /api/esrs-datapoints/responses`

Persists frontend-entered P9 responses for datapoints that belong to the current
applicable corpus. Laravel stores the state in
`characterizations.form_data.esrs_datapoint_responses`; no Python call is made.

Request body:

```json
{
  "responses": [
    {
      "datapoint_id": "BP-1_01",
      "status": "draft",
      "value": "Prepared on a consolidated basis.",
      "evidence_reference": "Finance pack 2025"
    },
    {
      "datapoint_id": "E2.IRO-1_01",
      "status": "completed",
      "value": "Pollution IRO screening completed.",
      "note": "Reviewed with operations lead."
    }
  ]
}
```

Allowed statuses are `draft`, `completed`, and `not_applicable`. The endpoint
is full replacement for the response map: submit the complete current P9 state
the frontend wants persisted. `responses: []` is valid and explicitly clears
all stored P9 responses. Omitted datapoint rows are removed by the replacement.

The backend trims `datapoint_id` before corpus membership checks and stored
keys. A single whitespace-padded valid ID is accepted, but duplicate canonical
IDs after trimming return `422`. Optional fields (`value`, `evidence_reference`,
and `note`) are sparse: absent, `null`, or empty values are omitted from the
stored response object and returned/exported as empty.

`not_applicable` counts as a decided response for P9 completion and report
readiness, while `completed_count` remains the count of responses whose status
is exactly `completed`.

The endpoint returns the same response-state shape as
`GET /api/esrs-datapoints/responses`. It returns JSON `404` with
`No characterization found.` when the authenticated user has no characterization
state, and `422` when any submitted datapoint ID is outside the current corpus
or a submitted payload contains duplicate canonical datapoint IDs.

### `GET /api/esrs-datapoints/responses/export.csv`

Downloads the current P9 corpus plus frontend-entered response state as a CSV
attachment. The export includes every currently applicable datapoint, even when
the user has not entered a response yet, so the frontend can offer an offline
work packet without reconstructing backend context. Rows for stored datapoint
responses that are no longer in the current corpus are omitted. CSV is produced
with standard `fputcsv` quoting; clients should parse it as CSV rather than by
splitting on newline because response fields can contain commas, quotes, and
newlines.

The endpoint returns JSON `404` with `No characterization found.` when the
authenticated user has no characterization state yet.

Example response headers:

```text
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename=esrs-datapoint-responses.csv
```

CSV columns:

```csv
block_key,disclosure_requirement_key,datapoint_id,standard,dr,name,applicability_reason_code,applicability_reason,applicability_mapping_basis,applicability_limitations,response_status,response_value,evidence_reference,note,response_updated_at
```

### `GET /api/esrs-datapoints/export.csv`

Downloads the current P9 datapoint corpus as a flattened CSV attachment. It
uses the same deterministic builder as `GET /api/esrs-datapoints`, so it shares
the same current mapping granularity and does not call Python.

The endpoint returns JSON `404` with `No characterization found.` when the
authenticated user has no characterization state yet.

Example response headers:

```text
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename=esrs-datapoints.csv
```

CSV columns:

```csv
block_key,block_title,disclosure_requirement_key,datapoint_id,standard,dr,paragraph,related_ar,name,data_type,conditional_or_alternative,may_disclose,appendix_b,phase_in_less_than_750,phase_in_all_undertakings
```

Example response shape:

```json
{
  "data": {
    "characterization_id": 1,
    "material_topic_ids": [2, 10],
    "activated_esrs_standards": ["E2", "S1"],
    "generation": {
      "source_name": "EFRAG IG 3 List of ESRS Data Points",
      "workbook_version": "2025-06",
      "mapping_granularity": "standard_level",
      "matter_to_dr_mapping_status": "pending",
      "coverage_status": "standard_level_partial"
    },
    "matter_mapping": {
      "status": "pending",
      "scope": "ar16_matter_to_disclosure_requirement",
      "coverage_status": "standard_level_partial",
      "current_filter": "activated_esrs_standard",
      "limitation": "No approved AR16 matter to Disclosure Requirement mapping is loaded yet; topical datapoints are included at activated-standard level.",
      "material_topics": [
        {
          "topic_id": 2,
          "esrs_code": "E2",
          "theme": { "en": "Environment", "es": "Medioambiente" },
          "subtheme": { "en": "Pollution", "es": "Contaminacion" },
          "subtopic": { "en": null, "es": null },
          "mapping_status": "pending_explicit_dr_mapping",
          "current_filter": "standard_level",
          "standard_level_disclosure_requirement_count": 7,
          "standard_level_datapoint_count": 69
        }
      ]
    },
    "phase_in_assessment": {
      "status": "eligible_less_than_750",
      "employee_count": {
        "source": "employee_count_range",
        "range": "50_249",
        "estimate": 150,
        "less_than_750": true
      },
      "counts": {
        "less_than_750_relief_datapoint_count": 8,
        "all_undertakings_phase_in_datapoint_count": 0,
        "applicable_phase_in_datapoint_count": 8
      },
      "note": "Phase-in metadata is advisory for planning. ESRS remains authoritative for final applicability and timing."
    },
    "summary": {
      "always_required_datapoint_count": 146,
      "topical_datapoint_count": 265,
      "minimum_disclosure_requirement_datapoint_count": 44,
      "total_datapoint_count": 455
    },
    "completion_plan": {
      "strategy": "baseline_then_material_topics",
      "phases": [
        {
          "sequence": 1,
          "key": "always_required",
          "title": "Complete ESRS 2 general disclosures first",
          "block_key": "always_required",
          "applies": true,
          "standards": ["ESRS 2"],
          "datapoint_count": 146,
          "status": "ready"
        },
        {
          "sequence": 2,
          "key": "topical",
          "title": "Complete topical datapoints for material standards",
          "block_key": "topical",
          "applies": true,
          "standards": ["E2", "S1"],
          "datapoint_count": 265,
          "status": "ready",
          "coverage_status": "standard_level_partial"
        },
        {
          "sequence": 3,
          "key": "minimum_disclosure_requirements",
          "title": "Review ESRS 2 MDR datapoints for material matters",
          "block_key": "minimum_disclosure_requirements",
          "applies": true,
          "standards": ["ESRS 2 MDR"],
          "datapoint_count": 44,
          "status": "conditional"
        },
        {
          "sequence": 4,
          "key": "e1_not_material_explanation",
          "title": "Complete E1 non-material explanation when required",
          "block_key": "e1_not_material_explanation",
          "applies": true,
          "standards": ["E1"],
          "datapoint_count": 0,
          "status": "satisfied"
        }
      ]
    },
    "blocks": {
      "always_required": {
        "standards": ["ESRS 2"],
        "datapoint_count": 146,
        "disclosure_requirements": [
          {
            "key": "BP-1",
            "standard": "ESRS 2",
            "dr": "BP-1",
            "datapoint_count": 6,
            "datapoint_ids": ["BP-1_01", "BP-1_02"]
          }
        ],
        "datapoints": [
          {
            "id": "BP-1_01",
            "standard": "ESRS 2",
            "dr": "BP-1",
            "paragraph": "5 a",
            "name": "Basis for preparation of sustainability statement",
            "data_type": "semi-narrative",
            "applicability": {
              "block_key": "always_required",
              "reason_code": "always_required_esrs_2",
              "reason": "ESRS 2 general disclosure datapoints are included as the baseline sustainability statement corpus.",
              "mapping_basis": "always_required",
              "source_chain": {
                "source_dataset": "EFRAG IG 3 List of ESRS Data Points",
                "esrs_standard": "ESRS 2",
                "disclosure_requirement": "BP-1",
                "datapoint_id": "BP-1_01"
              },
              "limitations": []
            }
          }
        ]
      },
      "topical": {
        "standards": ["E2", "S1"],
        "datapoint_count": 265,
        "disclosure_requirements": [
          {
            "key": "E2.IRO-1",
            "standard": "E2",
            "dr": "E2.IRO-1",
            "datapoint_count": 3,
            "datapoint_ids": ["E2.IRO-1_01", "E2.IRO-1_02", "E2.IRO-1_03"]
          },
          {
            "key": "S1.SBM-3",
            "standard": "S1",
            "dr": "S1.SBM-3",
            "datapoint_count": 11,
            "datapoint_ids": ["S1.SBM-3_01", "S1.SBM-3_02"]
          }
        ]
      },
      "minimum_disclosure_requirements": {
        "standards": ["ESRS 2 MDR"],
        "datapoint_count": 44,
        "disclosure_requirements": []
      },
      "e1_not_material_explanation": {
        "applies": true,
        "status": "satisfied",
        "explanation": "Climate impacts are below the documented ADM threshold."
      }
    }
  }
}
```

### `GET /api/report`

Returns report-package readiness for the future original frontend's final
"Informe" step. This endpoint does not generate the final AI report and does
not call Python. It tells the frontend which upstream blocks are ready, which
download/support endpoints are available, and which blockers remain.

The endpoint returns `data: null` when the current user has no characterization
state.

`next_actions` is an ordered list of endpoints for incomplete upstream workflow
sections. When all inputs are ready and final generation is still pending, it
returns `/api/report/draft`. Download entries always keep `endpoint` and
`content_type`; `status` is `ready`, `incomplete`, or `blocked`, with
`depends_on` and `blocking_sections` identifying the readiness source.

Example response for a completed characterization:

```json
{
  "data": {
    "type": "report_package_readiness",
    "version": "v0",
    "characterization_id": 1,
    "status": "incomplete",
    "sections": {
      "characterization": {
        "status": "ready",
        "endpoint": "/api/characterization"
      },
      "materiality_proposal": {
        "status": "ready",
        "endpoint": "/api/materiality-proposal",
        "topic_count": 1
      },
      "double_materiality_guide": {
        "status": "ready",
        "endpoint": "/api/double-materiality-guide"
      },
      "materiality_confirmation": {
        "status": "ready",
        "endpoint": "/api/materiality-confirmation",
        "is_confirmed": true,
        "confirmation_status": "confirmed",
        "confirmed_topic_count": 1
      },
      "esrs_datapoints": {
        "status": "ready",
        "endpoint": "/api/esrs-datapoints",
        "total_datapoint_count": 421,
        "coverage_status": "standard_level_partial",
        "matter_to_dr_mapping_status": "pending"
      },
      "datapoint_responses": {
        "status": "in_progress",
        "endpoint": "/api/esrs-datapoints/responses",
        "response_count": 2,
        "completed_count": 1,
        "not_applicable_count": 0,
        "decided_count": 1,
        "completion_ratio": 0.0024,
        "total_datapoint_count": 421
      },
      "final_report_generation": {
        "status": "not_implemented",
        "reason_code": "final_report_generation_pending"
      }
    },
    "downloads": {
      "p8_decision_sheet": {
        "endpoint": "/api/materiality-confirmation/decision-sheet",
        "content_type": "application/json",
        "status": "ready",
        "depends_on": ["materiality_confirmation"],
        "blocking_sections": []
      },
      "p9_responses_csv": {
        "endpoint": "/api/esrs-datapoints/responses/export.csv",
        "content_type": "text/csv",
        "status": "incomplete",
        "depends_on": ["esrs_datapoints", "datapoint_responses"],
        "blocking_sections": ["datapoint_responses"]
      },
      "p9_datapoints_csv": {
        "endpoint": "/api/esrs-datapoints/export.csv",
        "content_type": "text/csv",
        "status": "ready",
        "depends_on": ["esrs_datapoints"],
        "blocking_sections": []
      },
      "characterization_summary_pdf": {
        "endpoint": "/characterization/summary?format=pdf",
        "content_type": "application/pdf",
        "status": "ready",
        "depends_on": ["characterization"],
        "blocking_sections": []
      }
    },
    "next_actions": [
      "/api/esrs-datapoints/responses"
    ],
    "limitations": [
      {
        "key": "final_report_generation_pending",
        "message": "Final AI report and XBRL generation are not implemented in this release."
      },
      {
        "key": "exact_ar16_matter_to_dr_mapping_pending",
        "message": "P9 currently uses the documented standard-level partial fallback."
      }
    ]
  }
}
```

### `GET /api/report/draft`

Returns frontend-renderable draft data for the future original frontend's
"Informe" step. The frontend can use this JSON to render a draft report screen
or export flow, while Laravel remains the backend/API and does not generate a
final AI report or XBRL package in this slice.

The endpoint returns `data: null` when the current user has no characterization
state.

Example response for a completed characterization:

```json
{
  "data": {
    "type": "report_draft",
    "version": "v0",
    "characterization_id": 1,
    "generation_status": "frontend_rendered_draft",
    "readiness_status": "incomplete",
    "company": {
      "name": "Entidad Demo",
      "nace_code": "A",
      "status": "completed",
      "reporting_year": 2025,
      "product_service_type": "software_digital_services",
      "employee_count_range": "50_249",
      "revenue_range": "2m_to_10m",
      "regions": ["eu"]
    },
    "materiality": {
      "proposal_source": "p6_ai_candidate_topics",
      "is_confirmed": true,
      "confirmation_status": "confirmed",
      "proposed_topic_count": 1,
      "confirmed_topic_count": 1,
      "confirmed_at": "2026-06-04T15:30:00Z",
      "confirmed_topics": [
        {
          "id": 12,
          "esrs_code": "E2",
          "theme": {
            "en": "Pollution",
            "es": "Contaminacion"
          },
          "subtheme": {
            "en": "Pollution",
            "es": "Contaminacion"
          },
          "subtopic": {
            "en": null,
            "es": null
          }
        }
      ]
    },
    "datapoints": {
      "total_datapoint_count": 421,
      "response_status": "in_progress",
      "response_count": 2,
      "completed_count": 1,
      "not_applicable_count": 0,
      "decided_count": 1,
      "completion_ratio": 0.0024,
      "coverage_status": "standard_level_partial",
      "matter_to_dr_mapping_status": "pending",
      "blocks": [
        {
          "key": "always_required_esrs_2",
          "title": "Always-required ESRS 2 datapoints",
          "datapoint_count": 101,
          "response_count": 1,
          "completed_count": 1,
          "not_applicable_count": 0,
          "decided_count": 1
        }
      ]
    },
    "exports": {
      "report_readiness": {
        "endpoint": "/api/report",
        "content_type": "application/json",
        "status": "ready",
        "depends_on": [],
        "blocking_sections": []
      },
      "p8_decision_sheet": {
        "endpoint": "/api/materiality-confirmation/decision-sheet",
        "content_type": "application/json",
        "status": "ready",
        "depends_on": ["materiality_confirmation"],
        "blocking_sections": []
      }
    },
    "limitations": [
      {
        "key": "final_report_generation_pending",
        "message": "Final AI report and XBRL generation are not implemented in this release."
      }
    ]
  }
}
```

## Response Fields

All mutation endpoints return the same `data` shape as
`GET /api/characterization`.

Important status values:

- `draft`
- `submitted`
- `waiting`
- `processing`
- `failed`
- `timed_out`
- `completed`

Retry lifecycle fields:

- `retry_count`: number of failed gateway attempts for the current submission.
- `next_retry_at`: scheduled retry timestamp when status is `waiting`, otherwise
  `null`.
- `last_job_attempted_at`: latest worker attempt timestamp, or `null` before the
  first attempt.

## Current Tests

Covered by `tests/Feature/Api/CharacterizationApiTest.php`:

- unauthenticated API requests return HTTP `401`;
- progressive disclosure options are returned for the frontend;
- current user state returns JSON;
- draft save returns HTTP `200` and does not dispatch processing;
- optional CSRD orientation and data-readiness blocks persist through the JSON
  API;
- unsupported progressive disclosure option keys return validation errors;
- submit returns HTTP `202` and dispatches processing;
- JSON API submit accepts P5/P6 generation requests without preselected
  `esrs_topic_ids`;
- in-flight submit calls are idempotent and preserve retry metadata;
- completed characterizations reset retry metadata on genuine resubmit;
- private-dev auto-login works for API requests without requiring a Laravel
  login screen.

Covered by `tests/Feature/Api/MaterialityProposalApiTest.php`:

- unauthenticated proposal requests return HTTP `401`;
- users without a characterization get `data: null`;
- completed AI results return normalized proposal topic summaries, candidate
  topic evidence, manual-review prediction keys, raw prediction key count, and
  readiness for P8 confirmation.

Covered by `tests/Feature/Api/DoubleMaterialityGuideApiTest.php`:

- unauthenticated guide requests return HTTP `401`;
- P7 guide content is returned as structured JSON, not Markdown;
- guide sections cover ADM scope preparation, IRO identification, materiality
  assessment, decision documentation, and return to P8;
- template column definitions include IRO register and stakeholder consultation
  log handoff fields;
- private-dev auto-login works for the guide endpoint.

Covered by `tests/Feature/Api/MaterialityConfirmationApiTest.php`:

- unauthenticated confirmation and decision-sheet requests return HTTP `401`;
- P8 confirmation defaults to the P6 proposal when the user has not stored a
  final materiality set yet and marks that response as
  `confirmation_status=defaulted_from_p6`;
- final confirmation requires a completed non-empty P6 proposal;
- final confirmation requires the `confirmed_topic_ids` key but accepts an
  explicit empty array as a valid no-topical-materiality outcome;
- final confirmation rejects malformed, zero, negative, stale, or unrelated
  `change_reasons` / `change_reason_notes` topic keys;
- final P8 confirmation persists added/removed topics, change reasons, and the
  P9 consequence preview;
- stale stored reason keys are filtered from returned confirmation state;
- the decision sheet returns company context, P6 vs P8 counts, delta topic rows,
  change reasons, the E1 explanation, and P9 estimate metadata;
- the unconfirmed decision sheet is marked preview-only instead of final ADM
  evidence;
- removing E1 after P6 proposed E1 requires an explanation, including when the
  final topic list is empty.

Covered by `tests/Feature/CharacterizationTest.php`:

- when the submission job completes with AI `candidate_topics`, Laravel stores
  their `ar16_topic_id` values as the P6 materiality proposal in
  `esrs_topic_ids` and `form_data.esg_focus.topic_ids`.

Covered by `tests/Feature/Api/EsrsDatapointApiTest.php`:

- unauthenticated datapoint corpus requests return HTTP `401`;
- users without a characterization get `data: null`;
- final P8 materiality drives a deterministic standard-level corpus with ESRS 2
  baseline, topical datapoints grouped by Disclosure Requirement, source
  metadata, the E1 exception block, and explicit matter-mapping limitation
  metadata;
- P9 responses include phase-in eligibility metadata from the current
  employee-count range or exact employee count, including counts for `<750`
  relief and all-undertaking phase-in datapoints;
- P9 datapoints include an `applicability` block with reason code, mapping
  basis, source chain, and visible limitations;
- unauthenticated P9 response-state requests return HTTP `401`;
- users without a characterization get `data: null` for P9 response state;
- frontend P9 responses are persisted in characterization `form_data` only when
  submitted datapoints belong to the current deterministic corpus;
- datapoint responses outside the current corpus return HTTP `422`;
- P9 response-state CSV export includes corpus context, applicability fields,
  response status/value/evidence/note, and is advertised by the workflow
  manifest.

Covered by `tests/Feature/Api/ReportApiTest.php`:

- unauthenticated report-readiness requests return HTTP `401`;
- users without a characterization get `data: null`;
- completed characterizations return section readiness, download endpoints,
  datapoint response progress, and honest final-report-generation limitations.
- unauthenticated report-draft requests return HTTP `401`;
- report draft returns frontend-renderable company, materiality, datapoint
  summary, export, and limitation blocks.
