# Seguridad

Volver al [índice de documentación](../index.md).

La seguridad efectiva depende de la configuración del operador. El repositorio incluye piezas útiles, pero no una postura completa para producción.

## Controles disponibles

- autenticación con sesión;
- protección CSRF en formularios;
- verificación de correo cuando se activa;
- campo antibot y Turnstile cuando se configura;
- mensajes de validación localizados;
- cabeceras y proxy configurables en el entorno de despliegue.

## Controles no cubiertos por el perfil local

- secretos reales y rotación;
- políticas de acceso por organización;
- cifrado de almacenamiento gestionado por el operador;
- registro centralizado y detección de incidentes;
- escaneo documental operativo;
- revisión de dependencias y hardening del sistema anfitrión.

No introduzcas secretos en documentación ni en plantillas versionadas.
