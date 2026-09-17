# Configuración

Volver al [índice de documentación](../index.md).

Las plantillas de entorno son orientativas. No contienen secretos reales y no deben copiarse a producción sin revisión.

## Perfiles

| Perfil | Uso | Rasgos |
|---|---|---|
| Compose público | Evaluación local | SQLite temporal, correo a log, OAuth desactivado, documentos desactivados, servicio de propuestas por API interna |
| Desarrollo manual | Trabajo técnico local | Puede usar simulación de predicción y base local persistente si el operador la configura |
| Entorno propio | Operación fuera del repositorio | Requiere secretos, HTTPS, base persistente, correo, colas, monitorización y política de datos |

## Variables clave

- `APP_URL`: origen público de Laravel. Debe coincidir con proxy, cookies y redirecciones.
- `NEXT_PUBLIC_LARAVEL_API_BASE_URL`: ruta visible desde navegador hacia la API.
- `LARAVEL_API_ORIGIN`: origen servidor a servidor usado por el frontend.
- `CHARACTERIZATION_GATEWAY`: selecciona simulación o servicio API para propuestas.
- `CHARACTERIZATION_API_BASE_URL`: dirección del servicio de propuestas cuando se usa API.
- `CHARACTERIZATION_AI_MODEL_PROFILE`: perfil técnico del modelo incluido.
- `CHARACTERIZATION_PREDICTION_MAPPING_PATH`: correspondencia opcional para interpretar claves del modelo.
- `ESRS_MATTER_DR_MAPPING_PATH`: correspondencia opcional entre temas y requisitos de divulgación.
- `AUTH_REQUIRE_EMAIL_VERIFICATION`, `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET`: controles de registro dependientes del operador.

Una variable configurada no convierte una capacidad en garantía de negocio. Solo cambia el comportamiento técnico descrito.
