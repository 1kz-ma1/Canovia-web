<?php

namespace App\Services;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only, actor-scoped evidence overview for Understanding/Practice.
 * Recorded grades are facts; repeated misses are only review suggestions.
 */
final class AdaptiveLearningHistoryService
{
    private const WINDOW = 60;
    private const RECENT = 15;

    public function forPlanTask(Request $request, Plan $plan, Task $task, string $actorToken): array
    {
        $events = LearningAnswerEvent::query()
            ->whereHas('item.run', function ($query) use ($request, $plan, $task, $actorToken): void {
                $query->where('plan_id', $plan->id)
                    ->where('task_id', $task->id)
                    ->whereIn('mode', [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE]);
                if ($request->user()) {
                    $query->where('user_id', $request->user()->id);
                } else {
                    $query->whereNull('user_id')->where('actor_token', $actorToken);
                }
            })
            ->with(['item.run', 'item.evaluationAdjustment'])
            ->orderByDesc('id')->limit(self::WINDOW)->get();

        $eligible = $events->filter(fn (LearningAnswerEvent $answer) =>
            $answer->item && ! $answer->evaluationAdjustment)->values();

        $topics = [];
        foreach ($eligible as $event) {
            $item = $event->item;
            $metadata = data_get($item->question_snapshot, 'learning_metadata', []);
            if (! is_array($metadata)) continue;
            $names = collect($metadata['concepts'] ?? [])
                ->merge(is_array($metadata['weakness_targets'] ?? null) ? $metadata['weakness_targets'] : [])
                ->filter(fn ($name) => is_string($name) && trim($name) !== ''
                    && mb_strlen(trim($name)) <= 50)
                ->map(fn ($name) => trim($name))
                ->unique(fn ($name) => mb_strtolower($name))
                ->take(3);
            foreach ($names as $name) {
                $key = mb_strtolower($name);
                $topics[$key] ??= [
                    'name' => $name, 'answered' => 0, 'incorrect' => 0,
                    'missed_question_ids' => [], 'recent_correct' => [],
                ];
                $topic = &$topics[$key];
                $topic['answered']++;
                if (! $event->was_correct) {
                    $topic['incorrect']++;
                    if ($item->question_id) {
                        $topic['missed_question_ids'][(int) $item->question_id] = true;
                    }
                }
                if (count($topic['recent_correct']) < 2) {
                    $topic['recent_correct'][] = (bool) $event->was_correct;
                }
                unset($topic);
            }
        }

        $review = collect($topics)
            ->filter(fn (array $t) => $t['incorrect'] >= 2
                && count($t['missed_question_ids']) >= 2
                && $t['recent_correct'] !== [true, true])
            ->sort(fn (array $a, array $b) => ($b['incorrect'] <=> $a['incorrect'])
                ?: ($b['answered'] <=> $a['answered'])
                ?: strcmp($a['name'], $b['name']))
            ->take(5)
            ->map(fn (array $t) => [
                'name' => $t['name'],
                'answered' => $t['answered'],
                'incorrect' => $t['incorrect'],
            ])->values();

        $recent = $events->take(self::RECENT)->map(function (LearningAnswerEvent $event): array {
            $item = $event->item;
            $run = $item?->run;
            $payload = $event->answer_payload ?? [];
            $value = $payload['value'] ?? $event->answer_value;
            $displayValue = is_array($value)
                ? implode(' / ', array_filter($value, 'is_scalar'))
                : (string) $value;
            $metadata = data_get($item?->question_snapshot, 'learning_metadata', []);
            $concepts = is_array($metadata) && is_array($metadata['concepts'] ?? null)
                ? collect($metadata['concepts'])->filter(fn ($x) => is_string($x))->take(2)->values()->all()
                : [];
            return [
                'pack_title' => (string) ($run?->pack_title_snapshot ?? '問題集'),
                'mode' => $run?->mode,
                'ordinal' => (int) ($item?->ordinal ?? 0),
                'prompt' => (string) data_get($item?->question_snapshot, 'prompt', ''),
                'answered_at' => $event->answered_at,
                'was_correct' => (bool) $event->was_correct,
                'excluded' => (bool) $event->evaluationAdjustment,
                'answer' => $displayValue,
                'reasoning' => is_string($payload['reasoning'] ?? null)
                    ? $payload['reasoning'] : '',
                'explanation' => (string) ($item?->explanation_snapshot ?? ''),
                'concepts' => $concepts,
            ];
        })->values();

        return [
            'recent' => $recent,
            'review_topics' => $review,
            'saved_count' => $events->count(),
            'correct_count' => $events->where('was_correct', true)->count(),
            'excluded_count' => $events->count() - $eligible->count(),
            'window' => self::WINDOW,
        ];
    }
}
