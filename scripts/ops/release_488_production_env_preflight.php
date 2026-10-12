<?php

/**
 * Operator-only, read-only, SECRETLESS environment preflight for Canovia Release #488.
 *
 * Run only inside the actual runtime when a trusted CLI is available. In particular,
 * a local Mac process does NOT have the production Render environment.
 * This file has NO Laravel bootstrap, DB connection, shell, writes, or network calls.
 * Never logs, prints, hashes, truncates, or reflects any environment value.
 *
 * This is a conservative policy classifier, NOT an attestation of a live connection,
 * a completed backup restore, or permission to deploy.
 */

declare(strict_types=1);

function env488(string $name): ?string
{
    $value = getenv($name);
    return is_string($value) ? $value : null;
}

function bool488(?string $raw): ?bool
{
    if ($raw === null) {
        return null;
    }
    $value = strtolower(trim($raw));
    if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['false', '0', 'no', 'off', ''], true)) {
        return false;
    }
    return null;
}

function present488(?string $value): bool
{
    return $value !== null && $value !== '';
}

/**
 * @return array{result: string, codes: list<string>, release_authorized: bool,
 *               production_database_modified: bool, production_identity_verified: bool,
 *               backup_restore_verified: bool}
 */
function classify488(): array
{
    $block = [];
    $review = [];
    $addBlock = static function (string $code) use (&$block): void { $block[$code] = true; };
    $addReview = static function (string $code) use (&$review): void { $review[$code] = true; };

    if (PHP_SAPI !== 'cli'
        || env488('CANOVIA_PRODUCTION_ENV_AUDIT_APPROVED') !== 'YES'
        || env488('GITHUB_ACTIONS') === 'true') {
        $addBlock('OPERATOR_GUARD_NOT_SATISFIED');
    } else {
        if (env488('APP_ENV') !== 'production') {
            $addBlock('APP_ENV_NOT_PRODUCTION');
        }
        if (bool488(env488('APP_DEBUG')) !== false) {
            $addBlock('APP_DEBUG_NOT_EXPLICIT_FALSE');
        }

        $key = env488('APP_KEY');
        $decoded = is_string($key) && str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true) : false;
        if (! is_string($key) || ! (
            (str_starts_with($key, 'base64:') && is_string($decoded) && strlen($decoded) === 32)
            || (! str_starts_with($key, 'base64:') && strlen($key) === 32)
        )) {
            $addBlock('APP_ENCRYPTION_KEY_INVALID');
        }

        if (env488('DB_CONNECTION') !== 'mysql') {
            $addBlock('PRODUCTION_DATABASE_DRIVER_UNEXPECTED');
        }
        if (present488(env488('DB_SOCKET'))) {
            $addBlock('DATABASE_SOCKET_OVERRIDE');
        }
        $dbUrl = env488('DB_URL');
        $urlActive = present488($dbUrl);
        if ($urlActive) {
            // Laravel's ConnectionUrlParser overrides the individual DB_* keys.
            // parse_url is used only in memory, with no output of host, user or URL.
            try {
                $parts = @parse_url((string) $dbUrl);
            } catch (Throwable) {
                // Invalid URL contents must never enter a warning or traceback.
                $parts = false;
            }
            if (! is_array($parts)
                || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['mysql', 'mariadb'], true)
                || ! str_ends_with(strtolower((string) ($parts['host'] ?? '')), '.aivencloud.com')
                || rawurldecode((string) ($parts['path'] ?? '')) !== '/pacekeeper'
                || ! isset($parts['user'], $parts['pass'])
                || (string) $parts['pass'] === ''
                // Laravel URL query parameters can override the path/database.
                // For this release refuse URL options until separately reviewed.
                || isset($parts['query']) || isset($parts['fragment'])) {
                $addBlock('DATABASE_URL_TARGET_UNVERIFIED');
            }
            $addReview('DATABASE_URL_OVERRIDES_INDIVIDUAL_SETTINGS');
        } else {
            $host = strtolower((string) env488('DB_HOST'));
            $port = env488('DB_PORT');
            if (! str_ends_with($host, '.aivencloud.com')
                || strlen($host) <= strlen('.aivencloud.com')
                || env488('DB_DATABASE') !== 'pacekeeper'
                || ! is_string($port) || ! ctype_digit($port)
                || (int) $port < 1 || (int) $port > 65535
                || ! present488(env488('DB_USERNAME'))
                || ! present488(env488('DB_PASSWORD'))) {
                $addBlock('DATABASE_INDIVIDUAL_TARGET_UNVERIFIED');
            }
        }
        if (present488(env488('DATABASE_URL'))) {
            $addReview('ALTERNATE_DATABASE_URL_PRESENT');
        }
        if (! present488(env488('APP_URL'))) {
            $addReview('PUBLIC_APP_URL_NOT_EXPLICIT');
        } else {
            $appUrl = (string) env488('APP_URL');
            try {
                $origin = @parse_url($appUrl);
            } catch (Throwable) {
                $origin = false;
            }
            if (! is_array($origin)
                || ($origin['scheme'] ?? null) !== 'https'
                || ! isset($origin['host'])
                || isset($origin['user']) || isset($origin['pass'])
                || isset($origin['query']) || isset($origin['fragment'])
                || isset($origin['port'])
                || ! in_array((string) ($origin['path'] ?? ''), ['', '/'], true)
                || str_contains(strtolower((string) $origin['host']), 'staging')
                || str_contains((string) $origin['host'], '_')) {
                $addBlock('PUBLIC_APP_URL_UNEXPECTED');
            }
        }

        $sessionDriver = env488('SESSION_DRIVER') ?? 'database';
        if (! in_array($sessionDriver, ['database', 'redis'], true)) {
            $addBlock('SESSION_BACKEND_UNEXPECTED');
        } elseif ($sessionDriver === 'database'
            && ! in_array((string) env488('SESSION_CONNECTION'), ['', 'mysql'], true)) {
            $addBlock('SESSION_DATABASE_CONNECTION_UNEXPECTED');
        } elseif ($sessionDriver === 'redis') {
            if (! in_array((string) env488('SESSION_CONNECTION'), ['', 'default'], true)) {
                $addBlock('SESSION_REDIS_CONNECTION_UNEXPECTED');
            }
            if (! present488(env488('REDIS_URL'))) {
                $addReview('REDIS_SESSION_TARGET_NEEDS_OPERATOR_REVIEW');
            }
        }

        if (bool488(env488('SESSION_SECURE_COOKIE')) === false
            || (env488('SESSION_SECURE_COOKIE') !== null
                && bool488(env488('SESSION_SECURE_COOKIE')) === null)) {
            $addBlock('SESSION_SECURE_COOKIE_NOT_ENFORCED');
        }
        if (bool488(env488('SESSION_HTTP_ONLY')) === false
            || (env488('SESSION_HTTP_ONLY') !== null
                && bool488(env488('SESSION_HTTP_ONLY')) === null)) {
            $addBlock('SESSION_HTTP_ONLY_NOT_ENFORCED');
        }
        $sameSite = strtolower((string) (env488('SESSION_SAME_SITE') ?? 'lax'));
        if (! in_array($sameSite, ['lax', 'strict'], true)) {
            $addReview('SESSION_SAME_SITE_REQUIRES_REVIEW');
        }
        if (str_contains(strtolower((string) env488('SESSION_COOKIE')), 'staging')
            || str_contains(strtolower((string) env488('SESSION_DOMAIN')), 'staging')) {
            $addBlock('SESSION_STAGING_COOKIE_COLLISION');
        }

        $mcpFlags = [
            'CANOVIA_MCP_DISCOVERY_ENABLED',
            'CANOVIA_MCP_TOOLS_ENABLED',
            'CANOVIA_MCP_PLAN_CONSENT_ENABLED',
            'CANOVIA_MCP_ACCOUNT_LINK_ENABLED',
            'CANOVIA_MCP_DELEGATED_POLICY_ENABLED',
            'CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED',
            'CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED',
        ];
        foreach ($mcpFlags as $flag) {
            $value = env488($flag);
            if ($value !== null && bool488($value) !== false) {
                $addBlock('UNAPPROVED_MCP_FLAG_OR_INVALID_BOOLEAN');
            }
        }
        if (bool488(env488('CANOVIA_STAGING_ISOLATED')) === true) {
            $addBlock('STAGING_ISOLATION_ENABLED_IN_PRODUCTION');
        }
        if (present488(env488('CANOVIA_STAGING_DB_MODE'))
            || present488(env488('CANOVIA_STAGING_POSTGRES_ID'))
            || present488(env488('CANOVIA_STAGING_POSTGRES_USER'))) {
            $addReview('STAGING_ONLY_KEYS_PRESENT');
        }

        $aiDriver = env488('CANOVIA_NATIVE_AI_DRIVER') ?? 'disabled';
        if ($aiDriver !== 'disabled') {
            $addReview('NATIVE_AI_ENABLED_COST_AND_AUTH_REVIEW');
            if (! present488(env488('OPENAI_API_KEY'))) {
                $addBlock('NATIVE_AI_KEY_NOT_AVAILABLE');
            }
        }
        if (bool488(env488('DB_PERSISTENT')) === true) {
            $addReview('DB_PERSISTENT_CONNECTION_REVIEW');
        }
    }

    $codes = array_keys($block ?: $review);
    sort($codes, SORT_STRING);
    return [
        'result' => $block ? 'BLOCK' : 'REVIEW_REQUIRED',
        'codes' => $codes ?: ['CONFIGURATION_SHAPE_MATCH_NOT_RELEASE'],
        'release_authorized' => false,
        'production_database_modified' => false,
        'production_identity_verified' => false,
        'backup_restore_verified' => false,
    ];
}

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    try {
        $result = classify488();
    } catch (Throwable) {
        // Fail closed without printing a Throwable message or stack trace.
        $result = [
            'result' => 'BLOCK',
            'codes' => ['INTERNAL_AUDIT_ERROR'],
            'release_authorized' => false,
            'production_database_modified' => false,
            'production_identity_verified' => false,
            'backup_restore_verified' => false,
        ];
    }
    // Only fixed, code-owned strings can be emitted, NEVER environment contents.
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit($result['result'] === 'BLOCK' ? 2 : 0);
}
