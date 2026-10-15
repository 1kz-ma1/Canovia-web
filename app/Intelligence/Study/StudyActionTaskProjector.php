<?php

namespace App\Intelligence\Study;

use App\Intelligence\Data\StudyAdaptiveActionResult;
use App\Intelligence\Services\ActionProjectionStore;
use App\Intelligence\Services\IntelligenceEvidenceFreshnessGuard;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceActionProjection;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\LearningContextSourceEpochService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StudyActionTaskProjector
{
    public function __construct(
        private readonly ActionProjectionStore $actionStore,
        private readonly IntelligenceEvidenceFreshnessGuard $freshness,
        private readonly StudyPlanIntelligenceService $intelligence,
        private readonly PlanOwnershipService $ownership,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    /**
     * The caller's preflight edit permission and generated Action are NOT
     * durable approval. Lock the current Plan/Action and revalidate policy
     * and source-derived state inside the Task-creating transaction.
     *
     * @return array{task:Task,created:bool}
     */
    public function project(
        Plan $plan,
        StudyAdaptiveActionResult $result,
        Request $request,
    ): array {
        $action = $result->primaryAction();
        $projection = $result->projection;

        if (! $action || ! $projection) {
            throw new InvalidArgumentException(
                'A persisted current Action is required before Task projection.',
            );
        }

        return DB::transaction(function () use ($plan, $result, $request, $action, $projection) {
            $currentPlan = Plan::query()->whereKey($plan->id)
                ->lockForUpdate()->firstOrFail();

            // Plan owner revocation is serialized by the Plan row lock.
            // A collaborator's editor membership also needs a fresh read,
            // without relying on a controller-scoped cached role.
            $isOwner = $this->ownership->owns($request, $currentPlan);
            $isEditor = false;
            if (! $isOwner && $request->user() && $currentPlan->is_collaborative) {
                $membership = PlanMember::query()
                    ->where('plan_id', $currentPlan->id)
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()->first();
                $isEditor = $membership?->role === PlanMember::ROLE_EDITOR;
            }

            abort_unless($isOwner || $isEditor, 403);
            abort_unless($this->profiles->forPlan($currentPlan)->key === 'study', 409);

            $currentProjection = IntelligenceActionProjection::query()
                ->whereKey($projection->id)
                ->lockForUpdate()
                ->first();

            abort_unless($currentProjection !== null
                && (int) $currentProjection->plan_id === (int) $currentPlan->id
                && $currentProjection->status === IntelligenceActionProjection::STATUS_ACTIVE
                && hash_equals(
                    (string) $currentProjection->action_fingerprint,
                    IntelligenceFingerprint::action($action),
                )
                && hash_equals(
                    (string) $currentProjection->state_fingerprint,
                    IntelligenceFingerprint::state($result->intelligence->state),
                ), 409);

            // The source may have changed since refresh() prepared the Action.
            // Verify both the original Evidence trace and the entire CURRENT
            // Study projection (including new evidence and scope corrections).
            $snapshot = $currentProjection->load('decisionTrace.stateSnapshot')
                ->decisionTrace?->stateSnapshot;
            abort_unless($snapshot !== null
                && $this->freshness->isCurrent($snapshot)
                && $snapshot->captured_at !== null
                && hash_equals(
                    (string) $snapshot->state_fingerprint,
                    IntelligenceFingerprint::state(
                        $this->intelligence->evaluate(
                            $currentPlan,
                            $snapshot->captured_at->toDateTimeImmutable(),
                        )->state,
                    ),
                ), 409);

            $existing = $currentProjection->projected_task_id === null ? null
                : Task::query()->whereKey($currentProjection->projected_task_id)
                    ->lockForUpdate()->first();
            if ($existing && (int) $existing->plan_id === (int) $currentPlan->id) {
                return ['task' => $existing, 'created' => false];
            }
            abort_unless($currentProjection->projected_task_id === null, 409);

            $targetTaskId = filter_var(
                data_get($action->metadata, 'target_task_id'),
                FILTER_VALIDATE_INT,
            );
            if ($targetTaskId !== false && (int) $targetTaskId > 0) {
                $target = Task::query()
                    ->where('plan_id', $currentPlan->id)
                    ->whereKey((int) $targetTaskId)
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->where('progress_percent', '<', 100)
                    ->lockForUpdate()
                    ->first();

                if ($target) {
                    $this->actionStore->attachProjectedTask($currentProjection, $target);
                    return ['task' => $target, 'created' => false];
                }
            }

            if ((string) data_get($action->metadata, 'route_kind') !== 'project_task') {
                throw new InvalidArgumentException(
                    'This Action does not require a new Task projection.',
                );
            }

            $sortOrder = (int) Task::query()
                ->where('plan_id', $currentPlan->id)->max('sort_order');

            $task = Task::query()->create([
                'plan_id' => (int) $currentPlan->id,
                'title' => mb_substr($action->title, 0, 255),
                'description' => mb_substr(
                    $action->intent
                    ."\n\nCanovia Intelligence Actionから作成。"
                    ." Task進捗はStudy Stateの真実としては使用しません。",
                    0,
                    4000,
                ),
                'estimated_minutes' => 0,
                'remaining_minutes' => 0,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 1,
                'sort_order' => $sortOrder + 1,
                'next_action_note' => mb_substr($action->title, 0, 1000),
            ]);

            $this->actionStore->attachProjectedTask($currentProjection, $task);
            // Only a genuinely new Task enters the eligible source population.
            // Existing projected or reattached targets return above unchanged.
            app(LearningContextSourceEpochService::class)
                ->record($currentPlan, 'task_created');

            return ['task' => $task, 'created' => true];
        }, 3);
    }
}
