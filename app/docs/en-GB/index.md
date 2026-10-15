# IA4Sustainability Public Documentation

IA4Sustainability helps manage end-to-end ESG work (environmental, social and governance; ASG in Spanish) related to the Corporate Sustainability Reporting Directive (CSRD) and the European Sustainability Reporting Standards (ESRS). Double materiality determines which matters and ESRS information need to be addressed, but the tool does not replace professional judgement, the materiality decision or the final preparation of a report.

## Choose A Guide

- [Product](product.md): what it is, who it is for, what data it needs and what outputs it produces.
- [Glossary](glossary.md): terms used in the application and technical equivalents.
- [Describe the organisation](usage/organisation.md): initial information required.
- [Review proposed matters](usage/candidate-topics.md): how to treat automatic proposals.
- [Record materiality](usage/materiality.md): the difference between external analysis, records and confirmation.
- [Gather indicators and data](usage/information.md): statuses, coverage and evidence references.
- [Interpret results](usage/results.md): preparation summary, downloads and limits.
- [Capability limits](limits/capabilities.md): available, conditional and not provided.
- [Data and privacy](limits/data-and-privacy.md): data processed and operator responsibility.
- [Methodology](limits/methodology.md): assumptions, catalogues and mappings.
- [Technical manual](technical/index.md): installation, configuration, operations and integrations.

## Optional Features

- [Optional learning engine](learning-engine.md): off locally and in the included composition; on by configuration in deployed environments (`APP_ENV=production|staging` or `LEARNING_DEPLOYED=true`); consent only; manual promotion and rollback in `/admin/learning`; missing prerequisites block it visibly. Operations and deployment: [activation runbook](../learning-activation-runbook.md).
- [Learning gate metrics](../learning-engine-production.md#métricas-del-control-de-promocion): precision, recall, F1 and company-level intervals in the panel; predeclared improvement and positive preservation; `LEARNING_GATE_METRICS_ENABLED=false` by default (Spanish guide).
- [DMA import](../dma-import.md): CSV upload of the IRO register and stakeholder consultations into P8; `DMA_IMPORT_ENABLED`, off by default.
- [Local IRO text analysis](../dma-import.md#análisis-local-de-textos-iro) (Spanish): reviewed topic and indicator suggestions; `DMA_NLP_ENABLED=false` and `I4S_DMA_NLP_ENABLED=false`; requires reviewed IRO traceability.
- [IRO context in reports](../dma-import.md#contexto-iro-en-la-presentación-del-informe) (Spanish): HTML, DOCX and XHTML presentation when included in the approved snapshot; requires an integration that supplies the context.
- [Concept relations](../../ai-service/docs/concept-relations.md): informational suggestions in P8 and P9; `CONCEPT_RELATIONS_ENABLED`, off by default.
- [Informational sector comparison](../sector-comparison.md) (Spanish): sector support and a low-confidence global fallback during topic review; `SECTOR_COMPARISON_ENABLED=false` in Laravel and `ESRS_SECTOR_COMPARISON_ENABLED=false` in Python.
- [XBRL concept suggestions](../../ai-service/docs/xbrl-concept-mapping.md): operator review of candidates; `XBRL_SUGGESTION_OVERLAY_ENABLED`, off by default.
- [Runtime XBRL identification](../../ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución): reviewed candidates and frozen evidence, without automatic tagging; `XBRL_RUNTIME_IDENTIFICATION_ENABLED=false` by default.
- [iXBRL candidate package](../ixbrl-candidate-package.md): technical package from an approved version; `IXBRL_PACKAGE_UI_ENABLED`, off by default.
- [Report scope integrity and presentation](../report-scope-integrity.md): approvals bound to scope assets and XHTML presentation download; `REPORT_SCOPE_INTEGRITY_ENABLED`, off by default.
- [EU Taxonomy eligibility](../eu-taxonomy-eligibility.md): indicative screening, not alignment; `EU_TAXONOMY_ELIGIBILITY_ENABLED`, off by default.
- [Usability study](../usability-study-protocol.md): consent-based kit; `USABILITY_STUDY_ENABLED`, off by default.
- [Local Green Tech measurement](../../ai-service/docs/green-tech-measurement.md): command-line tool; `GREENTECH_MEASUREMENT_ENABLED`, off by default.
- [P5 → model encoding](../fix-ai-encoding.md): sector, product, region and size mappings to the included model; no activation variable.

The guides linked outside this folder are in Spanish.

## Languages

The primary source is Spanish from Spain. This folder is the equivalent English version. API identifiers, routes, variables and official names are left untranslated where appropriate.
