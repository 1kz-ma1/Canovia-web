<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * One-shot password replacement for the fixed synthetic MCP staging actor.
 *
 * This is not a public password reset, IdP registration, or production
 * recovery route. Only a closed, pinned staging deployment may invoke it,
 * and no credential is ever printed, returned or logged.
 */
final class McpStagingSyntheticCredentialRotator
{
    /** @return 'blocked'|'rotated'|'already_current' */
    public function rotate(
        McpStagingSyntheticActorBootstrap $actorBootstrap,
        McpStagingSyntheticPlanFixtureBootstrap $fixture,
    ): string {
        $password = config('canovia_staging.synthetic_owner_rotated_password');

        // Reject without touching the database if any stage boundary fails.
        // Separate human approval, boot arming and secret are all necessary.
        if (config('app.env') !== 'staging'
            || config('canovia_staging.isolated') !== true
            || (config('canovia_staging.database_mode') !== 'render_postgres'
                && ! app()->runningUnitTests())
            || config('canovia_staging.web_access_enabled') !== false
            || config('canovia_staging.web_access_explicitly_approved') !== false
            || config('canovia_staging.allow_synthetic_password_rotation') !== true
            || config('canovia_staging.rotate_synthetic_password_on_start') !== true
            || config('canovia_mcp.discovery_enabled') !== false
            || config('canovia_mcp.token_introspection_enabled') !== false
            || config('canovia_mcp.account_link_enabled') !== false
            || config('canovia_mcp.plan_consent_enabled') !== false
            || config('canovia_mcp.delegated_policy_enabled') !== false
            || config('canovia_mcp.tools_enabled') !== false
            || ! is_string($password)
            || strlen($password) < 32
            || strlen($password) > 128
            || preg_match('/[[:cntrl:]]/', $password) === 1
            || ! $actorBootstrap->isPinnedDatabase()) {
            return 'blocked';
        }

        try {
            return DB::transaction(function () use ($actorBootstrap, $fixture, $password): string {
                // Exactly one fixed synthetic user, one private Plan, two
                // Tasks and no external grants/links must remain intact.
                if ($fixture->verify($actorBootstrap) !== 'ready') {
                    return 'blocked';
                }

                $actor = User::query()
                    ->where('email', McpStagingSyntheticActorBootstrap::EMAIL)
                    ->lockForUpdate()
                    ->sole();

                if (Hash::check($password, $actor->password)) {
                    return 'already_current';
                }

                // User's hashed password cast hashes the new value before save.
                $actor->forceFill(['password' => $password])->save();

                return 'rotated';
            });
        } catch (Throwable) {
            // Exceptions may contain connection details; intentionally silent.
            return 'blocked';
        }
    }
}
