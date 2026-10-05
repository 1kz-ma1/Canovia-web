<?php

namespace App\Services;

use App\Contracts\StudyPracticeQuestionProvider;
use App\Models\Plan;
use App\Models\Question;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Collection;
use RuntimeException;

class QuestionBankStudyPracticeQuestionProvider implements StudyPracticeQuestionProvider
{
    public function __construct(
        private readonly QuestionBankCoverageService $coverageService,
        private readonly StudyTopicTaxonomyService $taxonomy,
    ) {}

    public function key(): string
    {
        return 'question_bank';
    }

    public function mode(): string
    {
        return 'direct';
    }

    public function prepare(Plan $plan, Task $task, Collection $recentAttempts, array $strategy, ?int $actorUserId = null): array
    {
        return $this->prepareSelection(
            $plan,
            $task,
            $recentAttempts,
            $strategy,
            $actorUserId,
            false,
        );
    }

    /**
     * Select only the questions the current Question Bank can confidently
     * supply. Missing seats are intentionally left open for Hybrid assembly.
     */
    public function preparePartial(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $strategy,
        ?int $actorUserId = null,
    ): array {
        return $this->prepareSelection(
            $plan,
            $task,
            $recentAttempts,
            $strategy,
            $actorUserId,
            true,
        );
    }

