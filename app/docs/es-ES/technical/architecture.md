# Arquitectura técnica

Volver al [índice de documentación](../index.md).

El perfil incluido separa tres componentes:

- frontend Next.js para la interfaz;
- API Laravel para autenticación, estado, catálogos, respuestas y exportaciones;
- servicio FastAPI para propuestas de temas a partir de la caracterización.

Compose publica puertos para evaluación local. Que una URL use localhost en la documentación no garantiza por sí solo aislamiento de red en otro entorno; el operador debe revisar enlaces de puertos, cortafuegos y proxy.

## Almacenamiento

El perfil público usa SQLite temporal y recrea la base al arrancar el componente web. No ofrece persistencia productiva, copias de seguridad ni recuperación.

## Exportaciones

Laravel genera resumen JSON, CSV, HTML, documentos guiados y, cuando procede, un candidato técnico XHTML/iXBRL. Cada salida tiene requisitos y límites propios. La validación técnica no acredita calidad de contenido ni aceptación regulatoria.

## Dependencias ausentes

El repositorio no suministra un despliegue de producción completo, proxy inverso, almacenamiento de objetos, proveedor de correo, proceso de cola persistente, claves OAuth reales ni servicio compatible de extracción documental.
