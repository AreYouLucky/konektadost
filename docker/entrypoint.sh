#!/bin/sh
# KonektaDOST container start-up: prepare storage, check settings, apply pending migrations, cache config.
set -e
cd /var/www/html

log() { echo "KonektaDOST: $*" >&2; }

# A fresh storage volume starts empty: recreate Laravel's folders.
mkdir -p storage/app/diagnostics storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    log "APP_KEY is not set. Add it to .env (generate one with: php artisan key:generate --show) and restart."
    exit 1
fi

# Never reuse configuration cached for another environment.
php artisan config:clear >/dev/null

# Wait for the database. On the very first start the database container imports the dump,
# which can take several minutes; docker-compose already waits for it to report healthy.
tries=0
until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: "3306").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }' 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        log "database ${DB_HOST:-?}:${DB_PORT:-3306} is not reachable; starting anyway."
        break
    fi
    sleep 2
done

# The domain tables come from the imported dump, not from migrations. Running migrations on an
# empty database would create tables the dump also contains and block the import, so only apply
# pending migrations once the imported data is present.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    if php artisan tinker --execute='echo \Illuminate\Support\Facades\Schema::hasTable("municipalities") ? "present" : "missing";' 2>/dev/null | grep -q present; then
        if ! php artisan migrate --force --no-interaction; then
            log "migrations failed. A 'table already exists' error means the dump was made without its"
            log "migrations table; export a complete dump (see README), or set RUN_MIGRATIONS=false to start anyway."
            exit 1
        fi
    else
        log "imported KonektaDOST data not found (no municipalities table). Skipping migrations."
        log "Put the database dump in docker/mysql-init/ before the first 'docker compose up' (see README)."
    fi
fi

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

# Everything above ran as root; Apache runs as www-data and must be able to write these.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
