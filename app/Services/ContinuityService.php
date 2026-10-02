<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ContinuityService
{
    public function forPlans(Collection $plans, string $actorToken): ?array
    {
        $session = $this->latestSessionForPlans($plans, $actorToken);
        $session?->loadMissing(['plan', 'task']);

        return $session ? $this->fromSession($session) : null;
    }

    /**
     * Home needs active/pending sessions and continuity at the same time.
     * Query the two session scopes separately to preserve their semantics, then
     * hydrate Plan/Task relations once across the combined model set.
     *
     * @return array{
     *   active_work_session:?WorkSession,
     *   pending_plan_updates:Collection,
     *   continuity:?array
     * }
     */
    public function homeContext(Collection $plans, string $actorToken): array
    {
        $dashboardSessions = WorkSession::query()
            ->where('actor_token', $actorToken)
            ->where(function ($query) {
                $query->whereIn('status', ['active', 'paused'])
                    ->orWhere(function ($pendingQuery) {
                        $pendingQuery
                            ->where('needs_plan_update', true)
                            ->whereIn('status', ['completed', 'interrupted']);
                    });
            })
            ->get();

        $latestSession = $this->latestSessionForPlans($plans, $actorToken);

        $sessionsToHydrate = $dashboardSessions
            ->when($latestSession, fn (Collection $sessions) => $sessions->push($latestSession))
            ->unique('id')
            ->values();

        if ($sessionsToHydrate->isNotEmpty()) {
            (new EloquentCollection($sessionsToHydrate->all()))
                ->loadMissing(['plan', 'task']);
        }

        $pendingPlanUpdates = $dashboardSessions
            ->filter(fn (WorkSession $session) => (bool) $session->needs_plan_update
                && in_array($session->status, ['completed', 'interrupted'], true))
            ->sortByDesc(fn (WorkSession $session) => $session->ended_at?->timestamp ?? 0)
            ->take(5)
            ->values();

        $activeWorkSession = $dashboardSessions
            ->filter(fn (WorkSession $session) => in_array($session->status, ['active', 'paused'], true))
            ->sortByDesc(fn (WorkSession $session) => $session->started_at?->timestamp ?? 0)
            ->first();

        return [
            'active_work_session' => $activeWorkSession,
            'pending_plan_updates' => $pendingPlanUpdates,
            'continuity' => $latestSession ? $this->fromSession($latestSession) : null,
        ];
    }

    private function latestSessionForPlans(Collection $plans, string $actorToken): ?WorkSession
    {
        $accountPlanIds = $plans
            ->filter(fn (Plan $plan) => $plan->user_id !== null)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $guestPlanIds = $plans
            ->filter(fn (Plan $plan) => $plan->user_id === null)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($accountPlanIds === [] && $guestPlanIds === []) {
            return null;
        }

        return WorkSession::query()
            ->where(function ($query) use ($accountPlanIds, $guestPlanIds, $actorToken) {
                if ($accountPlanIds !== []) {
                    $query->whereIn('plan_id', $accountPlanIds);
                }

                if ($guestPlanIds !== []) {
                    $method = $accountPlanIds !== [] ? 'orWhere' : 'where';
                    $query->{$method}(function ($guestQuery) use ($guestPlanIds, $actorToken) {
                        $guestQuery
                            ->whereIn('plan_id', $guestPlanIds)
                            ->where('actor_token', $actorToken);
                    });
                }
            })
            ->latest('started_at')
            ->first();
    }

    public function forPlan(Plan $plan, string $actorToken): ?array
    {
        $query = WorkSession::with(['plan', 'task'])
            ->where('plan_id', $plan->id);

        if ($plan->user_id === null) {
            $query->where('actor_token', $actorToken);
        }

        $session = $query->latest('started_at')->first();

        return $session ? $this->fromSession($session) : null;
    }

    private function fromSession(WorkSession $session): array
    {
        $task = $session->task;
        $active = in_array($session->status, ['active', 'paused'], true);
        $taskStartable = $task && ! in_array($task->status, ['done', 'cancelled'], true);

        return [
            'session_id' => $session->id,
            'plan_id' => $session->plan_id,
            'plan_title' => $session->plan?->title,
            'plan_icon' => $session->plan?->displayIcon() ?? '🧭',
            'plan_accent' => $session->plan?->accentKey() ?? 'sky',
            'plan_world' => $session->plan?->roadmapWorld() ?? 'default',
            'task_id' => $session->task_id,
            'task_title' => $task?->title ?? '前回の作業',
            'next_action_note' => $task?->next_action_note,
            'started_at' => $session->started_at,
            'ended_at' => $session->ended_at,
            'status' => $session->status,
            'is_active' => $active,
            'can_resume_task' => $taskStartable,
            'needs_plan_update' => (bool) $session->needs_plan_update,
        ];
    }
}
