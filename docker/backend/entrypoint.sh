#!/bin/sh
# OptiFleet container entrypoint.
#   entrypoint.sh bootstrap   one-shot job: configuration check, wait for PostgreSQL, Redis and MongoDB, migrations, production-safe seed
#   entrypoint.sh <anything>  runtime role (php-fpm, queue:work, schedule:work): waits (bounded) for the bootstrap to have finished, then runs
# Only the bootstrap job changes the database schema. Waits are bounded and end with a clear message; an invalid configuration exits instead of looping.
set -eu

# Every PHP process must run as the owner of storage/ (see docker/backend/Dockerfile).
APP_USER=www-data
WRITABLE_DIRS="storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"

fail() { echo "ERROR: $*" >&2; exit 1; }

if [ "$(id -u)" = "0" ]; then
  # Started as root: create the writable tree, hand back anything not owned by the app user (only those entries are touched), then continue as it.
  mkdir -p $WRITABLE_DIRS
  find storage bootstrap/cache ! -user "$APP_USER" -exec chown "$APP_USER:$APP_USER" {} + 2>/dev/null || true
  exec su-exec "$APP_USER" "$0" "$@"
fi

mkdir -p $WRITABLE_DIRS

DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-90}"
BOOTSTRAP_WAIT_SECONDS="${BOOTSTRAP_WAIT_SECONDS:-300}"

# wait_until <seconds> <what> <command...>: run the command every 2 s until it succeeds or the time is up.
wait_until() {
  limit="$1"; what="$2"; shift 2
  waited=0
  until "$@" >/dev/null 2>&1; do
    waited=$((waited + 2))
    [ "$waited" -lt "$limit" ] || return 1
    sleep 2
  done
}

tcp_up() { php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $s, 2) ? 0 : 1);' "$1" "$2"; }

if [ "${1:-}" = "bootstrap" ]; then
  echo "[bootstrap] checking configuration"
  [ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty (see .env.example)"
  case "${FLEET_SEED:-catalog}" in catalog|demo|none) ;; *) fail "FLEET_SEED must be catalog, demo or none" ;; esac
  if [ "${OPTINEXUS_ENABLED:-false}" = "true" ] || [ "${OPTINEXUS_ENABLED:-false}" = "1" ]; then
    [ -n "${OPTINEXUS_BASE_URL:-}" ] || fail "OPTINEXUS_ENABLED=true needs OPTINEXUS_BASE_URL"
  fi

  echo "[bootstrap] waiting for PostgreSQL, Redis and MongoDB (at most ${DB_WAIT_SECONDS}s each)"
  wait_until "$DB_WAIT_SECONDS" database php artisan db:show --no-interaction \
    || fail "the database did not become reachable within ${DB_WAIT_SECONDS}s (check DB_HOST/DB_PASSWORD)"
  wait_until "$DB_WAIT_SECONDS" redis tcp_up "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" \
    || fail "Redis did not become reachable within ${DB_WAIT_SECONDS}s (check REDIS_HOST)"
  wait_until "$DB_WAIT_SECONDS" mongodb tcp_up "${MONGO_HOST:-mongo}" "${MONGO_PORT:-27017}" \
    || fail "MongoDB did not become reachable within ${DB_WAIT_SECONDS}s (check MONGO_HOST)"

  echo "[bootstrap] migrating"
  php artisan migrate --force --no-interaction

  case "${FLEET_SEED:-catalog}" in
    catalog) php artisan db:seed --class=BootstrapSeeder --force --no-interaction ;;
    demo) php artisan db:seed --class=DevDemoSeeder --force --no-interaction ;;
    none) echo "[bootstrap] FLEET_SEED=none: no seeding" ;;
  esac
  echo "[bootstrap] complete"
  exit 0
fi

# Runtime roles never migrate. They start when the schema is current, or stop with a message instead of looping forever.
pending() { php artisan migrate:status --pending --no-interaction 2>&1 | grep -qi "no pending migrations"; }
wait_until "$BOOTSTRAP_WAIT_SECONDS" bootstrap pending \
  || fail "the database schema is not current after ${BOOTSTRAP_WAIT_SECONDS}s: run the bootstrap job (docker compose run --rm bootstrap) and start again"

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
