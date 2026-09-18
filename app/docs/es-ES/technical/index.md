# Manual tecnico de autohospedaje y operacion

Volver al [indice de documentacion](../index.md).

Este manual describe como preparar, instalar, configurar, poner en marcha, verificar, mantener y recuperar una instalacion propia de IA4Sustainability con los artefactos publicos incluidos en este repositorio. La fuente primaria esta en espanol de Espana; la version [en ingles](../../en-GB/technical/index.md) es equivalente.

La arquitectura de referencia de produccion es:

```text
Internet -> Caddy (80/443, TLS) -> Next.js publico -> Laravel privado
                                                |-> PostgreSQL privado
                                                |-> Redis privado
                                                |-> worker Laravel privado
                                                |-> volumen documental local
                                                |-> FastAPI IA privado
```

`app/compose.public.yml` no es produccion. Expone servicios internos, usa SQLite efimera y el arranque de Laravel ejecuta `php artisan migrate:fresh --seed --force`, que destruye datos.

## Recorrido

1. [Indice y alcance](scope.md): que se puede instalar hoy, precondiciones y matriz incluido/provisionado/bloqueado.
2. [Arquitectura](architecture.md): redes, puertos, exposicion publica/privada y flujos.
3. [Preparacion del servidor](server-preparation.md): sistema, DNS, firewall, usuario de servicio, volumenes y recursos.
4. [Secretos y parametros](secrets-and-parameters.md): creacion, almacenamiento, rotacion y variables.
5. [Base de datos y persistencia](database-persistence.md): PostgreSQL, Redis, volumen documental y migraciones.
6. [Servicios y despliegue](services-deployment.md): build, arranque, migraciones, `optimize`, worker y healthchecks.
7. [Proxy y TLS](proxy-tls.md): Caddyfile de referencia, cabeceras, limite de cuerpo y validacion.
8. [Correo y OAuth](mail-oauth.md): SMTP, Google Cloud, Microsoft Entra y errores comunes.
9. [Operacion](operations.md): colas, registros, observabilidad, actualizaciones y acciones ante error.
10. [Backup y recuperacion](backup-recovery.md): copias, restauracion, RPO/RTO y verificacion posterior.
11. [IA, mapping y reporting](ai-mapping-reporting.md): FastAPI, mapeo ESRS, P9/P10, Arelle e iXBRL candidato.
12. [Taxonomía EFRAG externa](external-efrag-taxonomy.md): ZIP separado, manifiesto privado, checksum y validación offline.
13. [Extraccion documental](document-extraction.md): bloqueo funcional, contrato minimo y criterio de aceptacion.
14. [Aceptacion y diagnostico](acceptance-diagnostics.md): checklist reproducible y tabla sintoma-comprobacion-solucion.
15. [Referencias](references.md): fuentes oficiales consultadas el 2026-09-17.

## Paginas puente

Estas rutas se mantienen para enlaces existentes y remiten al manual nuevo:

- [Instalacion local](installation-local.md)
- [Configuracion](configuration.md)
- [Limites de despliegue](deployment-boundaries.md)
- [Persistencia y migraciones](operations/persistence-migrations.md)
- [Seguridad](operations/security.md)
- [Monitorizacion y diagnostico](operations/monitoring-troubleshooting.md)
