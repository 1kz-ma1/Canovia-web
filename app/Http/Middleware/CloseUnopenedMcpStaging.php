<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Newly provisioned MCP staging instances expose only Render's /up probe
 * until a separate operator-approved staging access configuration is ready.
 * Production routing is unaffected and no sensitive diagnostic is exposed.
 */
final class CloseUnopenedMcpStaging
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'staging') {
            // Never permit an unmarked instance to become an open app, even
            // if someone accidentally switches the web-access flag.
            if (config('canovia_staging.isolated') !== true) {
                return $this->unavailable();
            }

            if ($request->is('up')) {
                // The old SQLite-only stage remains unchanged. In the
                // separately approved PostgreSQL mode, a healthy PHP
                // process is NOT sufficient: migration tables, exact
                // database and private DB identity must also be ready.
                if (! $this->databaseReady()) {
                    return $this->unavailable();
                }
                return $next($request);
            }

            // A single mistaken environment switch is not sufficient to
            // expose staging. Require independently reviewed authorization,
            // a durable isolated database and its live schema readiness.
            if (config('canovia_staging.web_access_enabled') !== true
                || config('canovia_staging.web_access_explicitly_approved') !== true
                || config('canovia_staging.database_mode') !== 'render_postgres'
                || ! $this->databaseReady()) {
                return $this->unavailable();
            }
        }

        return $next($request);
    }

    private function databaseReady(): bool
    {
        $mode = config('canovia_staging.database_mode');
        if ($mode === 'sqlite') {
            return true;
        }
        if ($mode !== 'render_postgres'
            || config('canovia_staging.postgres_id') !== 'dpg-db43rbbncjis73bmigi0-a'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'canovia_mcp_staging_db') {
            return false;
        }

        // Defense-in-depth if a custom boot accidentally bypasses the
        // staging shell's stricter pinned host/user/resource checks.
        $url = config('database.connections.pgsql.url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)
            || ($parts['host'] ?? null) !== 'dpg-db43rbbncjis73bmigi0-a'
            || ! is_string(config('canovia_staging.postgres_user'))
            || preg_match('/\\A[a-z][a-z0-9_]{2,127}\\z/D',
                config('canovia_staging.postgres_user')) !== 1
            || ($parts['user'] ?? null) !== config('canovia_staging.postgres_user')
            || ($parts['path'] ?? null) !== '/canovia_mcp_staging_db'
            || ($parts['port'] ?? 5432) !== 5432
            || ! isset($parts['pass']) || strlen($parts['pass']) < 8
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        try {
            $connection = DB::connection('pgsql');
            $database = $connection->selectOne('SELECT current_database() AS db_name');
            if (($database->db_name ?? null) !== 'canovia_mcp_staging_db') {
                return false;
            }

            $tables = $connection->selectOne(
                "SELECT COUNT(*) AS present_count
                 FROM information_schema.tables
                 WHERE table_schema = 'public'
                   AND table_name IN (
                     'migrations', 'users', 'plans',
                     'mcp_linked_subjects', 'mcp_delegated_grants',
                     'mcp_delegated_access_events'
                   )"
            );

            return (int) ($tables->present_count ?? 0) === 6;
        } catch (Throwable) {
            // No DB exception or credential may escape to the response.
            return false;
        }
    }

    private function unavailable(): Response
    {
        return response('Staging service unavailable.', 503)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
