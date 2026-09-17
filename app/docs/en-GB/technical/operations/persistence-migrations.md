# Persistence And Migrations

Back to the [technical manual](../index.md).

Bridge route. See [database and persistence](../database-persistence.md), [services and deployment](../services-deployment.md) and [backup and recovery](../backup-recovery.md).

Production rule: run `php artisan migrate --force` only after a restorable backup. Do not use `migrate:fresh`.
