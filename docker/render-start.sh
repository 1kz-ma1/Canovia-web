#!/usr/bin/env sh
set -eu

startup_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
cd /var/www/html

mkdir -p \
storage/framework/cache/data \
storage/framework/sessions \
storage/framework/views \
storage/logs \
bootstrap/cache \
/run/nginx \
/var/log/supervisor

# Decide before any migrations, cache generation or HTTP startup.
. "$startup_dir/startup-migration-policy.sh"
isolated_staging=false
if [ "${APP_ENV:-}" = "staging" ] && [ "${CANOVIA_STAGING_ISOLATED:-}" = "true" ]; then
    isolated_staging=true
fi
canovia_prepare_startup_schema

# Opt-in offline, secretless Auth0 wiring report. Never opens any route,
# contacts Auth0 or returns configured client IDs, secrets, URLs or keys.
if [ "$isolated_staging" = "true" ] \
    && [ "${CANOVIA_STAGING_VERIFY_AUTH0_WIRING_ON_START:-false}" = "true" ]; then
    # Do not echo provider credentials, errors, or raw Laravel output.
    # The CLI prints only a static allowlist of check identifiers.
    if failed_codes="$(php artisan canovia:mcp-auth0-sealed-wiring-check --blocked-codes 2>/dev/null)"; then
        echo "MCP isolated staging Auth0 wiring: configuration ready (not OAuth proof)"
    else
        case "$failed_codes" in
            ""|*[!a-z_,]*)
                echo "MCP isolated staging Auth0 wiring: blocked (details suppressed)" >&2
                ;;
            *)
                # Bounded, characters-only codes from the fixed PHP allowlist.
                if [ "${#failed_codes}" -le 512 ]; then
                    echo "MCP isolated staging Auth0 wiring: blocked checks=$failed_codes" >&2
                else
                    echo "MCP isolated staging Auth0 wiring: blocked (details suppressed)" >&2
                fi
                ;;
        esac
        exit 1
    fi
fi

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
