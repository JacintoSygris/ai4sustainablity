# Persistencia y migraciones

Volver al [índice de documentación](../index.md).

El perfil local ejecuta recreación de base al arrancar el componente web. Esta decisión simplifica la evaluación, pero destruye los datos de prueba.

## Evaluación local

- base SQLite en ubicación temporal;
- migraciones y datos iniciales recreados en cada arranque;
- sesiones y colas configuradas para uso local;
- sin garantías de conservación.

## Entorno propio

Un operador que quiera conservar datos debe diseñar una base persistente y revisar el proceso de arranque. Añadir un volumen por sí solo no basta si el arranque sigue recreando tablas.

## Migraciones

Las migraciones preparan estructura de base. No son una garantía de calidad de datos, continuidad, copias de seguridad ni compatibilidad con datos antiguos fuera del proceso probado por el operador.
