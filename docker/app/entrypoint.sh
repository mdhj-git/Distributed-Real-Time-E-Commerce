#!/bin/sh
set -e

cd /var/www

if [ ! -f .env ]; then
    cp .env.example .env
fi

composer install --no-interaction --no-progress

if ! grep -q "^APP_KEY=base64" .env; then
    php artisan key:generate --force
fi

php artisan migrate --force

php artisan storage:link || true

# php-fpm workers run as www-data, while the bind mount is owned by the host
# user. Grant write access to both without touching the executable bit of
# tracked files (a+rwX sets X only on directories), and without changing
# ownership so the host user can still run git.
chmod -R a+rwX storage bootstrap/cache

exec "$@"