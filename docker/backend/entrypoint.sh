#!/bin/sh
set -e

# Every PHP process must run as the owner of storage/ (see docker/backend/Dockerfile).
APP_USER=www-data
WRITABLE_DIRS="storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"

if [ "$(id -u)" = "0" ]; then
  # Started as root (`--user root`, or an older deployment): create the writable tree, hand back
  # anything not owned by the app user (only those entries are touched), then continue as it.
  mkdir -p $WRITABLE_DIRS
  find storage bootstrap/cache ! -user "$APP_USER" -exec chown "$APP_USER:$APP_USER" {} +
  exec su-exec "$APP_USER" "$0" "$@"
fi

mkdir -p $WRITABLE_DIRS
# Fail fast with the fix instead of failing later on the first upload. The private disk creates
# 0700 directories, so one foreign-owned folder blocks every upload beneath it.
foreign="$(find storage bootstrap/cache ! -user "$(id -un)" -print 2>/dev/null | head -n 1)"
if [ -n "$foreign" ]; then
  echo "ERROR: $foreign is not owned by $(id -un); uploads would fail." >&2
  echo "Repair once with:  docker compose run --rm --user root backend true" >&2
  exit 1
fi

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
