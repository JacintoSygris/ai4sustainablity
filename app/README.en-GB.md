# IA4Sustainability: Technical Package

This directory contains the runnable application: Next.js interface, Laravel API, FastAPI proposal service, contracts and data assets.

Before starting the local profile, remember that the included SQLite database is temporary and is recreated when the web component starts. Use fictitious data only.

## Main Guides

- [Local installation](docs/en-GB/technical/installation-local.md)
- [Architecture](docs/en-GB/technical/architecture.md)
- [Configuration](docs/en-GB/technical/configuration.md)
- [Persistence and migrations](docs/en-GB/technical/operations/persistence-migrations.md)
- [Monitoring and troubleshooting](docs/en-GB/technical/operations/monitoring-troubleshooting.md)
- [Integration contracts](docs/en-GB/integrations/authentication.md)

## Local Profile Services

| Service | Usual URL |
|---|---|
| Interface | `http://localhost:3000` |
| Laravel API | `http://localhost:8000` |
| Proposal service | `http://localhost:8001` |

These URLs are for local evaluation. Port publication, HTTPS, proxy, secrets, persistence, mail, queues and data policies are the responsibility of the operator of an own environment.

- [Optional learning engine](docs/en-GB/learning-engine.md): off locally and in the included composition; on by configuration in deployed environments (`APP_ENV=production|staging` or `LEARNING_DEPLOYED=true`); consent only; manual promotion and rollback in `/admin/learning`; missing prerequisites block it visibly.
- [Learning gate metrics](docs/learning-engine-production.md#métricas-del-control-de-promocion): precision, recall, F1 and company-level intervals in the panel; predeclared improvement and positive preservation; `LEARNING_GATE_METRICS_ENABLED=false` by default (Spanish guide).
- [DMA import](docs/dma-import.md) (Spanish): `DMA_IMPORT_ENABLED`, off by default.
- [Local IRO text analysis](docs/dma-import.md#análisis-local-de-textos-iro) (Spanish): reviewed topic and indicator suggestions; `DMA_NLP_ENABLED=false` in Laravel and `I4S_DMA_NLP_ENABLED=false` in Python; requires reviewed IRO traceability.
- [IRO context in reports](docs/dma-import.md#contexto-iro-en-la-presentación-del-informe) (Spanish): HTML, DOCX and XHTML presentation when included in the approved snapshot; requires an integration that supplies the context.
- [Concept relations](ai-service/docs/concept-relations.md) (Spanish): `CONCEPT_RELATIONS_ENABLED`, off by default.
- [Informational sector comparison](docs/sector-comparison.md) (Spanish): sector support and a low-confidence global fallback during topic review; `SECTOR_COMPARISON_ENABLED=false` in Laravel and `ESRS_SECTOR_COMPARISON_ENABLED=false` in Python.
- [XBRL concept suggestions](ai-service/docs/xbrl-concept-mapping.md) (Spanish): `XBRL_SUGGESTION_OVERLAY_ENABLED`, off by default.
- [Runtime XBRL identification](ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución): reviewed candidates and frozen evidence, without automatic tagging; `XBRL_RUNTIME_IDENTIFICATION_ENABLED=false` by default.
- [iXBRL candidate package](docs/ixbrl-candidate-package.md) (Spanish): `IXBRL_PACKAGE_UI_ENABLED`, off by default.
- [Report scope integrity and presentation](docs/report-scope-integrity.md) (Spanish): approvals bound to scope assets and XHTML presentation download; `REPORT_SCOPE_INTEGRITY_ENABLED`, off by default.
- [EU Taxonomy eligibility](docs/eu-taxonomy-eligibility.md) (Spanish): `EU_TAXONOMY_ELIGIBILITY_ENABLED`, off by default.
- [Usability study](docs/usability-study-protocol.md) (Spanish): `USABILITY_STUDY_ENABLED`, off by default.
- [Local Green Tech measurement](ai-service/docs/green-tech-measurement.md) (Spanish): command-line tool; `GREENTECH_MEASUREMENT_ENABLED`, off by default.
