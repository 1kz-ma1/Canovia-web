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

# Never log raw Laravel migration exceptions in isolated staging:
# a failed PostgreSQL connection may include private DSN or credentials.
# Retain the original production startup and transient-error logic.
isolated_staging=false
if [ "${APP_ENV:-}" = "staging" ] && [ "${CANOVIA_STAGING_ISOLATED:-}" = "true" ]; then
    isolated_staging=true
fi

while true; do
    if [ "$isolated_staging" = "true" ]; then
        if php artisan migrate --force --no-interaction --quiet >/dev/null 2>&1; then
            echo "MCP isolated staging migrations: completed (details suppressed)"
            break
        fi
        if [ "$migration_attempt" -ge "$migration_max_attempts" ]; then
            echo "MCP isolated staging migrations: failed (details suppressed)" >&2
            exit 1
        fi
        echo "MCP isolated staging migrations: retrying (details suppressed)" >&2
        migration_attempt=$((migration_attempt + 1))
        sleep "$migration_retry_delay"
        continue
    fi

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

# On-demand, staging-only synthetic account creation occurs strictly
# after migrations and before HTTP startup. Both approval switches and
# the private password are checked by the staging entrypoint and Artisan.
# Keep command output (including exceptions) out of Render logs.
if [ "$isolated_staging" = "true" ] \
    && [ "${CANOVIA_STAGING_SYNTHETIC_OWNER_BOOTSTRAP_ON_START:-false}" = "true" ]; then
    if ! php artisan canovia:mcp-staging-create-synthetic-owner --json >/dev/null 2>&1; then
        echo "MCP isolated staging synthetic owner bootstrap: blocked or failed (details suppressed)" >&2
        exit 1
    fi
    echo "MCP isolated staging synthetic owner bootstrap: completed (identity details suppressed)"
fi

# An independent, opt-in synthetic Plan fixture runs only in the same
# closed staging context as the synthetic owner. Suppress all details.
if [ "$isolated_staging" = "true" ] \
    && [ "${CANOVIA_STAGING_SYNTHETIC_PLAN_FIXTURE_ON_START:-false}" = "true" ]; then
    if ! php artisan canovia:mcp-staging-create-synthetic-plan --json >/dev/null 2>&1; then
        echo "MCP isolated staging synthetic Plan fixture: blocked or failed (details suppressed)" >&2
        exit 1
    fi
    echo "MCP isolated staging synthetic Plan fixture: completed (details suppressed)"
fi

# Closed-stage-only, read-only proof that a synthetic fixture survived
# the previous restart. No password, external IdP or user content is exposed.
if [ "$isolated_staging" = "true" ] \
    && [ "${CANOVIA_STAGING_VERIFY_SYNTHETIC_FIXTURE_ON_START:-false}" = "true" ]; then
    if ! php artisan canovia:mcp-staging-verify-synthetic-plan --json >/dev/null 2>&1; then
        echo "MCP isolated staging synthetic fixture verification: blocked (details suppressed)" >&2
        exit 1
    fi
    echo "MCP isolated staging synthetic fixture verification: ready (details suppressed)"
fi

# Operator-armed password replacement only after isolated staging migrations.
# Suppress credentials, queries, identities and exception details in logs.
if [ "$isolated_staging" = "true" ] \
    && [ "${CANOVIA_STAGING_ROTATE_SYNTHETIC_PASSWORD_ON_START:-false}" = "true" ]; then
    if ! php artisan canovia:mcp-staging-rotate-synthetic-password --json >/dev/null 2>&1; then
        echo "MCP isolated staging synthetic password rotation: blocked (details suppressed)" >&2
        exit 1
    fi
    echo "MCP isolated staging synthetic password rotation: completed (details suppressed)"
fi

echo "Building Laravel production caches..."
if [ "$isolated_staging" = "true" ]; then
    # Cache commands can invoke application boot logic. Keep their exception
    # details inside the process too; stage readiness is checked via /up.
    for cache_command in config:cache route:cache view:cache; do
        if ! php artisan "$cache_command" >/dev/null 2>&1; then
            echo "MCP isolated staging cache build failed (details suppressed)" >&2
            exit 1
        fi
    done
else
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

echo "Starting Canovia with Nginx + PHP-FPM on port 10000"
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
