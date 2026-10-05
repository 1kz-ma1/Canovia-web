<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use Illuminate\Support\Collection;

class QuestionBankCoverageService
{
    /**
     * @param array<string, mixed> $strategy
     * @return array{
     *   available:bool,
     *   pack:?QuestionPack,
     *   active_count:int,
     *   focus_match_count:int,
     *   required_count:int,
     *   reason:string
     * }
     */
    public function evaluate(Plan $plan, Task $task, array $strategy): array
    {
        $requiredCount = max(1, min(20, (int) ($strategy['target_question_count'] ?? 10)));
        $focusTopics = collect($strategy['focus_topics'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim($item))
            ->values();

        $context = $this->normalize(implode(' ', [
            (string) $plan->title,
            (string) ($plan->description ?? ''),
            (string) ($plan->category ?? ''),
            (string) $task->title,
            (string) ($task->description ?? ''),
        ]));

        $candidates = QuestionPack::query()
            ->where('status', 'published')
            ->with(['questions' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->map(function (QuestionPack $pack) use ($context, $focusTopics, $requiredCount, $strategy) {
                $packScore = $this->packMatchScore($pack, $context);
                $questions = $pack->questions;
                $focusMatchCount = $focusTopics->isEmpty()
                    ? $questions->count()
                    : $questions->filter(
                        fn (Question $question) => $this->questionFocusScore($question, $focusTopics) > 0
                    )->count();

                $mix = is_array($strategy['question_mix'] ?? null)
                    ? $strategy['question_mix']
                    : [];
                $requestedFocusCount = max(
                    0,
                    (int) ($mix['primary'] ?? 0) + (int) ($mix['secondary'] ?? 0),
                );
                $minimumFocusMatches = $focusTopics->isEmpty()
                    ? 0
                    : min(3, max(1, $requestedFocusCount ?: $requiredCount));

                $available = $packScore > 0
                    && $questions->count() >= $requiredCount
                    && $focusMatchCount >= $minimumFocusMatches;

                return [
                    'pack' => $pack,
                    'pack_score' => $packScore,
                    'selection_priority' => $this->selectionPriority($pack),
                    'active_count' => $questions->count(),
                    'focus_match_count' => $focusMatchCount,
                    'required_count' => $requiredCount,
                    'available' => $available,
                ];
            })
            ->filter(fn (array $item) => $item['pack_score'] > 0)
            ->sort(function (array $left, array $right) {
                return [
                    $right['available'] ? 1 : 0,
                    $right['pack_score'],
                    $right['selection_priority'],
                    $right['focus_match_count'],
                    $right['active_count'],
                    $right['pack']->id,
                ] <=> [
                    $left['available'] ? 1 : 0,
                    $left['pack_score'],
                    $left['selection_priority'],
                    $left['focus_match_count'],
                    $left['active_count'],
                    $left['pack']->id,
                ];
            })
            ->values();

        $best = $candidates->first();

        if (! $best) {
            return [
                'available' => false,
                'pack' => null,
                'active_count' => 0,
                'focus_match_count' => 0,
                'required_count' => $requiredCount,
                'reason' => 'このPlan・Taskに一致する公開Question Packがありません。',
            ];
        }

        $reason = $best['available']
            ? '公開Question Packに今回の演習を構成できる十分な問題があります。'
            : (
                $best['active_count'] < $requiredCount
                    ? "一致するPackはありますが、公開問題が{$requiredCount}問に達していません。"
                    : '一致するPackはありますが、現在の重点分野を十分にカバーしていません。'
            );

        return [
            'available' => (bool) $best['available'],
            'pack' => $best['pack'],
            'active_count' => (int) $best['active_count'],
            'focus_match_count' => (int) $best['focus_match_count'],
            'required_count' => $requiredCount,
            'reason' => $reason,
        ];
    }

    /**
     * @param Collection<int, string> $focusTopics
     */
    public function questionFocusScore(Question $question, Collection $focusTopics): int
    {
        if ($focusTopics->isEmpty()) {
            return 0;
        }

        $metadata = collect($question->learning_metadata ?? []);
        $terms = collect()
            ->merge($metadata->get('concepts', []))
            ->merge($metadata->get('weakness_targets', []))
            ->merge($metadata->get('tags', []))
            ->merge($metadata->get('keywords', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => $this->normalize((string) $item))
            ->unique();

        $score = 0;
        foreach ($focusTopics as $topic) {
            $normalizedTopic = $this->normalize($topic);

            foreach ($terms as $term) {
                if ($term === '' || $normalizedTopic === '') {
                    continue;
                }

                if ($term === $normalizedTopic) {
                    $score += 3;
                    continue;
                }

                // A specific metadata term may refine the focus topic.
                // Example: focus "DNS" can match "DNSレコード".
                if (mb_strlen($normalizedTopic) >= 3 && str_contains($term, $normalizedTopic)) {
                    $score += 2;
                    continue;
                }

                // A sufficiently specific term may be contained in a compound
                // focus such as "MTU計算". Generic two-character labels such
                // as "計算" must not make unrelated questions look like MTU.
                if (mb_strlen($term) >= 3 && str_contains($normalizedTopic, $term)) {
                    $score++;
                }
            }
        }

        return $score;
    }

    private function selectionPriority(QuestionPack $pack): int
    {
        $raw = data_get($pack->metadata, 'selection_priority', 0);
        $priority = filter_var($raw, FILTER_VALIDATE_INT);

        if ($priority === false) {
            return 0;
        }

        return max(-100, min(100, (int) $priority));
    }

    private function packMatchScore(QuestionPack $pack, string $normalizedContext): int
    {
        $metadata = collect($pack->metadata ?? []);
        $identityTerms = collect($metadata->get('match_terms', []))
            ->push($pack->exam_code)
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->unique();

        $score = 0;

        foreach ($identityTerms as $term) {
            $normalizedTerm = $this->normalize($term);
            if ($normalizedTerm === '') {
                continue;
            }

            if (
                preg_match('/^[a-z0-9_-]+$/', $normalizedTerm) === 1
                && mb_strlen($normalizedTerm) <= 6
            ) {
                if (preg_match(
                    '/(?:^|[^a-z0-9])'.preg_quote($normalizedTerm, '/').'(?:$|[^a-z0-9])/u',
                    $normalizedContext
                ) === 1) {
                    $score += 3;
                }

                continue;
            }

            if (str_contains($normalizedContext, $normalizedTerm)) {
                $score += 2;
            }
        }

        // Qualification identity must match first. A generic subject such as
        // "科目A" must never make an AP pack eligible for another exam.
        if ($score === 0) {
            return 0;
        }

        $subject = $this->normalize((string) ($pack->subject ?? ''));
        if ($subject !== '' && str_contains($normalizedContext, $subject)) {
            $score++;
        }

        return $score;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
