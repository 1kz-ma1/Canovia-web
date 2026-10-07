<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

final class PlanLifecyclePersonalizationAdapter
{
    private const COMPLETED_PLAN_MEMORY = 20;

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly PersonalizationLivingProfileService $livingProfile,
        private readonly PlanCompletionFingerprintService $fingerprints,
    ) {}

    public function observeCreated(
        Request $request,
        Plan $plan,
    ): void {
        if (! $this->eligible($request, $plan)) {
            return;
        }

        $context = $this->contexts->current($request);
        $lifecycle = $this->lifecycle($context);

        $lifecycle['last_created_plan_id'] = (int) $plan->id;
        $lifecycle['last_created_at'] = now()->toIso8601String();

        $this->contexts->storeObservedCandidate(
            $request,
            ['plan_lifecycle' => $lifecycle],
        );

        $this->livingProfile->refresh(
            $request,
            'new_plan',
            $plan,
        );
    }

    public function observeTaskUpdated(
        Request $request,
        Task $task,
    ): void {
        if (! $task->wasChanged(['status', 'progress_percent'])) {
            return;
        }

        if (
            ! $this->taskIsComplete($task)
            && $task->status !== 'cancelled'
        ) {
            return;
        }

        $plan = $task->relationLoaded('plan')
            ? $task->plan
            : $task->plan()->first();

        if (! $plan instanceof Plan || ! $this->eligible($request, $plan)) {
            return;
        }

        $activeTaskCount = Task::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', 'cancelled')
            ->count();

        if ($activeTaskCount === 0) {
            return;
        }

        $incompleteExists = Task::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query
                    ->where('status', '!=', 'done')
                    ->where('progress_percent', '<', 100);
            })
            ->exists();

        if ($incompleteExists) {
            return;
        }

        $context = $this->contexts->current($request);
        $lifecycle = $this->lifecycle($context);
        $completedIds = collect(
            $lifecycle['recent_completed_plan_ids']
            ?? [],
        )
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $snapshots = is_array(
            $lifecycle['completion_snapshots']
            ?? null,
        )
            ? $lifecycle['completion_snapshots']
            : [];

        $planKey = (string) $plan->id;
        $existing = is_array($snapshots[$planKey] ?? null)
            ? $snapshots[$planKey]
            : [];
        $existingFingerprint = (string) (
            $existing['fingerprint']
            ?? ''
        );
        $current = $this->fingerprints->snapshot($plan);

        $hasExistingFingerprint = preg_match(
            '/^[a-f0-9]{64}$/',
            $existingFingerprint,
        ) === 1;

        if (
            $hasExistingFingerprint
            && hash_equals(
                $existingFingerprint,
                $current['fingerprint'],
            )
        ) {
            return;
        }

        $legacyRemembered =
            ! $hasExistingFingerprint
            && $completedIds->contains((int) $plan->id);

        if ($legacyRemembered) {
            $snapshots = $this->rememberSnapshot(
                $snapshots,
                $planKey,
                [
                    'schema_version' =>
                        $current['schema_version'],
                    'fingerprint' =>
                        $current['fingerprint'],
                    'revision' => 1,
                    'task_count' =>
                        $current['task_count'],
                    'completed_at' =>
                        now()->toIso8601String(),
                    'change' =>
                        'legacy_baseline',
                ],
            );

            $lifecycle['completion_snapshots'] = $snapshots;
            $lifecycle['last_completion_baselined_plan_id'] =
                (int) $plan->id;
            $lifecycle['last_completion_baselined_at'] =
                now()->toIso8601String();

            $this->contexts->storeObservedCandidate(
                $request,
                ['plan_lifecycle' => $lifecycle],
            );

            return;
        }

        $revision = $hasExistingFingerprint
            ? max(1, (int) ($existing['revision'] ?? 1)) + 1
            : 1;
        $change = $hasExistingFingerprint
            ? 'material_revision'
            : 'first_completion';

        $snapshots = $this->rememberSnapshot(
            $snapshots,
            $planKey,
            [
                'schema_version' => $current['schema_version'],
                'fingerprint' => $current['fingerprint'],
                'revision' => $revision,
                'task_count' => $current['task_count'],
                'completed_at' => now()->toIso8601String(),
                'change' => $change,
            ],
        );

        $completedIds = $completedIds
            ->reject(fn ($id) => (int) $id === (int) $plan->id)
            ->push((int) $plan->id)
            ->slice(-self::COMPLETED_PLAN_MEMORY)
            ->values();

        $lifecycle['recent_completed_plan_ids'] =
            $completedIds->all();
        $lifecycle['completion_snapshots'] = $snapshots;
        $lifecycle['last_completed_plan_id'] = (int) $plan->id;
        $lifecycle['last_completion_trigger_task_id'] =
            (int) $task->id;
        $lifecycle['last_completed_task_id'] =
            $this->taskIsComplete($task)
                ? (int) $task->id
                : null;
        $lifecycle['last_completed_at'] = now()->toIso8601String();
        $lifecycle['last_completion_revision'] = $revision;
        $lifecycle['last_completion_change'] = $change;

        $this->contexts->storeObservedCandidate(
            $request,
            ['plan_lifecycle' => $lifecycle],
        );

        $this->livingProfile->refresh(
            $request,
            'plan_completed',
            $plan,
            [
                'completion_revision' => $revision,
                'completion_change' => $change,
            ],
        );
    }

    /**
     * @param array<string,array<string,mixed>> $snapshots
     * @param array<string,mixed> $snapshot
     * @return array<string,array<string,mixed>>
     */
    private function rememberSnapshot(
        array $snapshots,
        string $planKey,
        array $snapshot,
    ): array {
        unset($snapshots[$planKey]);
        $snapshots[$planKey] = $snapshot;

        if (count($snapshots) <= self::COMPLETED_PLAN_MEMORY) {
            return $snapshots;
        }

        return array_slice(
            $snapshots,
            -self::COMPLETED_PLAN_MEMORY,
            null,
            true,
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function lifecycle(array $context): array
    {
        $value = data_get(
            $context,
            'context_sources.observed.plan_lifecycle',
            [],
        );

        return is_array($value) ? $value : [];
    }

    private function eligible(
        Request $request,
        Plan $plan,
    ): bool {
        $user = $request->user();

        if (
            ! $user
            || $plan->user_id === null
            || (int) $plan->user_id !== (int) $user->id
        ) {
            return false;
        }

        $context = $this->contexts->current($request);

        return
            (array) data_get(
                $context,
                'context_sources.self_reported',
                [],
            ) !== []
            || (array) data_get(
                $context,
                'context_sources.observed',
                [],
            ) !== []
            || (array) data_get(
                $context,
                'context_sources.inferred',
                [],
            ) !== [];
    }

    private function taskIsComplete(Task $task): bool
    {
        return
            $task->status === 'done'
            || (int) $task->progress_percent >= 100;
    }
}
