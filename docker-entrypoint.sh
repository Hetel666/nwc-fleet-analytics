#!/bin/sh
set -e

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
rm -f bootstrap/cache/*.php

if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ "${DB_DATABASE}" != ":memory:" ]; then
    mkdir -p "$(dirname "${DB_DATABASE}")"
    touch "${DB_DATABASE}"
    chown www-data:www-data "${DB_DATABASE}"
fi

php artisan package:discover --ansi || true
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

if [ "$#" -gt 0 ]; then
    if [ "$(id -u)" = "0" ]; then
        exec gosu www-data "$@"
    fi

    exec "$@"
fi

start_managed_process() {
    name="$1"
    shift

    (
        while true; do
            echo "Starting ${name}: $*"

            if [ "$(id -u)" = "0" ]; then
                gosu www-data "$@"
            else
                "$@"
            fi

            code="$?"
            echo "${name} exited with status ${code}; restarting in 5 seconds."
            sleep 5
        done
    ) &
}

if [ "${RUN_INTERNAL_WORKERS:-true}" = "true" ]; then
    start_managed_process "scheduler" php artisan schedule:work
    start_managed_process "default-worker" php artisan queue:work database --queue=analytics,maintenance,default --sleep=3 --tries=3 --timeout=900 --max-time=3600
    start_managed_process "historical-worker" php artisan queue:work database --queue=historical-recalculations --sleep=3 --tries=6 --timeout=900 --max-time=3600
    start_managed_process "historical-monthly-worker" php artisan queue:work database --queue=historical-monthly-efficiency --sleep=3 --tries=6 --timeout=900 --max-time=3600
fi

php-fpm -D
exec nginx -g "daemon off;"
