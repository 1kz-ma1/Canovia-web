<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One fixed, private Plan and two synthetic Tasks for staging MCP read tests.
 *
 * Operator-only and opt-in. Never provisions an external subject, an OAuth
 * token, a delegated grant, or any data derived from production.
 */
final class McpStagingSyntheticPlanFixtureBootstrap
{
    public const REQUEST_ID = 'decca1f0-ea51-4e80-9c01-882acf6d0001';
    public const TITLE = 'MCP staging synthetic read test';
    public const FIRST_TASK = 'Review synthetic roadmap context';
    public const SECOND_TASK = 'Verify scoped task retrieval';

    /**
     * Read-only persisted-fixture verification after a sealed stage reboot.
     * Neither the secret bootstrap password nor the creation flags are needed.
     *
     * @return 'ready'|'blocked'
     */
    public function verify(McpStagingSyntheticActorBootstrap $actorBootstrap): string
    {
        if (config('app.env') !== 'staging'
            || config('canovia_staging.isolated') !== true
            || config('canovia_staging.web_access_enabled') !== false
            || config('canovia_staging.web_access_explicitly_approved') !== false
            || config('canovia_mcp.tools_enabled') !== false
            || (config('canovia_staging.database_mode') !== 'render_postgres'
                && ! app()->runningUnitTests())
            || ! $actorBootstrap->isPinnedDatabase()) {
            return 'blocked';
        }

        try {
            if (User::query()->count() !== 1
                || Plan::query()->count() !== 1
                || Task::query()->count() !== 2
                || DB::table('mcp_linked_subjects')->exists()
                || DB::table('mcp_delegated_grants')->exists()
                || DB::table('mcp_delegated_access_events')->exists()) {
                return 'blocked';
            }

            $actor = User::query()->sole();
            $plan = Plan::query()->sole();
            $tasks = Task::query()->where('plan_id', $plan->id)
                ->orderBy('sort_order')->orderBy('id')->get();

            return $actor->email === McpStagingSyntheticActorBootstrap::EMAIL
                && $actor->name === 'Canovia MCP Synthetic Owner'
                && $actor->email_verified_at !== null
                && (int) $plan->user_id === (int) $actor->id
                && $plan->creation_request_id === self::REQUEST_ID
                && $plan->title === self::TITLE
                && ! (bool) $plan->is_public
                && ! (bool) $plan->is_collaborative
                && $tasks->count() === 2
                && $tasks[0]->title === self::FIRST_TASK
                && $tasks[0]->status === 'doing'
                && (int) $tasks[0]->progress_percent === 25
                && $tasks[1]->title === self::SECOND_TASK
                && $tasks[1]->status === 'todo'
                && (int) $tasks[1]->progress_percent === 0
                    ? 'ready' : 'blocked';
        } catch (\Throwable) {
            // No SQL, DSN, identity or exception content in operator output.
            return 'blocked';
        }
    }

    /** @return 'blocked'|'created'|'already_present' */
    public function provision(McpStagingSyntheticActorBootstrap $actorBootstrap): string
    {
        // Refuse to query ANY database before the existing actor's pinned
        // staging-only gate passes. Real deploys also require PostgreSQL.
        if (! $actorBootstrap->canProvision()
            || (config('canovia_staging.database_mode') !== 'render_postgres'
                && ! app()->runningUnitTests())) {
            return 'blocked';
        }

        return DB::transaction(function (): string {
            $actor = User::query()
                ->where('email', McpStagingSyntheticActorBootstrap::EMAIL)
                ->first();

            // Synthetic owner is created via the independent guarded CLI
            // first. Refuse any other identity or malformed owner record.
            if (! $actor
                || $actor->name !== 'Canovia MCP Synthetic Owner'
                || $actor->email_verified_at === null
                || User::query()->where('id', '!=', $actor->id)->exists()) {
                return 'blocked';
            }

            $plan = Plan::query()
                ->where('creation_request_id', self::REQUEST_ID)
                ->first();

            if ($plan !== null) {
                // No silent mutation or repair of unexpected existing data.
                if (Plan::query()->count() !== 1
                    || (int) $plan->user_id !== (int) $actor->id
                    || $plan->title !== self::TITLE
                    || (bool) $plan->is_public
                    || (bool) $plan->is_collaborative) {
                    return 'blocked';
                }

                $tasks = Task::query()->where('plan_id', $plan->id)
                    ->orderBy('sort_order')->get();
                if (Task::query()->count() !== 2
                    || $tasks->count() !== 2
                    || $tasks[0]->title !== self::FIRST_TASK
                    || $tasks[1]->title !== self::SECOND_TASK) {
                    return 'blocked';
                }

                return 'already_present';
            }

            if (Plan::query()->exists() || Task::query()->exists()) {
                return 'blocked';
            }

            $plan = Plan::query()->create([
                'user_id' => $actor->id,
                'owner_token' => Str::random(64),
                'creation_request_id' => self::REQUEST_ID,
                'public_slug' => (string) Str::uuid(),
                'title' => self::TITLE,
                'description' => 'Invented data only. No personal details or real project information.',
                'category' => '個人開発',
                'priority' => 1,
                'priority_mode' => 'manual',
                'start_date' => today(),
                'deadline' => today()->addDays(14),
                'is_public' => false,
                'is_collaborative' => false,
            ]);

            Task::query()->create([
                'plan_id' => $plan->id,
                'title' => self::FIRST_TASK,
                'status' => 'doing',
                'progress_percent' => 25,
                'priority' => 1,
                'sort_order' => 1,
            ]);
            Task::query()->create([
                'plan_id' => $plan->id,
                'title' => self::SECOND_TASK,
                'status' => 'todo',
                'progress_percent' => 0,
                'priority' => 2,
                'sort_order' => 2,
            ]);

            return 'created';
        });
    }
}
