# IA4Sustainability

IA4Sustainability is a support product for managing end-to-end ESG work (environmental, social and governance; ASG in Spanish) associated with the Corporate Sustainability Reporting Directive (CSRD) and the European Sustainability Reporting Standards (ESRS). It helps characterise the organisation, review proposed matters for double materiality assessment, link confirmed matters to ESRS Disclosure Requirements and indicators/data, and prepare work outputs for the sustainability report.

It does not automatically generate a complete, final or guaranteed ESG or ESRS statement. AI recommends and organises; the organisation carries out and confirms double materiality, validates evidence and decides applicability. Current outputs are work aids: reviewable proposals, decision sheet, CSV lists, preparation and coverage summary, summarised HTML package, guided DOCX document and, under technical and approval conditions, an XHTML/iXBRL candidate. No output replaces assurance, legal opinion, official submission or regulatory acceptance.

## What You Need To Prepare

- Basic organisation, activity, reporting year, size and scope data.
- Internal people able to review impacts, risks, opportunities and evidence.
- Human judgement to perform double materiality, confirm material matters and decide ESRS applicability.
- Indicators, data and evidence references when completing responses.

## Journey

1. Describe the organisation.
2. Review ESG candidate matters proposed by the application for the ESRS framework.
3. Carry out and document double materiality with the responsible team.
4. Confirm material matters and any reasons to record.
5. Gather ESRS indicators/data, mark pending items and justify non-applicable items.
6. Review the preparation summary and download available outputs, including the XHTML/iXBRL candidate where applicable.

## Try Locally

**Warning before starting:** the included local profile recreates its SQLite database when the web component starts. Use fictitious data only. Do not use it for real information or to retain work.

The installation guide is in [app/docs/en-GB/technical/installation-local.md](app/docs/en-GB/technical/installation-local.md). The technical entry point is [app/README.en-GB.md](app/README.en-GB.md).

## Documentation

- [Public documentation in Spanish](app/docs/es-ES/index.md)
- [English documentation](app/docs/en-GB/index.md)
- [Technical manual](app/docs/en-GB/technical/index.md)
- [Integrations](app/docs/en-GB/integrations/authentication.md)

- [Optional learning engine](app/docs/en-GB/learning-engine.md): off locally and in the included composition; on by configuration in deployed environments (`APP_ENV=production|staging` or `LEARNING_DEPLOYED=true`); consent only; manual promotion and rollback in `/admin/learning`; missing prerequisites block it visibly.
- [Learning gate metrics](app/docs/learning-engine-production.md#métricas-del-control-de-promocion): precision, recall, F1 and company-level intervals in the panel; predeclared improvement and positive preservation; `LEARNING_GATE_METRICS_ENABLED=false` by default (Spanish guide).
- [DMA import](app/docs/dma-import.md) (Spanish): `DMA_IMPORT_ENABLED`, off by default.
- [Local IRO text analysis](app/docs/dma-import.md#análisis-local-de-textos-iro) (Spanish): reviewed topic and indicator suggestions; `DMA_NLP_ENABLED=false` in Laravel and `I4S_DMA_NLP_ENABLED=false` in Python; requires reviewed IRO traceability.
- [IRO context in reports](app/docs/dma-import.md#contexto-iro-en-la-presentación-del-informe) (Spanish): HTML, DOCX and XHTML presentation when included in the approved snapshot; requires an integration that supplies the context.
- [Concept relations](app/ai-service/docs/concept-relations.md) (Spanish): `CONCEPT_RELATIONS_ENABLED`, off by default.
- [Informational sector comparison](app/docs/sector-comparison.md) (Spanish): sector support and a low-confidence global fallback during topic review; `SECTOR_COMPARISON_ENABLED=false` in Laravel and `ESRS_SECTOR_COMPARISON_ENABLED=false` in Python.
- [XBRL concept suggestions](app/ai-service/docs/xbrl-concept-mapping.md) (Spanish): `XBRL_SUGGESTION_OVERLAY_ENABLED`, off by default.
- [Runtime XBRL identification](app/ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución): reviewed candidates and frozen evidence, without automatic tagging; `XBRL_RUNTIME_IDENTIFICATION_ENABLED=false` by default.
- [iXBRL candidate package](app/docs/ixbrl-candidate-package.md) (Spanish): `IXBRL_PACKAGE_UI_ENABLED`, off by default.
- [Report scope integrity and presentation](app/docs/report-scope-integrity.md) (Spanish): approvals bound to scope assets and XHTML presentation download; `REPORT_SCOPE_INTEGRITY_ENABLED`, off by default.
- [EU Taxonomy eligibility](app/docs/eu-taxonomy-eligibility.md) (Spanish): `EU_TAXONOMY_ELIGIBILITY_ENABLED`, off by default.
- [Usability study](app/docs/usability-study-protocol.md) (Spanish): `USABILITY_STUDY_ENABLED`, off by default.
- [Local Green Tech measurement](app/ai-service/docs/green-tech-measurement.md) (Spanish): command-line tool; `GREENTECH_MEASUREMENT_ENABLED`, off by default.
