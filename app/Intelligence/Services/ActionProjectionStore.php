<?php

namespace App\Intelligence\Services;

use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Support\CanonicalJson;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ActionProjectionStore
{
    public function persist(
        StateSnapshot $state,
        Decision $decision,
        ActionProposal $action,
        IntelligenceDecisionTrace $trace,
        ?int $userId = null,
        ?int $planId = null,
    ): IntelligenceActionProjection {
        $expectedDecisionReference = IntelligenceFingerprint::decisionReference(
            $decision,
        );

        if (! hash_equals(
            $expectedDecisionReference,
            (string) $trace->decision_reference,
        )) {
            throw new InvalidArgumentException(
                'Action projection trace does not match the supplied Decision.',
            );
        }

        $actionFingerprint = IntelligenceFingerprint::action($action);
        $actionReference = IntelligenceFingerprint::actionReference(
            $state,
            $action,
        );

        return DB::transaction(function () use (
            $state,
            $action,
            $trace,
            $userId,
            $planId,
            $actionFingerprint,
            $actionReference,
        ) {
            IntelligenceActionProjection::query()
                ->where('domain', $state->domain->value)
                ->where('scope_type', $state->scopeType)
                ->when(
                    $state->scopeId === null,
                    fn ($query) => $query->whereNull('scope_id'),
                    fn ($query) => $query->where(
                        'scope_id',
                        (string) $state->scopeId,
                    ),
                )
                ->when(
                    $planId === null,
                    fn ($query) => $query->whereNull('plan_id'),
                    fn ($query) => $query->where('plan_id', $planId),
                )
                ->where('status', IntelligenceActionProjection::STATUS_ACTIVE)
                ->where('action_reference', '!=', $actionReference)
                ->update([
                    'status' => IntelligenceActionProjection::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ]);

            $projection = IntelligenceActionProjection::query()
                ->firstOrCreate(
                    ['action_reference' => $actionReference],
                    [
                        'user_id' => $userId,
                        'plan_id' => $planId,
                        'intelligence_decision_trace_id' => $trace->id,
                        'domain' => $state->domain->value,
                        'scope_type' => $state->scopeType,
                        'scope_id' => $state->scopeId !== null
                            ? (string) $state->scopeId
                            : null,
                        'state_fingerprint' => IntelligenceFingerprint::state(
                            $state,
                        ),
                        'action_fingerprint' => $actionFingerprint,
                        'kind' => mb_substr($action->kind, 0, 64),
                        'title' => mb_substr($action->title, 0, 255),
                        'intent' => mb_substr($action->intent, 0, 4000),
                        'confidence' => $action->confidence->value,
                        'estimated_minutes' => $action->estimatedMinutes,
                        'success_signals' => CanonicalJson::normalize(
                            $action->successSignals,
                        ),
                        'metadata' => Arr::only(
                            CanonicalJson::normalize($action->metadata),
                            [
                                'policy_version',
                                'reason_code',
                                'target_scope_item_id',
                                'target_task_id',
                                'route_kind',
                                'subject',
                                'unit',
                                'remaining_unit',
                                'mastery_score_percent',
                                'retention_score_percent',
                                'readiness_score',
                                'deadline_pressure',
                                'projection_policy',
                            ],
                        ),
                        'status' => IntelligenceActionProjection::STATUS_ACTIVE,
                    ],
                );

            if (! $projection->wasRecentlyCreated) {
                $projection->update([
                    'intelligence_decision_trace_id' => $trace->id,
                    'confidence' => $action->confidence->value,
                    'status' => IntelligenceActionProjection::STATUS_ACTIVE,
                    'superseded_at' => null,
                    'dismissed_at' => null,
                ]);
            }

            return $projection->fresh();
        }, 3);
    }

    public function attachProjectedTask(
        IntelligenceActionProjection $projection,
        Task $task,
    ): IntelligenceActionProjection {
        if (
            $projection->plan_id !== null
            && (int) $projection->plan_id !== (int) $task->plan_id
        ) {
            throw new InvalidArgumentException(
                'Projected Task must belong to the Action Plan.',
            );
        }

        $projection->update([
            'projected_task_id' => (int) $task->id,
        ]);

        return $projection->fresh();
    }

    public function latestActiveForPlan(int $planId): ?IntelligenceActionProjection
    {
        return IntelligenceActionProjection::query()
            ->where('plan_id', $planId)
            ->where('status', IntelligenceActionProjection::STATUS_ACTIVE)
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }
}
