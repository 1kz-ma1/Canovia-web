#!/usr/bin/env sh
set -eu

cd /var/www/html

mkdir -p \
storage/framework/cache/data \
storage/framework/sessions \
storage/framework/views \
storage/logs \
bootstrap/cache \
/run/nginx \
/var/log/supervisor

echo "Running Laravel migrations..."

migration_attempt=1
migration_max_attempts="${CANOVIA_MIGRATION_MAX_ATTEMPTS:-5}"
migration_retry_delay="${CANOVIA_MIGRATION_RETRY_DELAY_SECONDS:-8}"

while true; do
    migration_output=""

    if migration_output="$(php artisan migrate --force 2>&1)"; then
        printf '%s\n' "$migration_output"
        break
    else
        migration_status=$?
    fi

    printf '%s\n' "$migration_output"

    case "$migration_output" in
        *"SQLSTATE[08S01]"*|*"SQLSTATE[HY000] [2002]"*|*"SQLSTATE[HY000] [2006]"*|*"SQLSTATE[HY000] [2013]"*|*"Got timeout reading communication packets"*)
            ;;
        *)
            exit "$migration_status"
            ;;
    esac

    if [ "$migration_attempt" -ge "$migration_max_attempts" ]; then
        echo "Migration failed after ${migration_attempt} transient database attempts."
        exit "$migration_status"
    fi

    echo "Transient database error during migration attempt ${migration_attempt}; retrying in ${migration_retry_delay}s..."
    migration_attempt=$((migration_attempt + 1))
    sleep "$migration_retry_delay"
done

echo "Building Laravel production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Starting Canovia with Nginx + PHP-FPM on port 10000"
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
