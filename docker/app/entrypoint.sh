#!/bin/sh
set -e

cd /var/www

if [ "${RUN_SETUP:-1}" = "1" ]; then
    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    composer install --no-interaction --no-progress

    if ! grep -q "^APP_KEY=base64" .env; then
        php artisan key:generate --force
    fi

    php artisan migrate --force

    php artisan storage:link || true
fi

exec "$@"
