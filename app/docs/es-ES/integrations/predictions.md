# Integración de propuestas

Volver al [índice de documentación](../index.md).

El servicio de propuestas recibe caracterización normalizada y devuelve claves candidatas. Laravel las convierte mediante correspondencias configuradas antes de mostrarlas como temas revisables.

## Superficie técnica

- `POST /predict`
- `GET /healthz`
- `GET /model-profiles`

En el perfil incluido se usa el perfil técnico `new_format_732_v1_gpt41`.

## Reglas de interpretación

- La salida propone temas candidatos, no materialidad final.
- Una respuesta sin candidatos puede ser válida.
- Las claves desconocidas o de revisión no deben convertirse automáticamente en decisiones.
- El prefijo de una clave no basta para crear un tema.
- La selección final debe confirmarla la persona usuaria.

Una integración debe conservar esta separación en sus pantallas y mensajes.
