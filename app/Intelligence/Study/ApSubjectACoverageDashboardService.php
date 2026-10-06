<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeSession;
use App\Services\StudyPracticeExamProfileService;
use Illuminate\Support\Collection;

final class ApSubjectACoverageDashboardService
{
    private const SESSION_LIMIT = 200;

    private const DOMAIN_ORDER = [
        'テクノロジ',
        'マネジメント',
        'ストラテジ',
    ];

    private const SOURCE_DOMAIN_MAP = [
        'technology' => 'テクノロジ',
        'management' => 'マネジメント',
        'strategy' => 'ストラテジ',
    ];

    public function __construct(
        private readonly StudyPracticeExamProfileService $examProfiles,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function project(
        Plan $plan,
        ?int $userId,
        ?string $actorToken,
    ): array {
        if (! $this->isApSubjectAPlan($plan)) {
            return [
                'available' => false,
                'reason' => 'not_ap_subject_a',
            ];
        }

        $referencePack = $this->referencePack();
        $referenceDomainCounts = $this->referenceDomainCounts(
            $referencePack,
        );
        $referenceQuestionCount = $referencePack
            ? $this->referenceQuestionCount($referencePack)
            : null;

        $sessions = $this->sessions(
            $plan,
            $userId,
            $actorToken,
        );

        $selectedQuestionIds = $sessions
            ->flatMap(
                fn (StudyPracticeSession $session) =>
                    collect(
                        $session->selected_questions ?? [],
                    ),
            )
            ->filter(fn ($item) => is_array($item))
            ->pluck('question_id')
            ->filter(
                fn ($id) =>
                    is_numeric($id)
                    && (int) $id > 0,
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $questions = $selectedQuestionIds->isEmpty()
            ? collect()
            : Question::query()
                ->whereIn('id', $selectedQuestionIds)
                ->get(['id', 'question_pack_id'])
                ->keyBy('id');

        $domains = collect(self::DOMAIN_ORDER)
            ->mapWithKeys(
                fn (string $label) => [
                    $label => $this->emptyDomain(
                        $label,
                        $referenceDomainCounts[$label]
                            ?? null,
                    ),
                ],
            )
            ->all();

        $officialUnique = [];
        $allUnique = [];
        $assessedSessionIds = [];

        foreach ($sessions as $session) {
            $selected = collect(
                $session->selected_questions ?? [],
            )
                ->filter(fn ($item) => is_array($item))
                ->mapWithKeys(function (array $item) {
                    $questionRef = trim(
                        (string) (
                            $item['question_ref']
                            ?? ''
                        ),
                    );
                    $questionId = is_numeric(
                        $item['question_id']
                        ?? null,
                    )
                        ? (int) $item['question_id']
                        : 0;
                    $domain = $this->normalizeDomain(
                        $item['selection_domain']
                            ?? null,
                    );

                    if (
                        $questionRef === ''
                        || $questionId <= 0
                        || $domain === null
                    ) {
                        return [];
                    }

                    return [
                        $questionRef => [
                            'question_id' => $questionId,
                            'domain' => $domain,
                            'parent_topic' =>
                                $this->nullableString(
                                    $item[
                                        'selection_parent_topic'
                                    ]
                                    ?? null,
                                ),
                        ],
                    ];
                });

            if ($selected->isEmpty()) {
                continue;
            }

            foreach ($session->attempts as $attempt) {
                $feedback = collect(data_get(
                    $attempt->assessment,
                    'question_feedback',
                    [],
                ))
                    ->filter(fn ($item) => is_array($item))
                    ->values();

                $seenRefs = [];

                foreach ($feedback as $item) {
                    $questionRef = trim(
                        (string) (
                            $item['question_id']
                            ?? ''
                        ),
                    );

                    if (
                        $questionRef === ''
                        || isset($seenRefs[$questionRef])
                    ) {
                        continue;
                    }

                    $selectedItem = $selected->get(
                        $questionRef,
                    );

                    if (! is_array($selectedItem)) {
                        continue;
                    }

                    $correctness = trim(
                        (string) (
                            $item['correctness']
                            ?? ''
                        ),
                    );

                    if (! in_array(
                        $correctness,
                        [
                            'correct',
                            'partial',
                            'incorrect',
                        ],
                        true,
                    )) {
                        continue;
                    }

                    $seenRefs[$questionRef] = true;
                    $assessedSessionIds[
                        (int) $session->id
                    ] = true;

                    $domain = (string) $selectedItem[
                        'domain'
                    ];
                    $questionId = (int) $selectedItem[
                        'question_id'
                    ];
                    $parentTopic = $selectedItem[
                        'parent_topic'
                    ] ?? null;

                    if (! isset($domains[$domain])) {
                        $domains[$domain] =
                            $this->emptyDomain(
                                $domain,
                                null,
                            );
                    }

                    $domains[$domain][
                        'assessed_exposure_count'
                    ]++;
                    $domains[$domain][
                        $correctness.'_count'
                    ]++;

                    $allUnique[$questionId] = true;
                    $domains[$domain][
                        '_unique_questions'
                    ][$questionId] = true;

                    $assessedAt = $attempt->created_at
                        ?? $session->completed_at
                        ?? $session->updated_at;

                    if (
                        $assessedAt !== null
                        && (
                            $domains[$domain][
                                'latest_assessed_at'
                            ] === null
                            || $assessedAt->gt(
                                $domains[$domain][
                                    'latest_assessed_at'
                                ],
                            )
                        )
                    ) {
                        $domains[$domain][
                            'latest_assessed_at'
                        ] = $assessedAt;
                    }

                    $question = $questions->get(
                        $questionId,
                    );

                    if (
                        $referencePack
                        && $question instanceof Question
                        && (int) $question->question_pack_id
                            === (int) $referencePack->id
                    ) {
                        $officialUnique[
                            $questionId
                        ] = true;
                        $domains[$domain][
                            '_official_unique'
                        ][$questionId] = true;
                    }

                    if ($parentTopic !== null) {
                        $this->addParentObservation(
                            $domains[$domain],
                            $parentTopic,
                            $correctness,
                            $assessedAt,
                        );
                    }
                }
            }
        }

        $domainRows = collect($domains)
            ->map(function (
                array $domain,
                string $label,
            ) {
                $domain[
                    'unique_question_count'
                ] = count(
                    $domain['_unique_questions'],
                );
                $domain[
                    'official_unique_question_count'
                ] = count(
                    $domain['_official_unique'],
                );

                $graded = (int) $domain[
                    'assessed_exposure_count'
                ];
                $correct = (int) $domain[
                    'correct_count'
                ];
                $domain[
                    'observed_correct_rate_percent'
                ] = $graded > 0
                    ? (int) round(
                        ($correct / $graded) * 100,
                    )
                    : null;

                $expected = $domain[
                    'official_expected_count'
                ];
                $domain[
                    'official_coverage_percent'
                ] = is_int($expected)
                    && $expected > 0
                    ? min(
                        100,
                        (int) round(
                            (
                                $domain[
                                    'official_unique_question_count'
                                ]
                                / $expected
                            )
                            * 100,
                        ),
                    )
                    : null;

                $domain['status'] =
                    $this->status(
                        $graded,
                        $domain[
                            'observed_correct_rate_percent'
                        ],
                    );
                $domain['parent_topics'] =
                    $this->parentRows(
                        $domain['_parents'],
                    );

                unset(
                    $domain['_unique_questions'],
                    $domain['_official_unique'],
                    $domain['_parents'],
                );

                return $domain;
            })
            ->sortBy(
                fn (array $domain) =>
                    array_search(
                        $domain['label'],
                        self::DOMAIN_ORDER,
                        true,
                    ) !== false
                        ? array_search(
                            $domain['label'],
                            self::DOMAIN_ORDER,
                            true,
                        )
                        : PHP_INT_MAX,
            )
            ->values();

        $officialCoveragePercent =
            $referenceQuestionCount !== null
            && $referenceQuestionCount > 0
                ? min(
                    100,
                    (int) round(
                        (
                            count($officialUnique)
                            / $referenceQuestionCount
                        )
                        * 100,
                    ),
                )
                : null;

        return [
            'available' => true,
            'version' => 'v1',
            'exam_profile_key' =>
                'ap_subject_a_exam',
            'session_limit' =>
                self::SESSION_LIMIT,
            'sessions_considered' =>
                $sessions->count(),
            'assessed_session_count' =>
                count($assessedSessionIds),
            'assessed_exposure_count' =>
                $domainRows->sum(
                    'assessed_exposure_count',
                ),
            'unique_question_count' =>
                count($allUnique),
            'has_assessed_activity' =>
                count($assessedSessionIds) > 0,
            'reference_pack' =>
                $referencePack
                    ? [
                        'id' => (int) $referencePack->id,
                        'slug' =>
                            (string) $referencePack->slug,
                        'title' =>
                            (string) $referencePack->title,
                        'question_count' =>
                            $referenceQuestionCount,
                    ]
                    : null,
            'official_unique_question_count' =>
                count($officialUnique),
            'official_coverage_percent' =>
                $officialCoveragePercent,
            'domains' => $domainRows->all(),
            'note' =>
                '公式Coverageは収録済み公式Packのユニーク採点問題数、正答観測はPlan内Question Bankの採点exposureです。Task進捗やMasteryとは別指標です。',
        ];
    }

    private function isApSubjectAPlan(
        Plan $plan,
    ): bool {
        $tasks = $plan->relationLoaded('tasks')
            ? $plan->tasks
            : $plan->tasks()->get();

        return $tasks->contains(
            fn ($task) =>
                data_get(
                    $this->examProfiles
                        ->forPlanTask(
                            $plan,
                            $task,
                        ),
                    'key',
                )
                === 'ap_subject_a_exam',
        );
    }

    private function referencePack(): ?QuestionPack
    {
        return QuestionPack::query()
            ->where('status', 'published')
            ->where('exam_code', 'AP')
            ->where('subject', '科目A')
            ->get()
            ->filter(
                fn (QuestionPack $pack) =>
                    data_get(
                        $pack->metadata,
                        'content_kind',
                    )
                    ===
                    'official_past_exam_curated',
            )
            ->sort(function (
                QuestionPack $left,
                QuestionPack $right,
            ) {
                $priority = (int) data_get(
                    $right->metadata,
                    'selection_priority',
                    0,
                ) <=> (int) data_get(
                    $left->metadata,
                    'selection_priority',
                    0,
                );

                if ($priority !== 0) {
                    return $priority;
                }

                return (int) $right->id
                    <=> (int) $left->id;
            })
            ->first();
    }

    /**
     * @return array<string,int>
     */
    private function referenceDomainCounts(
        ?QuestionPack $pack,
    ): array {
        if (! $pack) {
            return [];
        }

        return collect(
            data_get(
                $pack->metadata,
                'source_domain_counts',
                [],
            ),
        )
            ->filter(
                fn ($count, $key) =>
                    is_string($key)
                    && is_numeric($count)
                    && (int) $count >= 0,
            )
            ->mapWithKeys(function (
                $count,
                string $key,
            ) {
                $label =
                    self::SOURCE_DOMAIN_MAP[
                        mb_strtolower(
                            trim($key),
                        )
                    ]
                    ?? null;

                return $label
                    ? [$label => (int) $count]
                    : [];
            })
            ->all();
    }

    private function referenceQuestionCount(
        QuestionPack $pack,
    ): int {
        $metadataCount = data_get(
            $pack->metadata,
            'question_count',
        );

        if (
            is_numeric($metadataCount)
            && (int) $metadataCount > 0
        ) {
            return (int) $metadataCount;
        }

        return $pack
            ->questions()
            ->where('is_active', true)
            ->count();
    }

    private function sessions(
        Plan $plan,
        ?int $userId,
        ?string $actorToken,
    ): Collection {
        $query = StudyPracticeSession::query()
            ->with([
                'attempts' => function ($query) use (
                    $userId,
                    $actorToken,
                ) {
                    if ($userId !== null) {
                        $query->where(
                            'user_id',
                            $userId,
                        );

                        return;
                    }

                    $query
                        ->whereNull('user_id')
                        ->where(
                            'actor_token',
                            (string) $actorToken,
                        );
                },
            ])
            ->where('plan_id', $plan->id)
            ->whereNotNull('selected_questions');

        if ($userId !== null) {
            $query->where(
                'user_id',
                $userId,
            );
        } else {
            $query
                ->whereNull('user_id')
                ->where(
                    'actor_token',
                    (string) $actorToken,
                );
        }

        return $query
            ->whereHas(
                'attempts',
                function ($query) use (
                    $userId,
                    $actorToken,
                ) {
                    if ($userId !== null) {
                        $query->where(
                            'user_id',
                            $userId,
                        );

                        return;
                    }

                    $query
                        ->whereNull('user_id')
                        ->where(
                            'actor_token',
                            (string) $actorToken,
                        );
                },
            )
            ->latest('id')
            ->take(self::SESSION_LIMIT)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDomain(
        string $label,
        ?int $expected,
    ): array {
        return [
            'label' => $label,
            'official_expected_count' =>
                $expected,
            'official_unique_question_count' =>
                0,
            'official_coverage_percent' =>
                null,
            'assessed_exposure_count' => 0,
            'unique_question_count' => 0,
            'correct_count' => 0,
            'partial_count' => 0,
            'incorrect_count' => 0,
            'observed_correct_rate_percent' =>
                null,
            'latest_assessed_at' => null,
            'status' => 'unobserved',
            'parent_topics' => [],
            '_unique_questions' => [],
            '_official_unique' => [],
            '_parents' => [],
        ];
    }

    private function normalizeDomain(
        mixed $value,
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $lower = mb_strtolower($value);

        if (isset(self::SOURCE_DOMAIN_MAP[$lower])) {
            return self::SOURCE_DOMAIN_MAP[$lower];
        }

        if (in_array(
            $value,
            self::DOMAIN_ORDER,
            true,
        )) {
            return $value;
        }

        return $value;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== ''
            ? mb_substr($value, 0, 120)
            : null;
    }

    private function status(
        int $graded,
        ?int $correctRate,
    ): string {
        if ($graded <= 0) {
            return 'unobserved';
        }

        if ($graded < 3) {
            return 'observing';
        }

        if ($correctRate !== null && $correctRate < 60) {
            return 'needs_attention';
        }

        if ($correctRate !== null && $correctRate < 80) {
            return 'developing';
        }

        return 'stable';
    }

    private function addParentObservation(
        array &$domain,
        string $parentTopic,
        string $correctness,
        mixed $assessedAt,
    ): void {
        $parentTopic = mb_substr(
            trim($parentTopic),
            0,
            120,
        );

        if ($parentTopic === '') {
            return;
        }

        $domain['_parents'][$parentTopic]
            ??= [
                'label' => $parentTopic,
                'assessed_exposure_count' => 0,
                'correct_count' => 0,
                'partial_count' => 0,
                'incorrect_count' => 0,
                'latest_assessed_at' => null,
            ];

        $domain['_parents'][$parentTopic][
            'assessed_exposure_count'
        ]++;
        $domain['_parents'][$parentTopic][
            $correctness.'_count'
        ]++;

        if (
            $assessedAt !== null
            && (
                $domain['_parents'][$parentTopic][
                    'latest_assessed_at'
                ] === null
                || $assessedAt->gt(
                    $domain['_parents'][$parentTopic][
                        'latest_assessed_at'
                    ],
                )
            )
        ) {
            $domain['_parents'][$parentTopic][
                'latest_assessed_at'
            ] = $assessedAt;
        }
    }

    /**
     * @param array<string,array<string,mixed>> $parents
     * @return array<int,array<string,mixed>>
     */
    private function parentRows(
        array $parents,
    ): array {
        return collect($parents)
            ->map(function (array $row) {
                $graded = (int) $row[
                    'assessed_exposure_count'
                ];
                $rate = $graded > 0
                    ? (int) round(
                        (
                            (int) $row['correct_count']
                            / $graded
                        )
                        * 100,
                    )
                    : null;

                return [
                    ...$row,
                    'observed_correct_rate_percent' =>
                        $rate,
                    'status' =>
                        $graded < 2
                            ? 'low_confidence'
                            : $this->status(
                                $graded,
                                $rate,
                            ),
                ];
            })
            ->sort(function (
                array $left,
                array $right,
            ) {
                $leftEstablished =
                    (int) $left[
                        'assessed_exposure_count'
                    ] >= 2;
                $rightEstablished =
                    (int) $right[
                        'assessed_exposure_count'
                    ] >= 2;

                $leftRate =
                    $left[
                        'observed_correct_rate_percent'
                    ] ?? 101;
                $rightRate =
                    $right[
                        'observed_correct_rate_percent'
                    ] ?? 101;

                return [
                    $leftEstablished ? 0 : 1,
                    $leftEstablished
                        ? (int) $leftRate
                        : 101,
                    -1 * (int) $left[
                        'assessed_exposure_count'
                    ],
                    (string) $left['label'],
                ] <=> [
                    $rightEstablished ? 0 : 1,
                    $rightEstablished
                        ? (int) $rightRate
                        : 101,
                    -1 * (int) $right[
                        'assessed_exposure_count'
                    ],
                    (string) $right['label'],
                ];
            })
            ->take(5)
            ->values()
            ->all();
    }
}
