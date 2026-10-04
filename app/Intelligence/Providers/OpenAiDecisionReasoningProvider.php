<?php

namespace App\Intelligence\Providers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Intelligence\Contracts\DecisionReasoningProvider;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\ReasoningProviderSelection;
use App\Intelligence\Data\ReasoningRequest;
use App\Intelligence\Support\CanonicalJson;
use App\Models\Plan;
use App\Services\NativeAiGateway;

final class OpenAiDecisionReasoningProvider implements DecisionReasoningProvider
{
    public function __construct(
        private readonly NativeAiGateway $gateway,
    ) {}

    public function key(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->gateway->isConfigured();
    }

    public function select(ReasoningRequest $request): ReasoningProviderSelection
    {
        $candidateTypes = collect($request->candidates)
            ->pluck('type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($candidateTypes === []) {
            throw new NativeAiExecutionException(
                'Decision reasoning candidateがありません。',
                'reasoning_candidates_missing',
            );
        }

        $payload = [
            'state' => [
                'domain' => $request->state->domain->value,
                'scope_type' => $request->state->scopeType,
                'metrics' => $request->state->metrics,
                'facts' => $request->state->facts,
            ],
            'readiness' => [
                'score' => $request->readiness->score,
                'level' => $request->readiness->level->value,
                'confidence' => $request->readiness->confidence->value,
                'components' => $request->readiness->components,
                'gaps' => $request->readiness->gaps,
            ],
            'baseline' => [
                'type' => $request->baselineDecision->type,
                'reason_code' => $request->baselineDecision->reasonCode,
                'confidence' => $request->baselineDecision->confidence->value,
            ],
            'candidates' => collect($request->candidates)
                ->map(fn ($candidate) => [
                    'type' => $candidate->type,
                    'reason_code' => $candidate->reasonCode,
                    'priority' => $candidate->priority,
                    'confidence' => $candidate->confidence->value,
                    'reasons' => $candidate->reasons,
                    'metadata' => $candidate->metadata,
                ])
                ->values()
                ->all(),
        ];

        $prompt = implode("\n", [
            'You are Canovia Decision Reasoning.',
            'Choose exactly one candidate from the provided candidate list.',
            'Do not invent a new action, candidate type, task, or plan.',
            'Do not rewrite the product state.',
            'Use the normalized State and Readiness only.',
            'Return only the structured response required by the schema.',
            '',
            CanonicalJson::encode($payload),
        ]);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'selected_type' => [
                    'type' => 'string',
                    'enum' => $candidateTypes,
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'reason_codes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 8,
                ],
            ],
            'required' => [
                'selected_type',
                'confidence',
                'reason_codes',
            ],
        ];

        $result = $this->gateway->generateStructured(
            purpose: 'intelligence_decision_reasoning',
            prompt: $prompt,
            schema: $schema,
            schemaName: 'canovia_decision_reasoning',
            plan: $request->planId
                ? Plan::query()->find($request->planId)
                : null,
            task: null,
            maxOutputTokens: max(
                200,
                (int) config('intelligence.reasoning.openai.max_output_tokens', 700),
            ),
            userId: $request->userId,
            capacityTier: 'standard',
            metadata: [
                'domain' => $request->state->domain->value,
                'candidate_count' => count($request->candidates),
                'state_reference' => substr(
                    App\Intelligence\Support\IntelligenceFingerprint::stateReference($request->state),
                    0,
                    128,
                ),
            ],
            featureKey: FeatureKey::AutomaticAiExecution,
        );

        $data = $result['data'];
        $selectedType = trim((string) ($data['selected_type'] ?? ''));

        if (! in_array($selectedType, $candidateTypes, true)) {
            $this->gateway->markRunFailed(
                (int) $result['run_id'],
                'reasoning_candidate_mismatch',
                'Native AI selected a candidate that is not in the allowed set.',
            );

            throw new NativeAiExecutionException(
                'Native AIのDecision候補がCanoviaの許可候補と一致しません。',
                'reasoning_candidate_mismatch',
                (int) $result['run_id'],
            );
        }

        if (! is_numeric($data['confidence'] ?? null)) {
            $this->gateway->markRunFailed(
                (int) $result['run_id'],
                'reasoning_confidence_invalid',
                'Native AI did not return a numeric confidence.',
            );

            throw new NativeAiExecutionException(
                'Native AIのDecision confidenceを読み取れません。',
                'reasoning_confidence_invalid',
                (int) $result['run_id'],
            );
        }

        $knownReasonCodes = collect($request->candidates)
            ->flatMap(fn ($candidate) => [
                $candidate->reasonCode,
                ...$candidate->reasons,
            ])
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values();

        $reasonCodes = collect((array) ($data['reason_codes'] ?? []))
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->filter(fn (string $item) => $knownReasonCodes->contains($item))
            ->unique()
            ->take(8)
            ->values()
            ->all();

        return new ReasoningProviderSelection(
            selectedType: $selectedType,
            confidence: new Confidence(
                max(0, min(1, round((float) $data['confidence'], 4))),
            ),
            provider: (string) $result['provider'],
            model: (string) $result['model'],
            nativeAiRunId: (int) $result['run_id'],
            reasonCodes: $reasonCodes,
            metadata: [
                'schema' => 'canovia_decision_reasoning',
            ],
        );
    }
}
