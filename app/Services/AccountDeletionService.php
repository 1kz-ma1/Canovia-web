<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AccountDeletionService
{
    /**
     * Delete the Canovia account and user-owned content.
     *
     * Shared Plans owned by another user survive. User-authored rows that can
     * outlive a User through nullable foreign keys are explicitly removed.
     */
    public function delete(User $user): void
    {
        $userId = (int) $user->id;
        $email = (string) $user->email;
        $ownedPlanIds = Plan::query()
            ->where('user_id', $userId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $storagePaths = $this->storagePaths(
            $userId,
            $ownedPlanIds,
        );

        DB::transaction(function () use (
            $user,
            $userId,
            $email,
            $ownedPlanIds,
        ): void {
            // Remove file-backed rows before Plan cascade so their DB records
            // do not turn into ownerless/null-user records.
            $this->deleteRows(
                'career_captures',
                $userId,
                $ownedPlanIds,
            );
            $this->deleteRows(
                'study_recall_sources',
                $userId,
                $ownedPlanIds,
            );
            $this->deleteRows(
                'inbox_items',
                $userId,
                $ownedPlanIds,
            );

            if ($ownedPlanIds->isNotEmpty()) {
                Plan::query()
                    ->whereIn('id', $ownedPlanIds->all())
                    ->get()
                    ->each(fn (Plan $plan) => $plan->delete());
            }

            // Child-first for records that may exist on Plans owned by other
            // users or without a Plan at all.
            foreach ([
                'intelligence_action_projections',
                'intelligence_reasoning_runs',
                'intelligence_decision_traces',
                'intelligence_state_snapshots',
                'execution_activities',
                'guided_executions',
                'task_evidences',
                'practice_question_demands',
                'study_practice_attempts',
                'study_practice_sessions',
                'study_recall_reviews',
                'study_recall_sources',
                'study_scope_captures',
                'native_ai_runs',
                'goal_discovery_messages',
                'goal_contexts',
                'career_captures',
                'inbox_items',
                'feedbacks',
                'plan_activity_logs',
                'roadmap_votes',
                'pwa_handoffs',
            ] as $table) {
                $this->deleteByUserId($table, $userId);
            }

            if (
                Schema::hasTable('password_reset_tokens')
                && Schema::hasColumn('password_reset_tokens', 'email')
            ) {
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->delete();
            }

            if (
                Schema::hasTable('sessions')
                && Schema::hasColumn('sessions', 'user_id')
            ) {
                DB::table('sessions')
                    ->where('user_id', $userId)
                    ->delete();
            }

            // Remaining direct user relations use database cascade/null rules.
            // Cascade-owned data (memberships, product grants, future memos,
            // companion preferences, provider connections, etc.) is removed
            // here together with the User.
            $user->delete();
        });

        $storagePaths->each(function (string $path): void {
            try {
                Storage::delete($path);
            } catch (\Throwable) {
                // The account is already gone. Missing/unavailable storage
                // must not resurrect or roll back personal DB data.
            }
        });
    }

    /**
     * @param Collection<int,int> $ownedPlanIds
     * @return Collection<int,string>
     */
    private function storagePaths(
        int $userId,
        Collection $ownedPlanIds,
    ): Collection {
        $paths = collect();

        foreach ([
            ['inbox_items', 'storage_path'],
            ['study_recall_sources', 'storage_path'],
            ['career_captures', 'screenshot_path'],
        ] as [$table, $column]) {
            if (
                ! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $column)
            ) {
                continue;
            }

            $query = DB::table($table)
                ->whereNotNull($column);

            $query->where(function ($query) use (
                $table,
                $userId,
                $ownedPlanIds,
            ): void {
                $hasUser = Schema::hasColumn($table, 'user_id');
                $hasPlan = Schema::hasColumn($table, 'plan_id');

                if ($hasUser) {
                    $query->where('user_id', $userId);
                }

                if ($hasPlan && $ownedPlanIds->isNotEmpty()) {
                    $method = $hasUser ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('plan_id', $ownedPlanIds->all());
                }
            });

            $paths = $paths->merge(
                $query->pluck($column)
                    ->filter(fn ($path) => is_string($path) && trim($path) !== ''),
            );
        }

        return $paths->unique()->values();
    }

    /**
     * Delete rows owned by the user and/or attached to owned Plans.
     *
     * @param Collection<int,int> $ownedPlanIds
     */
    private function deleteRows(
        string $table,
        int $userId,
        Collection $ownedPlanIds,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $hasUser = Schema::hasColumn($table, 'user_id');
        $hasPlan = Schema::hasColumn($table, 'plan_id');

        if (! $hasUser && ! $hasPlan) {
            return;
        }

        $query = DB::table($table);

        $query->where(function ($query) use (
            $table,
            $hasUser,
            $hasPlan,
            $userId,
            $ownedPlanIds,
        ): void {
            if ($hasUser) {
                $query->where('user_id', $userId);
            }

            if ($hasPlan && $ownedPlanIds->isNotEmpty()) {
                $method = $hasUser ? 'orWhereIn' : 'whereIn';
                $query->{$method}('plan_id', $ownedPlanIds->all());
            }
        });

        $query->delete();
    }

    private function deleteByUserId(
        string $table,
        int $userId,
    ): void {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'user_id')
        ) {
            return;
        }

        DB::table($table)
            ->where('user_id', $userId)
            ->delete();
    }
}
