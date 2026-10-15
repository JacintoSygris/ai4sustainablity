# Datos y privacidad

Volver al [índice de documentación](../index.md).

La documentación pública describe el comportamiento del repositorio y del perfil local incluido. Una instalación operada por otra entidad necesita su propia política de privacidad, responsable, datos de contacto, base jurídica, plazos de conservación y medidas técnicas.

## Perfil local incluido

El perfil de Compose usa una base SQLite temporal en memoria de contenedor y el arranque de Laravel recrea las tablas. Por tanto, los datos no deben considerarse persistentes. Usa solo datos ficticios.

## Datos tratados por la aplicación

Puede tratar datos de cuenta, correo electrónico, contraseña protegida mediante resumen criptográfico, perfil de organización, decisiones de materialidad, respuestas, referencias de evidencia y metadatos técnicos de exportación.

## Documentos

La carga documental está desactivada en el perfil público y falta el servicio de extracción compatible. Si un operador activa una capacidad documental propia, debe explicar qué archivos se almacenan, qué extracciones se conservan, cómo se eliminan y qué registros pueden permanecer.

## Finalidades opcionales con consentimiento

Estas finalidades están apagadas en el perfil local. Solo tratan datos de cuentas que han dado un consentimiento específico, separado de la aceptación del servicio, y que pueden retirarlo sin perder el acceso.

- **Aprendizaje a partir de las decisiones** (versión de consentimiento `learning-feedback-bandit-2026-10-08`, finalidad `feedback:supervised-learning`). Se activa por configuración en una instalación declarada (`APP_ENV=production|staging` o `LEARNING_DEPLOYED=true`) y solo admite cuentas con correo verificado. Se gestiona en `/profile`. Usa el perfil estructurado de la organización y las decisiones P8/P9 con identificadores seudónimos, sin nombres, correos, documentos, notas ni valores del informe. Los datos seudonimizados no son anónimos. Eventos, candidatos y registros operativos se conservan un máximo de 30 días; las exportaciones temporales se borran al terminar y las interrumpidas a los 20 minutos. La retirada excluye eventos futuros, bloquea el uso en un máximo de 90 segundos y desactiva las versiones afectadas; no existe una resta exacta de una contribución individual. Véase [Motor de aprendizaje opcional](../learning-engine.md).
- **Estudio de usabilidad** (solo con `USABILITY_STUDY_ENABLED=true`, sea o no una instalación declarada). Exige una cuenta autenticada y su consentimiento explícito, y un correo verificado solo cuando `AUTH_REQUIRE_EMAIL_VERIFICATION=true`. Recoge un código aleatorio vinculado a la cuenta, el consentimiento, inicios, finalizaciones y abandonos de tareas, fechas, errores visibles, respuestas SUS y comentarios opcionales. No recoge respuestas de la empresa, pulsaciones, grabaciones ni documentos. Se conserva hasta la retirada o hasta el borrado al cierre del estudio, como máximo 90 días desde la inscripción. Véase el [protocolo del estudio](../../usability-study-protocol.md) (en castellano).

## Módulos opcionales que almacenan datos

- **Importación del ADM** (`DMA_IMPORT_ENABLED`): conserva las filas importadas, las sugerencias, el nombre original y el SHA-256 de cada archivo, su tamaño, la fecha y el usuario. Se elimina en cascada con la caracterización o la cuenta.
- **Elegibilidad en la Taxonomía de la UE** (`EU_TAXONOMY_ELIGIBILITY_ENABLED`): conserva la declaración más reciente de actividades y porcentajes, con su contexto de origen. Se elimina con la caracterización.

El responsable de una instalación debe completar su información de privacidad, encargados, ubicación, derechos y evaluación de riesgos antes de activar estas funciones.

## Eliminación y conservación

No deben prometerse borrados absolutos sin comprobar la configuración real. Puede haber registros de cuenta, metadatos, copias de seguridad, trazas o referencias conservadas por motivos técnicos o legales. En el perfil local, reiniciar el componente web recrea la base y elimina los datos de prueba, pero eso no equivale a una política de borrado aplicable a producción.
