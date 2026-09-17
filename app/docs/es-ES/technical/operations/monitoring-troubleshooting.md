# Monitorización y diagnóstico

Volver al [índice de documentación](../index.md).

## Salud de Laravel

`GET /healthz` es una comprobación pública de salud. Un HTTP 200 indica que la dependencia de base de datos responde en ese momento. Un HTTP 503 indica degradación de esa dependencia.

El campo de cola es informativo salvo que el operador añada una comprobación más estricta. La respuesta no debe exponer secretos, versiones internas, trazas ni rutas del sistema.

## Comprobaciones locales

Las comprobaciones de interfaz, API y servicio de propuestas indican disponibilidad básica. No verifican envío de correos, calidad de propuestas, persistencia, exportaciones concretas ni cumplimiento.

## Diagnóstico

Si falla una descarga, revisa primero el estado del recorrido, después las dependencias técnicas de esa descarga y finalmente los registros del servicio que la genera. Distingue bloqueo por falta de datos, bloqueo por configuración y fallo de ejecución.
