# Datos y privacidad

Volver al [índice de documentación](../index.md).

La documentación pública describe el comportamiento del repositorio y del perfil local incluido. Una instalación operada por otra entidad necesita su propia política de privacidad, responsable, datos de contacto, base jurídica, plazos de conservación y medidas técnicas.

## Perfil local incluido

El perfil de Compose usa una base SQLite temporal en memoria de contenedor y el arranque de Laravel recrea las tablas. Por tanto, los datos no deben considerarse persistentes. Usa solo datos ficticios.

## Datos tratados por la aplicación

Puede tratar datos de cuenta, correo electrónico, contraseña protegida mediante resumen criptográfico, perfil de organización, decisiones de materialidad, respuestas, referencias de evidencia y metadatos técnicos de exportación.

## Documentos

La carga documental está desactivada en el perfil público y falta el servicio de extracción compatible. Si un operador activa una capacidad documental propia, debe explicar qué archivos se almacenan, qué extracciones se conservan, cómo se eliminan y qué registros pueden permanecer.

## Eliminación y conservación

No deben prometerse borrados absolutos sin comprobar la configuración real. Puede haber registros de cuenta, metadatos, copias de seguridad, trazas o referencias conservadas por motivos técnicos o legales. En el perfil local, reiniciar el componente web recrea la base y elimina los datos de prueba, pero eso no equivale a una política de borrado aplicable a producción.
