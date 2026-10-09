#!/usr/bin/env sh
set -eu

# Staging-only entrypoint. Fail BEFORE migrations and HTTP startup.
deny() {
    printf '%s\n' "MCP isolated staging guard: BLOCKED ($1)" >&2
    exit 42
}

[ "${CANOVIA_STAGING_ISOLATED:-}" = "true" ] || deny "staging marker missing"
[ "${APP_ENV:-}" = "staging" ] || deny "APP_ENV must equal staging"
[ "${APP_DEBUG:-}" = "false" ] || deny "debug must be disabled"

# Database mode is explicit: retain disposable SQLite by default, or use
# ONLY the pre-created, independent Free Render staging PostgreSQL instance.
# Reject all unapproved Aiven/live database hosts before ANY migration.
[ -z "${DATABASE_URL:-}" ] || deny "DATABASE_URL unsupported"
[ -z "${PGPASSWORD:-}" ] || deny "PGPASSWORD unsupported"
[ -z "${PGHOST:-}" ] || deny "PGHOST unsupported"
[ -z "${PGDATABASE:-}" ] || deny "PGDATABASE unsupported"
[ -z "${MYSQL_ATTR_SSL_CA:-}" ] || deny "MySQL CA unsupported"
[ -z "${DB_HOST:-}" ] || deny "DB_HOST override forbidden"
[ -z "${DB_USERNAME:-}" ] || deny "DB_USERNAME override forbidden"
[ -z "${DB_PASSWORD:-}" ] || deny "DB_PASSWORD override forbidden"
[ -z "${DB_SOCKET:-}" ] || deny "DB_SOCKET override forbidden"

case "${CANOVIA_STAGING_DB_MODE:-sqlite}" in
    sqlite)
        [ "${DB_CONNECTION:-}" = "sqlite" ] || deny "SQLite driver required"
        [ "${DB_DATABASE:-}" = "/var/www/html/storage/app/staging/mcp.sqlite" ] \
            || deny "SQLite database path mismatch"
        [ -z "${DB_URL:-}" ] || deny "external URL forbidden for SQLite"
        ;;
    render_postgres)
        [ "${DB_CONNECTION:-}" = "pgsql" ] || deny "PostgreSQL driver required"
        [ "${DB_DATABASE:-}" = "canovia_mcp_staging_db" ] \
            || deny "Postgres database name mismatch"
        [ "${CANOVIA_STAGING_POSTGRES_ID:-}" = "dpg-db43rbbncjis73bmigi0-a" ] \
            || deny "Postgres resource ID mismatch"

        # Database URL is read as a process environment variable, never
        # logged or accepted from user input; compare immutable host and DB
        # name to the exact Render staging instance. Internal Render only.
        [ -n "${DB_URL:-}" ] || deny "staging PostgreSQL URL missing"
        php -r '
            $raw = getenv("DB_URL");
            $p = is_string($raw) ? parse_url($raw) : false;
            if (!is_array($p)
                || !in_array($p["scheme"] ?? "", ["postgres", "postgresql"], true)
                || ($p["host"] ?? "") !== "dpg-db43rbbncjis73bmigi0-a"
                || ($p["user"] ?? "") !== "canovia_mcp_staging_db_user"
                || ($p["path"] ?? "") !== "/canovia_mcp_staging_db"
                || ($p["port"] ?? 5432) !== 5432
                || !isset($p["pass"]) || strlen($p["pass"]) < 8
                || isset($p["query"]) || isset($p["fragment"])) {
                exit(42);
            }
        ' || deny "Postgres URL fails pinned staging resource validation"
        ;;
    *) deny "unsupported staging database mode" ;;
esac

[ "${SESSION_DRIVER:-}" = "file" ] || deny "local sessions required"
[ "${CACHE_STORE:-}" = "file" ] || deny "local cache required"
[ "${QUEUE_CONNECTION:-}" = "sync" ] || deny "background workers forbidden"
[ "${MAIL_MAILER:-}" = "log" ] || deny "outbound email forbidden"
[ -z "${SESSION_DOMAIN:-}" ] || deny "cookie domain must be unset"
[ "${SESSION_COOKIE:-}" = "canovia_mcp_staging_session" ] \
    || deny "isolated session cookie required"

# Exact HTTPS onrender staging hostname, no path/query/fragment/credentials.
case "${APP_URL:-}" in
    https://*staging*.onrender.com) ;;
    *) deny "APP_URL must be an isolated staging onrender.com host" ;;
esac
host="${APP_URL#https://}"
case "$host" in
    *"/"*|*"?"*|*"#"*|*"@"*|*":"*|*" "*|*".."*|*"_"*)
        deny "unsafe staging hostname" ;;
    pacekeeper-d3mm.onrender.com|app.canovia.com|canovia.com)
        deny "production hostname" ;;
esac
# Avoid accepting uppercase, shell special characters or arbitrary host text.
if ! printf '%s\n' "$host" | grep -Eq '^[a-z0-9-]*staging[a-z0-9-]*\.onrender\.com$'; then
    deny "unexpected staging hostname"
fi

# Render generateValue is not a valid Laravel APP_KEY on its own.
# Supply an independently generated base64:<44 chars> key.
case "${APP_KEY:-}" in
    base64:????????????????????????????????????????????) ;;
    *) deny "separate base64 Laravel APP_KEY required" ;;
esac

# Never inherit production integrations, mail, webhook or payment secrets.
for name in GITHUB_READ_TOKEN GITHUB_APP_ID GITHUB_APP_PRIVATE_KEY \
    GITHUB_APP_PRIVATE_KEY_BASE64 GITHUB_APP_WEBHOOK_SECRET \
    GITHUB_TOKEN OPENAI_API_KEY ANTHROPIC_API_KEY \
    STRIPE_SECRET STRIPE_WEBHOOK_SECRET AWS_ACCESS_KEY_ID \
    AWS_SECRET_ACCESS_KEY RESEND_API_KEY; do
    if [ -n "$(printenv "$name" 2>/dev/null || true)" ]; then
        deny "production integration secrets forbidden"
    fi
done

# Real OAuth and MCP capabilities are separately gated in Canovia code.
# Before explicit staging testing approval, even synthetic MCP is OFF.
if [ "${CANOVIA_MCP_TOOLS_ENABLED:-false}" != "false" ]; then
    [ "${CANOVIA_STAGING_MCP_EXPLICITLY_APPROVED:-false}" = "true" ] \
        || deny "MCP tools require separate staging approval"
fi

# Initially only /up is accessible via the Laravel staging middleware.
case "${CANOVIA_STAGING_WEB_ACCESS_ENABLED:-false}" in
    false|true) ;;
    *) deny "invalid staging public-access switch" ;;
esac

if [ "${1:-}" = "--check-only" ]; then
    printf '%s\n' "MCP isolated staging guard: PASS (config only; no DB/network changes)"
    exit 0
fi
[ "$#" -eq 0 ] || deny "unsupported arguments"

cd /var/www/html
if [ "${CANOVIA_STAGING_DB_MODE:-sqlite}" = "sqlite" ]; then
    mkdir -p storage/app/staging
    touch storage/app/staging/mcp.sqlite
    chown -R www-data:www-data storage/app/staging
fi

# Production boot script runs migrations only AFTER staging DB guard.
exec ./docker/render-start.sh
