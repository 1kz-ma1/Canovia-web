<?php

namespace App\Intelligence\Services;

use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Support\CanonicalJson;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceDecisionTrace;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class DecisionTraceStore
{
    public function __construct(
        private readonly StateSnapshotStore $stateStore,
    ) {}

    public function persist(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        Decision $decision,
        ?int $userId = null,
        ?int $planId = null,
        array $metadata = [],
    ): IntelligenceDecisionTrace {
        $expectedInput = IntelligenceFingerprint::decisionInput($state, $readiness);

        if (! hash_equals($expectedInput, $decision->inputFingerprint)) {
            throw new InvalidArgumentException(
                'Decision input fingerprint does not match the supplied State and Readiness.',
            );
        }

        $storedState = $this->stateStore->persist(
            $state,
            $userId,
            $planId,
        );

        $decisionReference = IntelligenceFingerprint::decisionReference($decision);

        return IntelligenceDecisionTrace::query()->firstOrCreate(
            [
                'decision_reference' => $decisionReference,
            ],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'intelligence_state_snapshot_id' => $storedState->id,
                'domain' => $state->domain->value,
                'scope_type' => $state->scopeType,
                'scope_id' => $state->scopeId !== null ? (string) $state->scopeId : null,
                'state_reference' => $storedState->state_reference,
                'state_fingerprint' => $storedState->state_fingerprint,
                'readiness_fingerprint' => IntelligenceFingerprint::readiness($readiness),
                'readiness_score' => $readiness->score,
                'readiness_level' => $readiness->level->value,
                'readiness_confidence' => $readiness->confidence->value,
                'readiness_components' => CanonicalJson::normalize($readiness->components),
                'readiness_gaps' => CanonicalJson::normalize($readiness->gaps),
                'readiness_metadata' => Arr::only(
                    CanonicalJson::normalize($readiness->metadata),
                    ['policy', 'target_score_percent', 'meaning'],
                ),
                'decision_type' => $decision->type,
                'reason_code' => $decision->reasonCode,
                'decision_summary' => mb_substr($decision->summary, 0, 255),
                'decision_confidence' => $decision->confidence->value,
                'input_fingerprint' => $decision->inputFingerprint,
                'decision_reasons' => CanonicalJson::normalize($decision->reasons),
                'decision_metadata' => Arr::only(
                    CanonicalJson::normalize($decision->metadata),
                    [
                        'policy_version',
                        'candidate_count',
                        'candidate_types',
                        'selected_priority',
                        'weakness_count',
                        'target_scope_item_id',
                        'target_task_id',
                        'subject',
                        'unit',
                        'remaining_unit',
                        'mastery_score_percent',
                        'retention_score_percent',
                    ],
                ),
                'metadata' => Arr::only($metadata, [
                    'engine',
                    'engine_version',
                    'policy_version',
                ]),
            ],
        );
    }
}
