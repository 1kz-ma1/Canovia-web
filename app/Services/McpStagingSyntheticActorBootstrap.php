<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staging-only, opt-in synthetic actor for future external IdP testing.
 *
 * Never accepts external identities or caller-supplied email/roles/Plan IDs.
 * Refuses production and non-pinned databases; password is supplied only by
 * the operator's private staging environment and never echoed or logged.
 */
final class McpStagingSyntheticActorBootstrap
{
    public const EMAIL = 'mcp-synthetic-owner@canovia.invalid';

    /** @return 'blocked'|'created'|'already_present' */
    public function provision(): string
    {
        $password = config('canovia_staging.synthetic_owner_password');

        if (config('app.env') !== 'staging'
            || config('canovia_staging.isolated') !== true
            || config('canovia_staging.web_access_enabled') !== false
            || config('canovia_staging.web_access_explicitly_approved') !== false
            || config('canovia_mcp.tools_enabled') !== false
            || config('canovia_staging.allow_synthetic_owner_bootstrap') !== true
            || ! is_string($password)
            || strlen($password) < 24 || strlen($password) > 128
            || preg_match('/[[:cntrl:]]/', $password) === 1
            || ! $this->isPinnedDatabase()) {
            return 'blocked';
        }

        $created = DB::transaction(function () use ($password): bool {
            $actor = User::query()->firstOrCreate(
                ['email' => self::EMAIL],
                [
                    'name' => 'Canovia MCP Synthetic Owner',
                    'password' => $password,
                    'first_run_completed_at' => now(),
                ],
            );

            if ($actor->wasRecentlyCreated) {
                // email_verified_at is intentionally not mass-assignable
                // on the normal User model. The synthetic CLI can set it
                // only after all environment and database gates pass.
                $actor->forceFill(['email_verified_at' => now()])->save();
            }

            return $actor->wasRecentlyCreated;
        });

        return $created ? 'created' : 'already_present';
    }

    private function isPinnedDatabase(): bool
    {
        $driver = config('database.default');

        if ($driver === 'sqlite') {
            $path = config('database.connections.sqlite.database');

            return $path === '/var/www/html/storage/app/staging/mcp.sqlite'
                || (app()->runningUnitTests() && $path === ':memory:');
        }

        if ($driver !== 'pgsql'
            || config('canovia_staging.postgres_id') !== 'dpg-db43rbbncjis73bmigi0-a'
            || config('database.connections.pgsql.database') !== 'canovia_mcp_staging_db') {
            return false;
        }

        $url = config('database.connections.pgsql.url');
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)
            && ($parts['host'] ?? null) === 'dpg-db43rbbncjis73bmigi0-a'
            && is_string(config('canovia_staging.postgres_user'))
            && preg_match('/\\A[a-z][a-z0-9_]{2,127}\\z/D',
                config('canovia_staging.postgres_user')) === 1
            && ($parts['user'] ?? null) === config('canovia_staging.postgres_user')
            && ($parts['path'] ?? null) === '/canovia_mcp_staging_db'
            && ($parts['port'] ?? 5432) === 5432
            && isset($parts['pass']) && strlen($parts['pass']) >= 8
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
