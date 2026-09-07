#!/bin/sh
set -e

echo "Waiting for database..."
until php artisan db:show > /dev/null 2>&1; do
  sleep 1
done

echo "Running migrations..."
php artisan migrate --force

echo "Caching configuration..."
php artisan config:cache
php artisan route:cache

exec "$@"
