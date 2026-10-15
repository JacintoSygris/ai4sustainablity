# IA4Sustainability: paquete técnico

Este directorio contiene la aplicación ejecutable: interfaz Next.js, API Laravel, servicio de propuestas FastAPI, contratos y activos de datos.

Antes de arrancar el perfil local, recuerda que la base SQLite incluida es temporal y se recrea al iniciar el componente web. Usa solo datos ficticios.

## Guias principales

- [Instalación local](docs/es-ES/technical/installation-local.md)
- [Arquitectura](docs/es-ES/technical/architecture.md)
- [Configuración](docs/es-ES/technical/configuration.md)
- [Persistencia y migraciones](docs/es-ES/technical/operations/persistence-migrations.md)
- [Monitorizacion y diagnostico](docs/es-ES/technical/operations/monitoring-troubleshooting.md)
- [Contratos de integración](docs/es-ES/integrations/authentication.md)

## Servicios del perfil local

| Servicio | URL habitual |
|---|---|
| Interfaz | `http://localhost:3000` |
| API Laravel | `http://localhost:8000` |
| Servicio de propuestas | `http://localhost:8001` |

Estas URL sirven para evaluación local. La públicacion de puertos, HTTPS, proxy, secretos, persistencia, correo, colas y políticas de datos corresponden al operador de un entorno propio.

- [Motor de aprendizaje opcional](docs/es-ES/learning-engine.md): apagado en local y en la composición incluida; activo por configuración en entornos desplegados (`APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`); solo con consentimiento; promoción y reversión manuales en `/admin/learning`; los requisitos ausentes lo bloquean de forma visible.
- [Métricas del control de aprendizaje](docs/learning-engine-production.md#métricas-del-control-de-promocion): precisión, sensibilidad, F1 e intervalos por empresa en el panel; mejora predeclarada y conservación de positivos; `LEARNING_GATE_METRICS_ENABLED=false` por defecto.
- [Importación del ADM](docs/dma-import.md): `DMA_IMPORT_ENABLED`, apagado por defecto.
- [Análisis local de textos IRO](docs/dma-import.md#análisis-local-de-textos-iro): propuestas revisadas de temas e indicadores; `DMA_NLP_ENABLED=false` en Laravel e `I4S_DMA_NLP_ENABLED=false` en Python; requiere trazabilidad IRO revisada.
- [Contexto IRO en informes](docs/dma-import.md#contexto-iro-en-la-presentación-del-informe): presentación en HTML, DOCX y XHTML si consta en la instantánea aprobada; requiere una integración que aporte ese contexto.
- [Relaciones entre conceptos](ai-service/docs/concept-relations.md): `CONCEPT_RELATIONS_ENABLED`, apagado por defecto.
- [Comparación sectorial informativa](docs/sector-comparison.md): soporte por sector y referencia global de baja confianza durante la revisión de temas; `SECTOR_COMPARISON_ENABLED=false` en Laravel y `ESRS_SECTOR_COMPARISON_ENABLED=false` en Python.
- [Sugerencias de conceptos XBRL](ai-service/docs/xbrl-concept-mapping.md): `XBRL_SUGGESTION_OVERLAY_ENABLED`, apagado por defecto.
- [Identificación XBRL en ejecución](ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución): candidatos revisados y evidencia congelada, sin etiquetado automático; `XBRL_RUNTIME_IDENTIFICATION_ENABLED=false` por defecto.
- [Paquete candidato iXBRL](docs/ixbrl-candidate-package.md): `IXBRL_PACKAGE_UI_ENABLED`, apagado por defecto.
- [Integridad del alcance y presentación del informe](docs/report-scope-integrity.md): aprobación vinculada a los activos y descarga XHTML de presentación; `REPORT_SCOPE_INTEGRITY_ENABLED`, apagado por defecto.
- [Elegibilidad en la Taxonomía de la UE](docs/eu-taxonomy-eligibility.md): `EU_TAXONOMY_ELIGIBILITY_ENABLED`, apagado por defecto.
- [Estudio de usabilidad](docs/usability-study-protocol.md): `USABILITY_STUDY_ENABLED`, apagado por defecto.
- [Medición local Green Tech](ai-service/docs/green-tech-measurement.md): herramienta de línea de comandos; `GREENTECH_MEASUREMENT_ENABLED`, apagada por defecto.
