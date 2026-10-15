# Documentación pública de IA4Sustainability

IA4Sustainability ayuda a gestionar de principio a fin el trabajo ASG (ambiental, social y de gobernanza; ESG en inglés) relacionado con la Directiva sobre información corporativa en materia de sostenibilidad (CSRD) y las Normas Europeas de Información sobre Sostenibilidad (ESRS). La doble materialidad determina qué asuntos y qué información ESRS deben tratarse, pero la herramienta no sustituye el criterio profesional, la decisión de materialidad ni la preparación final de un informe.

## Elegir una guía

- [Producto](product.md): qué es, para quién sirve, qué datos pide y qué salidas produce.
- [Glosario](glossary.md): términos usados en la aplicación y equivalencias técnicas.
- [Describir la organización](usage/organisation.md): información inicial necesaria.
- [Revisar asuntos propuestos](usage/candidate-topics.md): cómo tratar las propuestas automáticas.
- [Registrar materialidad](usage/materiality.md): diferencia entre análisis externo, registro y confirmación.
- [Reunir indicadores y datos](usage/information.md): estados, cobertura y referencias de evidencia.
- [Interpretar resultados](usage/results.md): resumen de preparación, descargas y límites.
- [Límites de capacidad](limits/capabilities.md): disponible, condicionado y no ofrecido.
- [Datos y privacidad](limits/data-and-privacy.md): datos tratados y responsabilidad del operador.
- [Metodología](limits/methodology.md): supuestos, catálogos y correspondencias.
- [Manual técnico](technical/index.md): instalación, configuración, operación e integraciones.

## Funciones opcionales

- [Motor de aprendizaje opcional](learning-engine.md): apagado en local y en la composición incluida; activo por configuración en entornos desplegados (`APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`); solo con consentimiento; promoción y reversión manuales en `/admin/learning`; los requisitos ausentes lo bloquean de forma visible. Operación y despliegue: [runbook de activación](../learning-activation-runbook.md).
- [Métricas del control de aprendizaje](../learning-engine-production.md#métricas-del-control-de-promocion): precisión, sensibilidad, F1 e intervalos por empresa en el panel; mejora predeclarada y conservación de positivos; `LEARNING_GATE_METRICS_ENABLED=false` por defecto.
- [Importación del ADM](../dma-import.md): carga CSV del registro IRO y de consultas hacia P8; `DMA_IMPORT_ENABLED`, apagado por defecto.
- [Análisis local de textos IRO](../dma-import.md#análisis-local-de-textos-iro): propuestas revisadas de temas e indicadores; `DMA_NLP_ENABLED=false` e `I4S_DMA_NLP_ENABLED=false`; requiere trazabilidad IRO revisada.
- [Contexto IRO en informes](../dma-import.md#contexto-iro-en-la-presentación-del-informe): presentación en HTML, DOCX y XHTML si consta en la instantánea aprobada; requiere una integración que aporte ese contexto.
- [Relaciones entre conceptos](../../ai-service/docs/concept-relations.md): sugerencias informativas en P8 y P9; `CONCEPT_RELATIONS_ENABLED`, apagado por defecto.
- [Comparación sectorial informativa](../sector-comparison.md): soporte por sector y referencia global de baja confianza durante la revisión de temas; `SECTOR_COMPARISON_ENABLED=false` en Laravel y `ESRS_SECTOR_COMPARISON_ENABLED=false` en Python.
- [Sugerencias de conceptos XBRL](../../ai-service/docs/xbrl-concept-mapping.md): revisión de candidatos por un operador; `XBRL_SUGGESTION_OVERLAY_ENABLED`, apagado por defecto.
- [Identificación XBRL en ejecución](../../ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución): candidatos revisados y evidencia congelada, sin etiquetado automático; `XBRL_RUNTIME_IDENTIFICATION_ENABLED=false` por defecto.
- [Paquete candidato iXBRL](../ixbrl-candidate-package.md): paquete técnico desde una versión aprobada; `IXBRL_PACKAGE_UI_ENABLED`, apagado por defecto.
- [Integridad del alcance y presentación del informe](../report-scope-integrity.md): aprobación vinculada a los activos y descarga XHTML de presentación; `REPORT_SCOPE_INTEGRITY_ENABLED`, apagado por defecto.
- [Elegibilidad en la Taxonomía de la UE](../eu-taxonomy-eligibility.md): cribado orientativo, no alineamiento; `EU_TAXONOMY_ELIGIBILITY_ENABLED`, apagado por defecto.
- [Estudio de usabilidad](../usability-study-protocol.md): kit con consentimiento; `USABILITY_STUDY_ENABLED`, apagado por defecto.
- [Medición local Green Tech](../../ai-service/docs/green-tech-measurement.md): herramienta de línea de comandos; `GREENTECH_MEASUREMENT_ENABLED`, apagada por defecto.
- [Codificación P5 → modelo](../fix-ai-encoding.md): correspondencias de sector, producto, región y tamaño con el modelo incluido; sin variable de activación.

Las guías enlazadas fuera de esta carpeta están en castellano.

## Idiomas

La fuente primaria está en español de España. La carpeta [en-GB](../en-GB/index.md) contiene una versión equivalente en inglés. Los identificadores de API, rutas, variables y nombres oficiales se conservan sin traducir cuando corresponde.
