# Idiomas y terminología del producto

La aplicación dispone de castellano (`es`) e inglés (`en`). El castellano es el idioma predeterminado y de reserva. La persona usuaria puede cambiarlo mediante el selector de idioma; la preferencia se conserva en su sesión y en una cookie cifrada por Laravel.

## Superficies localizadas

La presentación del idioma seleccionado comprende navegación, autenticación, caracterización, opciones y errores, guía de doble importancia relativa, revisión de temas, datos normativos, ayudas, estados, tablas, imágenes, informes y descargas. El catálogo de presentación castellana incluye 1184 etiquetas de datos y 99 títulos de requisitos de información.

En castellano se emplean NEIS, doble importancia relativa, gastos de capital, gastos operativos y Protocolo de Gases de Efecto Invernadero. Los nombres propios y los identificadores normativos necesarios para interoperar se conservan. En inglés se mantiene la terminología inglesa correspondiente.

## Datos y autoridad

La localización no modifica claves, identificadores, esquemas, rutas, enumeraciones, unidades de máquina, valores, hechos, decisiones, borradores, instantáneas aprobadas ni evidencias. Tampoco traduce contenido libre introducido por usuarios. La capa de presentación no sustituye el catálogo canónico ni la autoridad del servidor.

Las lecturas asíncronas descartan respuestas de un idioma anterior. Si falla un cambio de idioma, se conserva la preferencia anterior y no se pierden los cambios en curso. Las descargas localizadas se bloquean mientras se confirma el cambio.

La edición pública conserva sus hechos estructurados y dimensiones, campos adicionales de exportación, identificación LEI, flujo de taxonomía externa y guía con `next_step`. Los modelos suministrados no cambian por seleccionar un idioma.

## Descargas e informes

Las exportaciones localizadas utilizan cabeceras y etiquetas del idioma activo, conservando el contenido de usuario y la neutralización de fórmulas de hoja de cálculo. La exportación heredada destinada a interoperabilidad mantiene su formato de máquina; no debe confundirse con la descarga localizada.

HTML, DOCX y otras presentaciones de informe proyectan las etiquetas sin alterar los hechos ni el paquete de evidencias. Las salidas electrónicas siguen siendo candidatos técnicos sujetos a sus controles: un idioma completo no acredita presentación oficial, aseguramiento ni aceptación regulatoria.

## Configuración

Use `APP_LOCALE=es` y `APP_FALLBACK_LOCALE=es` como valores de partida; se aceptan solamente `es` y `en` como preferencia de usuario. La ruta `/api/locale` conserva los controles de sesión, CSRF, limitación de peticiones y caché privada. No use `Accept-Language` ni parámetros arbitrarios como autoridad para cambiar datos.

La instalación sigue las guías de esta edición: [castellano](es-ES/index.md) e [inglés](en-GB/index.md). Publicar código no actualiza por sí solo una instalación ni activa aprendizaje con datos reales. Toda instalación debe conservar su entorno, configuración privada, controles de acceso y requisitos de taxonomía externa.
