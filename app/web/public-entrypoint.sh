#!/bin/sh
set -eu

# Jenkins injects runtime configuration; never print its values.
php -r '
if (trim((string) getenv("APP_KEY")) === "") {
    fwrite(STDERR, "ERROR APP_KEY: missing / falta configurar.\n");
}
$host = strtolower((string) (parse_url((string) getenv("APP_URL"), PHP_URL_HOST) ?: ""));
if ((getenv("APP_ENV") ?: "production") === "production"
    && in_array($host, ["", "localhost", "example.com", "app.example.org"], true)) {
    fwrite(STDERR, "ERROR APP_URL: host empty, localhost or placeholder / host vacío, localhost o de ejemplo.\n");
}
'

DB_DATABASE="${DB_DATABASE:-/var/www/html/storage/database/database.sqlite}"
export DB_DATABASE
mkdir -p "$(dirname "$DB_DATABASE")" storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
touch "$DB_DATABASE"

php artisan config:clear --no-ansi
php artisan route:clear --no-ansi
php artisan view:clear --no-ansi
if [ "${I4S_RESET_DATABASE_ON_START:-false}" = "true" ]; then
    printf '%s\n' 'WARNING I4S_RESET_DATABASE_ON_START=true: DELETING ALL DATABASE DATA / BORRANDO TODOS LOS DATOS.' >&2
    php artisan migrate:fresh --force --no-ansi
else
    php artisan migrate --force --no-ansi
fi

php artisan db:seed --class=NaceCodeSeeder --force --no-ansi
php artisan db:seed --class=EsrsTopicSeeder --force --no-ansi

if php artisan i4s:deploy:check --no-ansi; then
    printf '%s\n' 'PASS i4s:deploy:check (exit=0)'
else
    result=$?
    printf 'FAIL i4s:deploy:check (exit=%s); continuing startup / continúa el arranque.\n' "$result" >&2
fi

exec php artisan serve --host=0.0.0.0 --port=8000
