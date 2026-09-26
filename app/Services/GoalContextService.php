<?php

namespace App\Services;

use App\Models\GoalContext;
use App\Models\GoalContextFact;
use App\Models\Plan;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class GoalContextService
{
    /**
     * Generic readiness is deterministic coverage, not an AI confidence score.
     *
     * desired_state       25
     * current_state       25
     * success_signal      20
     * constraints         10
     * drivers/measurement 20
     */
    public const READINESS_WEIGHTS = [
        'desired_state' => 25,
        'current_state' => 25,
        'success_signal' => 20,
        'constraints' => 10,
        'drivers_or_measurement' => 20,
    ];

    public function ensureForPlan(
        Plan $plan,
        ?int $userId = null,
        ?string $actorToken = null,
    ): GoalContext {
        $context = GoalContext::query()->firstOrCreate(
            ['plan_id' => (int) $plan->id],
            [
                'user_id' => $userId ?? $plan->user_id,
                'actor_token' => ($userId ?? $plan->user_id) ? null : $actorToken,
                'desired_state' => trim((string) $plan->title),
                'status' => 'discovery',
            ],
        );

        if (! $context->facts()->where('key', 'goal_title')->exists()) {
            $context->facts()->create([
                'type' => 'target',
                'key' => 'goal_title',
                'label' => '目標',
                'value_json' => ['text' => trim((string) $plan->title)],
                'source' => 'user_answer',
                'state' => 'confirmed',
                'confidence' => 1,
                'importance' => 5,
                'observed_at' => now(),
                'confirmed_at' => now(),
                'metadata' => ['origin' => 'plan_create'],
            ]);
        }

        return $this->recalculate($context);
    }

    public function createDraft(
        string $desiredState,
        ?int $userId = null,
        ?string $actorToken = null,
    ): GoalContext {
        $desiredState = trim($desiredState);

        if ($desiredState === '') {
            throw new InvalidArgumentException('Desired state is required.');
        }

        $context = GoalContext::query()->create([
            'user_id' => $userId,
            'actor_token' => $userId ? null : $actorToken,
            'desired_state' => $desiredState,
            'status' => 'discovery',
        ]);

        $this->recordFact(
            $context,
            type: 'target',
            label: '目標',
            value: ['text' => $desiredState],
            source: 'user_answer',
            state: 'confirmed',
            key: 'goal_title',
            confidence: 1,
            importance: 5,
            metadata: ['origin' => 'goal_discovery'],
        );

        return $context->fresh(['facts']);
    }

    /**
     * @param array<string,mixed>|string|int|float|bool|null $value
     * @param array<string,mixed> $metadata
     */
    public function recordFact(
        GoalContext $context,
        string $type,
        string $label,
        mixed $value,
        string $source,
        string $state = 'confirmed',
        ?string $key = null,
        float $confidence = 1,
        int $importance = 3,
        array $metadata = [],
    ): GoalContextFact {
        if (! in_array($type, GoalContextFact::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported Goal Context fact type.');
        }

        if (! in_array($source, GoalContextFact::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported Goal Context fact source.');
        }

        if (! in_array($state, GoalContextFact::STATES, true)) {
            throw new InvalidArgumentException('Unsupported Goal Context fact state.');
        }

        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('Goal Context fact label is required.');
        }

        $key = $key !== null ? trim($key) : null;
        $key = $key !== '' ? $key : null;
        $confidence = max(0, min(1, $confidence));
        $importance = max(1, min(5, $importance));

        if ($key !== null && $state === 'confirmed') {
            $context->facts()
                ->where('key', $key)
                ->whereIn('state', ['confirmed', 'candidate', 'unknown'])
                ->update(['state' => 'superseded']);
        }

        $fact = $context->facts()->create([
            'type' => $type,
            'key' => $key,
            'label' => mb_substr($label, 0, 255),
            'value_json' => $this->normalizeValue($value),
            'source' => $source,
            'state' => $state,
            'confidence' => $confidence,
            'importance' => $importance,
            'observed_at' => now(),
            'confirmed_at' => $state === 'confirmed' ? now() : null,
            'metadata' => $metadata,
        ]);

        $this->recalculate($context);

        return $fact;
    }

    public function syncPlanTitle(Plan $plan, string $previousTitle): GoalContext
    {
        $context = $plan->goalContext ?: $this->ensureForPlan($plan, $plan->user_id);

        if (trim((string) $context->desired_state) === trim($previousTitle)) {
            $context->update(['desired_state' => trim((string) $plan->title)]);
        }

        $this->recordFact(
            $context,
            type: 'target',
            label: '目標',
            value: ['text' => trim((string) $plan->title)],
            source: 'user_answer',
            state: 'confirmed',
            key: 'goal_title',
            confidence: 1,
            importance: 5,
            metadata: ['origin' => 'plan_update'],
        );

        return $context->fresh(['facts']);
    }

    public function recalculate(GoalContext $context): GoalContext
    {
        $facts = $context->facts()
            ->where('state', 'confirmed')
            ->get();

        $coverage = [
            'desired_state' => filled(trim((string) $context->desired_state)),
            'current_state' => filled(trim((string) $context->current_state_summary))
                || $facts->contains(fn (GoalContextFact $fact) => $fact->type === 'current_state'),
            'success_signal' => $facts->contains(fn (GoalContextFact $fact) => $fact->type === 'signal'),
            'constraints' => $facts->contains(fn (GoalContextFact $fact) => $fact->type === 'constraint'),
            'drivers_or_measurement' => $facts->contains(
                fn (GoalContextFact $fact) => $fact->type === 'driver'
                    || ($fact->type === 'signal' && (bool) data_get($fact->metadata, 'measurement', false))
            ),
        ];

        $score = collect(self::READINESS_WEIGHTS)
            ->sum(fn (int $weight, string $dimension) => ($coverage[$dimension] ?? false) ? $weight : 0);

        $state = match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };

        $context->forceFill([
            'readiness_score' => $score,
            'readiness_state' => $state,
            'status' => $state === 'high' ? 'active' : 'discovery',
            'last_assessed_at' => now(),
        ])->save();

        return $context->fresh(['facts']);
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(GoalContext $context): array
    {
        $context->loadMissing('facts');
        $active = $context->facts->where('state', '!=', 'superseded')->values();

        return [
            'desired_state' => $context->desired_state,
            'current_state_summary' => $context->current_state_summary,
            'readiness' => [
                'score' => (int) $context->readiness_score,
                'state' => $context->readiness_state,
                'coverage' => $this->coverage($context, $active),
            ],
            'confirmed_facts' => $active->where('state', 'confirmed')->values(),
            'candidate_hints' => $active->where('state', 'candidate')->values(),
            'known_unknowns' => $active->where('state', 'unknown')->values(),
        ];
    }

    public function promptContext(GoalContext $context): string
    {
        $snapshot = $this->snapshot($context);

        $confirmed = $this->formatFacts($snapshot['confirmed_facts']);
        $candidates = $this->formatFacts($snapshot['candidate_hints']);
        $unknowns = $this->formatFacts($snapshot['known_unknowns']);

        return implode("\n", [
            '【GOAL CONTEXT】',
            'Desired State: '.trim((string) $snapshot['desired_state']),
            'Current State Summary: '.(trim((string) ($snapshot['current_state_summary'] ?? '')) ?: '未確認'),
            'Readiness: '.strtoupper((string) data_get($snapshot, 'readiness.state')).' / '.(int) data_get($snapshot, 'readiness.score').'/100',
            '',
            'CONFIRMED FACTS:',
            $confirmed ?: '- まだありません',
            '',
            'UNCONFIRMED HINTS:',
            $candidates ?: '- ありません',
            '',
            'KNOWN UNKNOWNS:',
            $unknowns ?: '- まだ構造化されていません',
            '',
            '注意: UNCONFIRMED HINTSは事実として扱わず、KNOWN UNKNOWNSは推測で埋めないでください。',
        ]);
    }

    /**
     * @param Collection<int,GoalContextFact> $facts
     * @return array<string,bool>
     */
    private function coverage(GoalContext $context, Collection $facts): array
    {
        $confirmed = $facts->where('state', 'confirmed');

        return [
            'desired_state' => filled(trim((string) $context->desired_state)),
            'current_state' => filled(trim((string) $context->current_state_summary))
                || $confirmed->contains(fn (GoalContextFact $fact) => $fact->type === 'current_state'),
            'success_signal' => $confirmed->contains(fn (GoalContextFact $fact) => $fact->type === 'signal'),
            'constraints' => $confirmed->contains(fn (GoalContextFact $fact) => $fact->type === 'constraint'),
            'drivers_or_measurement' => $confirmed->contains(
                fn (GoalContextFact $fact) => $fact->type === 'driver'
                    || ($fact->type === 'signal' && (bool) data_get($fact->metadata, 'measurement', false))
            ),
        ];
    }

    /**
     * @param Collection<int,GoalContextFact> $facts
     */
    private function formatFacts(Collection $facts): string
    {
        return $facts
            ->map(function (GoalContextFact $fact) {
                $value = $fact->value_json;
                $rendered = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $value;

                return '- '.$fact->label.': '.($rendered ?: '未設定')
                    .' [source='.$fact->source.', confidence='.number_format((float) $fact->confidence, 2).']';
            })
            ->implode("\n");
    }

    private function normalizeValue(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return ['value' => $value];
    }
}
