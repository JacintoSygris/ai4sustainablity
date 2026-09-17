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
