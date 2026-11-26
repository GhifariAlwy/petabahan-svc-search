#!/bin/sh
set -e

if [ ! -d "vendor" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

if [ -f ".env" ]; then
    [ -n "$DB_HOST" ] && sed -i "s/^DB_HOST=.*/DB_HOST=$DB_HOST/" .env
    [ -n "$DB_PORT" ] && sed -i "s/^DB_PORT=.*/DB_PORT=$DB_PORT/" .env
    [ -n "$REDIS_HOST" ] && sed -i "s/^REDIS_HOST=.*/REDIS_HOST=$REDIS_HOST/" .env
    [ -n "$REDIS_PORT" ] && sed -i "s/^REDIS_PORT=.*/REDIS_PORT=$REDIS_PORT/" .env
fi

php artisan package:discover --ansi 2>/dev/null || true

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

echo "Starting service on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
