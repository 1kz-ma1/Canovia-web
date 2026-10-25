#!/usr/bin/env sh
# Source this only after entering the application root. Never print DB errors.
canovia_prepare_startup_schema() {
    # A generated cache may contain an old DB target / APP_KEY / feature profile.
    # Clear only the fixed, owned cache before booting ANY PHP application code.
    startup_config='bootstrap/cache/config.php'
    case "${APP_CONFIG_CACHE:-}" in
        ''|"$startup_config"|"$(pwd -P)/$startup_config") ;;
        *) echo 'Startup blocked: unsupported config cache location' >&2; return 1 ;;
    esac
    if [ -L bootstrap ] || [ -L bootstrap/cache ] || [ -L "$startup_config" ] \
        || { [ -e "$startup_config" ] && [ ! -f "$startup_config" ]; }; then
        echo 'Startup blocked: unsafe generated config cache' >&2
        return 1
    fi
    if ! rm -f -- "$startup_config" >/dev/null 2>&1; then
        echo 'Startup blocked: generated config cache cleanup failed' >&2
        return 1
    fi

    # Preserve the existing closed, synthetic staging workflow. It is not a
    # production migration policy or permission to enable that environment.
    if [ "${APP_ENV:-}" = staging ] \
        && [ "${CANOVIA_STAGING_ISOLATED:-}" = true ] \
        && [ -z "${CANOVIA_STARTUP_MIGRATIONS:-}" ]; then
        migration_attempt=1
        migration_max_attempts="${CANOVIA_MIGRATION_MAX_ATTEMPTS:-5}"
        migration_retry_delay="${CANOVIA_MIGRATION_RETRY_DELAY_SECONDS:-8}"
        case "$migration_max_attempts" in
            [1-9]|10) ;;
            *) echo 'Invalid isolated migration retry bounds' >&2; return 1 ;;
        esac
        case "$migration_retry_delay" in
            [0-9]|[1-5][0-9]|60) ;;
            *) echo 'Invalid isolated migration retry bounds' >&2; return 1 ;;
        esac
        while true; do
            if php artisan migrate --force --no-interaction --quiet >/dev/null 2>&1; then
                echo 'MCP isolated staging migrations: completed (details suppressed)'
                return 0
            fi
            if [ "$migration_attempt" -ge "$migration_max_attempts" ]; then
                echo 'MCP isolated staging migrations: failed (details suppressed)' >&2
                return 1
            fi
            migration_attempt=$((migration_attempt + 1))
            sleep "$migration_retry_delay"
        done
    fi

    case "${CANOVIA_STARTUP_MIGRATIONS:-check}" in
        check) ;;
        apply)
            approved_sha="${CANOVIA_APPROVED_MIGRATION_SHA:-}"
            case "$approved_sha" in
                ''|*[!0-9a-f]*) echo 'Migration apply blocked: exact approval SHA required' >&2; return 1 ;;
            esac
            if [ "${#approved_sha}" -ne 40 ] || [ "$approved_sha" != "${RENDER_GIT_COMMIT:-}" ]; then
                echo 'Migration apply blocked: runtime/approval SHA mismatch' >&2; return 1
            fi
            # A lost connection may follow partially committed MySQL DDL.
            # Run ONCE. Failure requires operator review, never automatic down.
            if php artisan migrate --force --no-interaction --quiet >/dev/null 2>&1; then
                echo 'Approved migration execution completed; checking ledger'
            else
                migration_status=$?
                echo 'Approved migration execution failed; operator review required (details suppressed)' >&2
                return "$migration_status"
            fi
            ;;
        *) echo 'Startup blocked: invalid migration mode' >&2; return 1 ;;
    esac

    if php artisan canovia:release-schema-ready --no-interaction --quiet >/dev/null 2>&1; then
        echo 'Migration ledger matches source; no startup DDL in check mode'
    else
        migration_status=$?
        echo 'Startup blocked: schema ledger unavailable, pending or unknown (details suppressed)' >&2
        return "$migration_status"
    fi
}
