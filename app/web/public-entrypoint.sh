#!/bin/sh
set -eu

mkdir -p "$(dirname "$DB_DATABASE")" storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
touch "$DB_DATABASE"

php artisan config:clear --no-ansi
php artisan route:clear --no-ansi
php artisan view:clear --no-ansi
php artisan migrate:fresh --seed --force --no-ansi

exec php artisan serve --host=0.0.0.0 --port=8000
