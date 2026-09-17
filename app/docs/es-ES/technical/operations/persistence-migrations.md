# Persistencia y migraciones

Volver al [manual tecnico](../index.md).

Ruta puente. Ver [base de datos y persistencia](../database-persistence.md), [servicios y despliegue](../services-deployment.md) y [backup y recuperacion](../backup-recovery.md).

Regla de produccion: ejecutar `php artisan migrate --force` solo tras backup restaurable. No usar `migrate:fresh`.