    private function prepareSelection(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $strategy,
        ?int $actorUserId,
        bool $allowPartial,
    ): array {

        $coverage = $this->coverageService->evaluate($plan, $task, $strategy);
        $pack = $coverage['pack'];

        if (! $pack) {
            if ($allowPartial) {
                return $this->emptySelection($coverage, $strategy);
            }

            throw new RuntimeException('Question BankのCoverageが不足しています。');
        }

        if (! $allowPartial && ! $coverage['available']) {
            throw new RuntimeException('Question BankのCoverageが不足しています。');
        }

        $targetCount = (int) $coverage['required_count'];
        $weakness = is_array($strategy['weakness_priority'] ?? null)
            ? $strategy['weakness_priority']
            : [];

        $primaryTopics = collect($weakness['primary_topics'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->values();
        $secondaryTopics = collect($weakness['secondary_topics'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->values();

        // Backward compatibility for old strategies that only expose focus_topics.
        if ($primaryTopics->isEmpty() && $secondaryTopics->isEmpty()) {
            $secondaryTopics = collect($strategy['focus_topics'] ?? [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->values();
        }

        $mix = is_array($strategy['question_mix'] ?? null)
            ? $strategy['question_mix']
            : [];
        $primaryTarget = max(0, min($targetCount, (int) ($mix['primary'] ?? 0)));
        $secondaryTarget = max(0, min($targetCount, (int) ($mix['secondary'] ?? 0)));
        $diagnosticTarget = max(0, min($targetCount, (int) ($mix['diagnostic'] ?? $targetCount)));

        $exposureContext = $this->questionExposureContext($plan);
        $routingPolicy = is_array($strategy['routing_policy'] ?? null)
            ? $strategy['routing_policy']
            : [];
        $broadRouting = (bool) ($routingPolicy['broad_parent_control'] ?? false)
            && ($strategy['key'] ?? '') !== 'weakness_reinforcement';
        $parentStates = collect($routingPolicy['parent_topics'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->mapWithKeys(fn (array $item) => [
                $this->taxonomy->key((string) ($item['parent_topic'] ?? ''))
                    => $item,
            ]);
        $cooldownTopics = collect($routingPolicy['cooldown_topics'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->values();

        $preferHarder = ($strategy['key'] ?? '') === 'retention_and_transfer';

        $candidates = $pack->questions
            ->where('is_active', true)
            ->map(function (Question $question) use (
                $primaryTopics,
                $secondaryTopics,
                $exposureContext,
                $parentStates,
                $cooldownTopics,
            ) {
                $exposure = $exposureContext['questions'][(int) $question->id] ?? [];
                $parentTopic = $this->taxonomy->parentForQuestion($question)
                    ?? $this->domainKey($question);
                $parentKey = $this->taxonomy->key($parentTopic);
                $parentState = is_array($parentStates->get($parentKey))
                    ? $parentStates->get($parentKey)
                    : [];
                $parentCap = array_key_exists('broad_question_cap', $parentState)
                    ? max(0, (int) $parentState['broad_question_cap'])
                    : max(
                        1,
                        (int) config(
                            'study.practice_routing.parent_exposure.broad_max_questions',
                            2,
                        ),
                    );

                return [
                    'question' => $question,
                    'primary_score' => $this->coverageService->questionFocusScore($question, $primaryTopics),
                    'secondary_score' => $this->coverageService->questionFocusScore($question, $secondaryTopics),
                    'primary_topic' => $this->bestMatchingTopic($question, $primaryTopics),
                    'secondary_topic' => $this->bestMatchingTopic($question, $secondaryTopics),
                    'domain_key' => $this->domainKey($question),
                    'parent_topic' => $parentTopic,
                    'parent_key' => $parentKey !== '' ? $parentKey : 'other',
                    'parent_recent_exposure' => (float) ($parentState['recent_exposure'] ?? 0.0),
                    'parent_confidence' => (float) ($parentState['mastery_confidence'] ?? 0.5),
                    'parent_suppressed' => (bool) ($parentState['suppressed'] ?? false),
                    'parent_preferred' => (bool) ($parentState['preferred'] ?? false),
                    'parent_cap' => $parentCap,
                    'cooldown_match' => $cooldownTopics->isNotEmpty()
                        && $this->coverageService->questionFocusScore(
                            $question,
                            $cooldownTopics,
                        ) > 0,
                    'exposure_count' => (int) ($exposure['exposure_count'] ?? 0),
                    'last_seen_session_offset' => array_key_exists('last_seen_session_offset', $exposure)
                        ? $exposure['last_seen_session_offset']
                        : null,
                    'recent' => (bool) ($exposure['recent'] ?? false),
                ];
            })
            ->values();

        $selected = collect();
        $selectedIds = collect();

        $primary = $this->selectBucket(
            $candidates->filter(fn (array $item) => $item['primary_score'] > 0),
            $primaryTarget,
            'primary_topic',
            'primary_score',
            $preferHarder,
            $selectedIds,
            $broadRouting,
            $selected,
        );
        $this->appendSelection($selected, $selectedIds, $primary, 'primary');

        $secondary = $this->selectBucket(
            $candidates->filter(fn (array $item) => $item['secondary_score'] > 0),
            $secondaryTarget,
            'secondary_topic',
            'secondary_score',
            $preferHarder,
            $selectedIds,
            $broadRouting,
            $selected,
        );
        $this->appendSelection($selected, $selectedIds, $secondary, 'secondary');

        // Diagnostic questions deliberately prefer topics outside the active
        // weakness set so another weakness can still be discovered.
        $diagnosticPool = $candidates->filter(
            fn (array $item) => $item['primary_score'] === 0 && $item['secondary_score'] === 0
        );
        $diagnostic = $this->selectBucket(
            $diagnosticPool,
            $diagnosticTarget,
            $broadRouting ? 'parent_key' : 'domain_key',
            null,
            $preferHarder,
            $selectedIds,
            $broadRouting,
            $selected,
        );
        $this->appendSelection($selected, $selectedIds, $diagnostic, 'diagnostic');

        // Bank-only mode keeps the historical behavior and fills the remaining
        // seats from the pack. Hybrid mode deliberately leaves uncovered seats
        // open so Native AI can generate only the missing practice demand.
        if (! $allowPartial) {
            $remaining = max(0, $targetCount - $selected->count());
            if ($remaining > 0) {
                $fallback = $this->selectBucket(
                    $candidates,
                    $remaining,
                    $broadRouting ? 'parent_key' : 'domain_key',
                    null,
                    $preferHarder,
                    $selectedIds,
                    $broadRouting,
                    $selected,
                );
                $this->appendSelection($selected, $selectedIds, $fallback, 'balanced_fill');
            }

            // Bank-only mode may relax parent/cooldown caps only as a final
            // fill. Hybrid mode deliberately leaves capped seats open.
            $remaining = max(0, $targetCount - $selected->count());
            if ($remaining > 0 && $broadRouting) {
                $relaxed = $this->selectBucket(
                    $candidates,
                    $remaining,
                    'parent_key',
                    null,
                    $preferHarder,
                    $selectedIds,
                    false,
                    $selected,
                );
                $this->appendSelection(
                    $selected,
                    $selectedIds,
                    $relaxed,
                    'balanced_fill_relaxed',
                );
            }

            if ($selected->count() < $targetCount) {
                throw new RuntimeException('Question Bankから必要数の問題を選定できませんでした。');
            }
        }

        $selected = $selected->take($targetCount)->values();
        $questions = $selected->pluck('question')->values();
        $rendered = $questions->map(fn (Question $question) => $this->renderQuestion($question))->all();

        $actualMix = $selected
            ->countBy('bucket')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'provider' => $this->key(),
            'mode' => $this->mode(),
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'payload' => [
                'title' => $pack->title.' / '.($strategy['label'] ?? '演習'),
                'questions' => $rendered,
                'pack' => [
                    'id' => $pack->id,
                    'slug' => $pack->slug,
                    'title' => $pack->title,
                    'version' => $pack->version,
                    'exam_code' => $pack->exam_code,
                    'subject' => $pack->subject,
                ],
                'coverage' => [
                    'active_count' => $coverage['active_count'],
                    'focus_match_count' => $coverage['focus_match_count'],
                    'required_count' => $coverage['required_count'],
                ],
                'selection_mix' => [
                    'requested' => [
                        'primary' => $primaryTarget,
                        'secondary' => $secondaryTarget,
                        'diagnostic' => $diagnosticTarget,
                    ],
                    'actual' => $actualMix,
                ],
                'selection_rotation' => [
                    'history_sessions_considered' => $exposureContext['session_count'],
                    'history_session_limit' => $exposureContext['history_limit'],
                    'recent_session_window' => $exposureContext['recent_window'],
                ],
                'routing_control' => [
                    'broad_parent_control' => $broadRouting,
                    'cooldown_topics' => $cooldownTopics->values()->all(),
                    'suppressed_parent_topics' =>
                        $routingPolicy['suppressed_parent_topics'] ?? [],
                    'preferred_parent_topics' =>
                        $routingPolicy['preferred_parent_topics'] ?? [],
                ],
            ],
            'questions' => $rendered,
            'selected_questions' => $selected->map(fn (array $item) => [
                'question_ref' => 'bank_'.$item['question']->id,
                'question_id' => $item['question']->id,
                'source_type' => $item['question']->source_type,
                'source_reference' => $item['question']->source_reference,
                'selection_bucket' => $item['bucket'],
                'selection_domain' => $item['domain_key'],
                'selection_parent_topic' => $item['parent_topic'] ?? null,
                'selection_parent_recent_exposure' => round(
                    (float) ($item['parent_recent_exposure'] ?? 0.0),
                    3,
                ),
                'selection_parent_cap' => (int) ($item['parent_cap'] ?? 0),
                'selection_cooldown_match' => (bool) ($item['cooldown_match'] ?? false),
                'selection_exposure_count' => (int) ($item['exposure_count'] ?? 0),
                'selection_last_seen_session_offset' => $item['last_seen_session_offset'] ?? null,
                'selection_recent' => (bool) ($item['recent'] ?? false),
            ])->all(),
        ];
    }

    /**
     * @param array<string,mixed> $coverage
     * @param array<string,mixed> $strategy
     * @return array<string,mixed>
     */
    private function emptySelection(array $coverage, array $strategy): array
    {
        return [
            'provider' => $this->key(),
            'mode' => $this->mode(),
            'selector_type' => 'question_bank_partial',
            'selector_version' => 'bank-v4-routing',
            'payload' => [
                'title' => 'Canovia Question Bank / '.($strategy['label'] ?? '演習'),
                'questions' => [],
                'pack' => null,
                'coverage' => [
                    'active_count' => (int) ($coverage['active_count'] ?? 0),
                    'focus_match_count' => (int) ($coverage['focus_match_count'] ?? 0),
                    'required_count' => (int) ($coverage['required_count'] ?? 0),
                    'reason' => (string) ($coverage['reason'] ?? ''),
                ],
                'selection_mix' => [
                    'requested' => (array) ($strategy['question_mix'] ?? []),
                    'actual' => [],
                ],
            ],
            'questions' => [],
            'selected_questions' => [],
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $candidates
     * @param Collection<int,int> $selectedIds
     * @return Collection<int,array<string,mixed>>
     */
    private function selectBucket(
        Collection $candidates,
        int $count,
        string $groupKey,
        ?string $scoreKey,
        bool $preferHarder,
        Collection $selectedIds,
        bool $enforceParentCaps = false,
        ?Collection $alreadySelected = null,
    ): Collection {
        if ($count <= 0) {
            return collect();
        }

        $parentCounts = collect($alreadySelected ?? [])
            ->filter(fn ($item) => is_array($item))
            ->countBy(fn (array $item) => (string) ($item['parent_key'] ?? 'other'));

        $available = $candidates
            ->reject(fn (array $item) => $selectedIds->contains((int) $item['question']->id))
            ->groupBy(fn (array $item) => (string) ($item[$groupKey] ?: 'other'))
            ->map(function (Collection $group) use (
                $scoreKey,
                $preferHarder,
                $enforceParentCaps,
            ) {
                return $group
                    ->sort(function (array $left, array $right) use (
                        $scoreKey,
                        $preferHarder,
                        $enforceParentCaps,
                    ) {
                        $leftScore = $scoreKey ? (int) ($left[$scoreKey] ?? 0) : 0;
                        $rightScore = $scoreKey ? (int) ($right[$scoreKey] ?? 0) : 0;
                        $leftLastSeen = $left['last_seen_session_offset'] ?? null;
                        $rightLastSeen = $right['last_seen_session_offset'] ?? null;

                        $leftRank = [
                            $enforceParentCaps && ($left['cooldown_match'] ?? false) ? 1 : 0,
                            $enforceParentCaps && ($left['parent_suppressed'] ?? false) ? 1 : 0,
                            $enforceParentCaps && ($left['parent_preferred'] ?? false) ? 0 : 1,
                            $enforceParentCaps
                                ? (float) ($left['parent_recent_exposure'] ?? 0.0)
                                : 0.0,
                            $left['recent'] ? 1 : 0,
                            (int) ($left['exposure_count'] ?? 0),
                            $leftLastSeen === null ? PHP_INT_MIN : -1 * (int) $leftLastSeen,
                            -1 * $leftScore,
                            $preferHarder
                                ? -1 * (int) $left['question']->difficulty
                                : abs(3 - (int) $left['question']->difficulty),
                            (int) $left['question']->sort_order,
                            (int) $left['question']->id,
                        ];
                        $rightRank = [
                            $enforceParentCaps && ($right['cooldown_match'] ?? false) ? 1 : 0,
                            $enforceParentCaps && ($right['parent_suppressed'] ?? false) ? 1 : 0,
                            $enforceParentCaps && ($right['parent_preferred'] ?? false) ? 0 : 1,
                            $enforceParentCaps
                                ? (float) ($right['parent_recent_exposure'] ?? 0.0)
                                : 0.0,
                            $right['recent'] ? 1 : 0,
                            (int) ($right['exposure_count'] ?? 0),
                            $rightLastSeen === null ? PHP_INT_MIN : -1 * (int) $rightLastSeen,
                            -1 * $rightScore,
                            $preferHarder
                                ? -1 * (int) $right['question']->difficulty
                                : abs(3 - (int) $right['question']->difficulty),
                            (int) $right['question']->sort_order,
                            (int) $right['question']->id,
                        ];

                        return $leftRank <=> $rightRank;
                    })
                    ->values();
            });

        $available = $enforceParentCaps
            ? $available->sort(function (Collection $left, Collection $right) {
                $leftItem = $left->first() ?? [];
                $rightItem = $right->first() ?? [];

                return [
                    (bool) ($leftItem['parent_suppressed'] ?? false) ? 1 : 0,
                    (bool) ($leftItem['parent_preferred'] ?? false) ? 0 : 1,
                    (float) ($leftItem['parent_recent_exposure'] ?? 0.0),
                    -1 * $left->count(),
                ] <=> [
                    (bool) ($rightItem['parent_suppressed'] ?? false) ? 1 : 0,
                    (bool) ($rightItem['parent_preferred'] ?? false) ? 0 : 1,
                    (float) ($rightItem['parent_recent_exposure'] ?? 0.0),
                    -1 * $right->count(),
                ];
            })
            : $available->sortByDesc(fn (Collection $group) => $group->count());

        $picked = collect();

        while ($picked->count() < $count && $available->isNotEmpty()) {
            $madeProgress = false;

            foreach ($available as $key => $group) {
                if ($picked->count() >= $count) {
                    break;
                }

                $next = null;
                while ($group->isNotEmpty()) {
                    $candidate = $group->shift();
                    if (! is_array($candidate)) {
                        continue;
                    }

                    if ($enforceParentCaps) {
                        if ((bool) ($candidate['cooldown_match'] ?? false)) {
                            continue;
                        }

                        $parentKey = (string) ($candidate['parent_key'] ?? 'other');
                        $parentCap = max(0, (int) ($candidate['parent_cap'] ?? 0));
                        $currentCount = (int) ($parentCounts->get($parentKey, 0));

                        if ($parentCap <= 0 || $currentCount >= $parentCap) {
                            continue;
                        }
                    }

                    $next = $candidate;
                    break;
                }

                if (! $next) {
                    $available->forget($key);
                    continue;
                }

                $picked->push($next);

                if ($enforceParentCaps) {
                    $parentKey = (string) ($next['parent_key'] ?? 'other');
                    $parentCounts->put(
                        $parentKey,
                        (int) ($parentCounts->get($parentKey, 0)) + 1,
                    );
                }

                $available->put($key, $group);
                $madeProgress = true;
            }

            if (! $madeProgress) {
                break;
            }
        }

        return $picked->values();
    }

    /**
     * @return array{
     *   questions:array<int,array{exposure_count:int,last_seen_session_offset:?int,recent:bool}>,
     *   session_count:int,
     *   history_limit:int,
     *   recent_window:int
     * }
     */
    private function questionExposureContext(Plan $plan): array
    {
        $historyLimit = max(
            1,
            min(100, (int) config('study.question_bank_selection.exposure_history_session_limit', 24)),
        );
        $recentWindow = max(
            1,
            min(
                $historyLimit,
                (int) config('study.question_bank_selection.recent_session_window', 3),
            ),
        );

        $sessions = StudyPracticeSession::query()
            ->where('plan_id', $plan->id)
            ->whereNotNull('selected_questions')
            ->latest('created_at')
            ->latest('id')
            ->take($historyLimit)
            ->get(['id', 'selected_questions']);

        $questions = [];

        foreach ($sessions->values() as $sessionOffset => $session) {
            $questionIds = collect($session->selected_questions ?? [])
                ->pluck('question_id')
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id) => (int) $id)
                ->unique();

            foreach ($questionIds as $questionId) {
                if (! isset($questions[$questionId])) {
                    $questions[$questionId] = [
                        'exposure_count' => 0,
                        'last_seen_session_offset' => null,
                        'recent' => false,
                    ];
                }

                $questions[$questionId]['exposure_count']++;

                if ($questions[$questionId]['last_seen_session_offset'] === null) {
                    $questions[$questionId]['last_seen_session_offset'] = (int) $sessionOffset;
                }

                if ($sessionOffset < $recentWindow) {
                    $questions[$questionId]['recent'] = true;
                }
            }
        }

        return [
            'questions' => $questions,
            'session_count' => $sessions->count(),
            'history_limit' => $historyLimit,
            'recent_window' => $recentWindow,
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $selected
     * @param Collection<int,int> $selectedIds
     * @param Collection<int,array<string,mixed>> $items
     */
    private function appendSelection(
        Collection $selected,
        Collection $selectedIds,
        Collection $items,
        string $bucket,
    ): void {
        foreach ($items as $item) {
            $id = (int) $item['question']->id;
            if ($selectedIds->contains($id)) {
                continue;
            }

            $item['bucket'] = $bucket;
            $selected->push($item);
            $selectedIds->push($id);
        }
    }

    /**
     * @param Collection<int,string> $topics
     */
    private function bestMatchingTopic(Question $question, Collection $topics): string
    {
        $bestTopic = '';
        $bestScore = 0;

        foreach ($topics as $topic) {
            $score = $this->coverageService->questionFocusScore($question, collect([$topic]));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTopic = $topic;
            }
        }

        return $bestTopic;
    }

    private function domainKey(Question $question): string
    {
        $metadata = collect($question->learning_metadata ?? []);

        $ignored = ['科目a', '計算', '科目a計算'];
        $candidate = collect($metadata->get('tags', []))
            ->merge($metadata->get('concepts', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->first(function (string $item) use ($ignored) {
                return ! in_array(mb_strtolower($item), $ignored, true);
            });

        return $candidate ?: 'other';
    }

    /**
     * @return array<string, mixed>
     */
    private function renderQuestion(Question $question): array
    {
        $fields = collect($question->response_schema ?? [])
            ->filter(fn ($field) => is_array($field))
            ->values()
            ->all();

        $first = $fields[0] ?? ['type' => 'textarea', 'choices' => []];
        $legacyType = match ((string) ($first['type'] ?? 'textarea')) {
            'short_text', 'textarea' => 'text',
            default => (string) ($first['type'] ?? 'text'),
        };

        return [
            'id' => 'bank_'.$question->id,
            'source_question_id' => $question->id,
            'source_type' => $question->source_type,
            'source_reference' => $question->source_reference,
            'topic' => $this->taxonomy->subtopicForQuestion($question),
            'parent_topic' => $this->taxonomy->parentForQuestion($question),
            'prompt' => $question->prompt,
            'response_fields' => $fields,
            'type' => $legacyType,
            'choices' => $first['choices'] ?? [],
        ];
    }
}
