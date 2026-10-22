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

# Emergency runtime hotfix: production only, with an immutable no-DDL startup mode.
# Fail before running Laravel/Artisan if a stale or unsafe mode is configured.
# Release acceptance must separately prove the actual schema and migration ledger
# are compatible with the pinned legacy Live code. This guard is not that proof.
if [ "${APP_ENV:-}" != "production" ]; then
    echo "Emergency PHP hotfix refuses non-production APP_ENV" >&2
    exit 42
fi
if [ "${CANOVIA_STARTUP_MIGRATIONS:-check}" != "check" ] \
    || [ -n "${CANOVIA_APPROVED_MIGRATION_SHA:-}" ]; then
    echo "Emergency PHP hotfix refuses migration execution or approval settings" >&2
    exit 42
fi
echo "Emergency PHP hotfix: automatic migrations disabled; separate schema acceptance required"
isolated_staging=false

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
