# IA4Sustainability

IA4Sustainability es un producto de apoyo para gestionar de principio a fin el trabajo ASG (ambiental, social y de gobernanza; ESG en inglés) asociado a la Directiva sobre información corporativa en materia de sostenibilidad (CSRD) y a las Normas Europeas de Información sobre Sostenibilidad (NEIS; ESRS en inglés). Ayuda a caracterizar la organización, revisar asuntos propuestos para el análisis de doble importancia relativa, vincular los asuntos confirmados con requisitos de información y datos NEIS, y preparar salidas de trabajo para el informe de sostenibilidad.

No genera automáticamente una memoria ASG o NEIS completa, final ni garantizada. La IA recomienda y ordena; la organización realiza y confirma el análisis de doble importancia relativa, valida evidencias y decide la aplicabilidad. Las salidas actuales son apoyo de trabajo: propuestas revisables, hoja de decisión, listas CSV, resumen de preparación y cobertura, paquete HTML resumido, documento DOCX guiado y, bajo condiciones técnicas y de aprobación, un candidato XHTML/iXBRL. Ninguna salida sustituye aseguramiento, opinión legal, presentación oficial ni aceptación regulatoria.

## Qué necesitas preparar

- Datos básicos de la organización, actividad, ejercicio, tamaño y alcance.
- Personas internas que puedan revisar impactos, riesgos, oportunidades y evidencias.
- Criterio humano para realizar el análisis de doble importancia relativa, confirmar asuntos materiales y decidir la aplicabilidad de las NEIS.
- Indicadores, datos y referencias de evidencia cuando se completen respuestas.

## Recorrido

1. Describir la organización.
2. Revisar asuntos ASG candidatos propuestos por la aplicación para el marco NEIS.
3. Realizar y documentar el análisis de doble importancia relativa con el equipo responsable.
4. Confirmar asuntos materiales y motivos que se quieran registrar.
5. Reunir datos NEIS, marcar pendientes y justificar los no aplicables.
6. Consultar el resumen de preparación y descargar las salidas disponibles, incluido el candidato XHTML/iXBRL cuando proceda.

## Probar en local

**Aviso antes de arrancar:** el perfil local incluido recrea su base SQLite al iniciar el componente web. Usa solo datos ficticios. No lo uses para información real ni para conservar trabajo.

La guía de instalación está en [app/docs/es-ES/technical/installation-local.md](app/docs/es-ES/technical/installation-local.md). La entrada técnica está en [app/README.md](app/README.md).

## Documentación

- [Documentación pública en español](app/docs/es-ES/index.md)
- [Documentación en inglés](README.en-GB.md)
- [Idiomas y terminología](app/docs/localization-first-slice.md)
- [Manual técnico](app/docs/es-ES/technical/index.md)
- [Integraciones](app/docs/es-ES/integrations/authentication.md)

- [Motor de aprendizaje opcional](app/docs/es-ES/learning-engine.md): apagado en local y en la composición incluida; activo por configuración en entornos desplegados (`APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`); solo con consentimiento; promoción y reversión manuales en `/admin/learning`; los requisitos ausentes lo bloquean de forma visible.
- [Métricas del control de aprendizaje](app/docs/learning-engine-production.md#métricas-del-control-de-promocion): precisión, sensibilidad, F1 e intervalos por empresa en el panel; mejora predeclarada y conservación de positivos; `LEARNING_GATE_METRICS_ENABLED=false` por defecto.
- [Importación del ADM](app/docs/dma-import.md): `DMA_IMPORT_ENABLED`, apagado por defecto.
- [Análisis local de textos IRO](app/docs/dma-import.md#análisis-local-de-textos-iro): propuestas revisadas de temas e indicadores; `DMA_NLP_ENABLED=false` en Laravel e `I4S_DMA_NLP_ENABLED=false` en Python; requiere trazabilidad IRO revisada.
- [Contexto IRO en informes](app/docs/dma-import.md#contexto-iro-en-la-presentación-del-informe): presentación en HTML, DOCX y XHTML si consta en la instantánea aprobada; requiere una integración que aporte ese contexto.
- [Relaciones entre conceptos](app/ai-service/docs/concept-relations.md): `CONCEPT_RELATIONS_ENABLED`, apagado por defecto.
- [Comparación sectorial informativa](app/docs/sector-comparison.md): soporte por sector y referencia global de baja confianza durante la revisión de temas; `SECTOR_COMPARISON_ENABLED=false` en Laravel y `ESRS_SECTOR_COMPARISON_ENABLED=false` en Python.
- [Sugerencias de conceptos XBRL](app/ai-service/docs/xbrl-concept-mapping.md): `XBRL_SUGGESTION_OVERLAY_ENABLED`, apagado por defecto.
- [Identificación XBRL en ejecución](app/ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución): candidatos revisados y evidencia congelada, sin etiquetado automático; `XBRL_RUNTIME_IDENTIFICATION_ENABLED=false` por defecto.
- [Paquete candidato iXBRL](app/docs/ixbrl-candidate-package.md): `IXBRL_PACKAGE_UI_ENABLED`, apagado por defecto.
- [Integridad del alcance y presentación del informe](app/docs/report-scope-integrity.md): aprobación vinculada a los activos y descarga XHTML de presentación; `REPORT_SCOPE_INTEGRITY_ENABLED`, apagado por defecto.
- [Elegibilidad en la Taxonomía de la UE](app/docs/eu-taxonomy-eligibility.md): `EU_TAXONOMY_ELIGIBILITY_ENABLED`, apagado por defecto.
- [Estudio de usabilidad](app/docs/usability-study-protocol.md): `USABILITY_STUDY_ENABLED`, apagado por defecto.
- [Medición local Green Tech](app/ai-service/docs/green-tech-measurement.md): herramienta de línea de comandos; `GREENTECH_MEASUREMENT_ENABLED`, apagada por defecto.
