<?php

namespace App\Intelligence\Study;

use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyRecallItem;
use App\Models\Task;
use App\Services\StudyActivityPolicyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StudyMethodRecommendationService
{
    public const SCOPE_ORGANIZATION = 'scope_organization';
    public const PRACTICAL_EVIDENCE = 'practical_evidence';

    public function __construct(
        private readonly StudyActivityPolicyService $activities,
    ) {}

    /**
     * @param array<string,mixed> $learningType
     * @param array<string,mixed> $state
     * @param array<string,mixed>|null $practiceRecommendation
     * @return array<string,mixed>
     */
    public function recommend(
        Plan $plan,
        Task $task,
        array $learningType,
        array $state,
        ?array $practiceRecommendation,
        ?int $userId,
        ?string $actorToken,
        ?string $adaptiveRouteKind = null,
    ): array {
        $activity = $this->activities->forPlanTask($plan, $task);
        $type = (string) (
            $learningType['key']
            ?? 'general_learning'
        );
        $methods = $this->baseMethods(
            $plan,
            $task,
            $activity,
            $type,
            $adaptiveRouteKind,
            (bool) ($state['has_confirmed_scope'] ?? false),
        );

        $knowledgeGap = $this->repeatedKnowledgeGap(
            $plan,
            $task,
            $userId,
            $actorToken,
        );
        $recallStats = $this->recallStats($plan, $task);
        $resourceCount = PlanResource::query()
            ->where('plan_id', $plan->id)
            ->count();

        $phase = (string) (
            $practiceRecommendation['phase']
            ?? ''
        );
        $resume = (bool) (
            $practiceRecommendation['resume']
            ?? false
        );
        $retentionDue = $this->strings(
            $practiceRecommendation['retention_due_topics']
            ?? [],
        );
        $cooldown = $this->strings(
            $practiceRecommendation['cooldown_topics']
            ?? [],
        );
        $mastered = $this->strings(
            $practiceRecommendation['mastered_topics']
            ?? [],
        );

        [$primaryKey, $variant, $reason, $rule] =
            $this->selectPrimary(
                type: $type,
                state: $state,
                phase: $phase,
                resume: $resume,
                adaptiveRouteKind: $adaptiveRouteKind,
                knowledgeGap: $knowledgeGap,
                retentionDue: $retentionDue,
                basePrimary: (string) data_get(
                    $activity,
                    'primary.key',
                    StudyActivityPolicyService::QUESTION_PRACTICE,
                ),
                cooldown: $cooldown,
                mastered: $mastered,
            );

        $methods = $this->applyStateScores(
            $methods,
            $primaryKey,
            $knowledgeGap,
            $retentionDue,
            $phase,
            $type,
            $state,
        );

        $ranked = collect($methods)
            ->sortByDesc(fn (array $method) =>
                (int) ($method['fit_score'] ?? 0)
            )
            ->values();

        $primary = $ranked->firstWhere(
            'key',
            $primaryKey,
        ) ?? $ranked->first();

        $primary = [
            ...$primary,
            'variant' => $variant,
            'reason' => $reason,
            'rule' => $rule,
            'url' => $this->url(
                $primaryKey,
                $plan,
                $task,
            ),
            'action_label' => $this->actionLabel(
                $primaryKey,
                $variant,
                $resourceCount,
                $recallStats,
                $practiceRecommendation,
            ),
        ];

        $alternatives = $ranked
            ->reject(fn (array $method) =>
                $method['key'] === $primaryKey
            )
            ->take(4)
            ->map(fn (array $method) => [
                ...$method,
                'url' => $this->url(
                    (string) $method['key'],
                    $plan,
                    $task,
                ),
                'action_label' => $this->actionLabel(
                    (string) $method['key'],
                    null,
                    $resourceCount,
                    $recallStats,
                    $practiceRecommendation,
                ),
            ])
            ->values()
            ->all();

        return [
            'version' => 'v1',
            'primary' => $primary,
            'alternatives' => $alternatives,
            'signals' => [
                'learning_type' => $type,
                'adaptive_route_kind' => $adaptiveRouteKind,
                'practice_phase' => $phase,
                'practice_resume' => $resume,
                'knowledge_gap_attempt_count' =>
                    $knowledgeGap['attempt_count'],
                'knowledge_gap_topics' =>
                    $knowledgeGap['topics'],
                'retention_due_topics' => $retentionDue,
                'cooldown_topics' => $cooldown,
                'mastered_topics' => $mastered,
                'recall_total' => $recallStats['total'],
                'recall_due' => $recallStats['due'],
                'resource_count' => $resourceCount,
                'base_activity' => data_get(
                    $activity,
                    'primary.key',
                ),
            ],
            'practice_recommendation' =>
                $primaryKey
                    === StudyActivityPolicyService::QUESTION_PRACTICE
                    ? $practiceRecommendation
                    : null,
        ];
    }

    /**
     * @param array<string,mixed> $activity
     * @return array<int,array<string,mixed>>
     */
    private function baseMethods(
        Plan $plan,
        Task $task,
        array $activity,
        string $type,
        ?string $adaptiveRouteKind,
        bool $hasConfirmedScope,
    ): array {
        $existing = collect(
            $activity['all'] ?? [],
        )
            ->filter(fn ($method) => is_array($method))
            ->map(fn (array $method) => [
                'key' => (string) ($method['key'] ?? ''),
                'label' => (string) (
                    $method['label']
                    ?? 'Study Method'
                ),
                'short_label' => (string) (
                    $method['short_label']
                    ?? $method['label']
                    ?? '学習方法'
                ),
                'icon' => (string) (
                    $method['icon']
                    ?? '◉'
                ),
                'description' => (string) (
                    $method['description']
                    ?? ''
                ),
                'fit_score' => max(
                    0,
                    min(
                        100,
                        (int) (
                            $method['fit_score']
                            ?? 0
                        ),
                    ),
                ),
                'source' => 'task_semantic_fit',
            ])
            ->filter(fn (array $method) =>
                $method['key'] !== ''
            )
            ->values();

        if (
            $type === 'school_test'
            || (
                $adaptiveRouteKind === 'study_scope'
                && $hasConfirmedScope
            )
        ) {
            $existing->push([
                'key' => self::SCOPE_ORGANIZATION,
                'label' => 'Scope Organization',
                'short_label' => '範囲整理',
                'icon' => '▦',
                'description' =>
                    '試験範囲・教材範囲を整理し、次の学習配分を決めます。',
                'fit_score' => 20,
                'source' => 'state_method',
            ]);
        }

        if ($type === 'skill_learning') {
            $existing->push([
                'key' => self::PRACTICAL_EVIDENCE,
                'label' => 'Practical Evidence',
                'short_label' => '実践・成果物',
                'icon' => '◎',
                'description' =>
                    '実際に作る・使う・解くなどの実践結果をEvidenceとして現在地に反映します。',
                'fit_score' => 20,
                'source' => 'state_method',
            ]);
        }

        return $existing->all();
    }

    /**
     * @param array<string,mixed> $state
     * @param array{attempt_count:int,topics:array<int,string>} $knowledgeGap
     * @param array<int,string> $retentionDue
     * @param array<int,string> $cooldown
     * @param array<int,string> $mastered
     * @return array{0:string,1:?string,2:string,3:string}
     */
    private function selectPrimary(
        string $type,
        array $state,
        string $phase,
        bool $resume,
        ?string $adaptiveRouteKind,
        array $knowledgeGap,
        array $retentionDue,
        string $basePrimary,
        array $cooldown,
        array $mastered,
    ): array {
        if ($resume) {
            return [
                StudyActivityPolicyService::QUESTION_PRACTICE,
                'resume',
                '途中の演習は問題セットと方針がすでに確定しているため、新しい学習方法へ切り替えず続きから再開します。',
                'resume_continuity',
            ];
        }

        if (
            $adaptiveRouteKind === 'study_scope'
            && (bool) (
                $state['has_confirmed_scope']
                ?? false
            )
        ) {
            return [
                self::SCOPE_ORGANIZATION,
                null,
                '確認済みScopeはありますが、現在のStudy Intelligenceでは次の学習配分を決める前に範囲・試験情報の再確認が必要です。',
                'adaptive_scope',
            ];
        }

        if (
            $type === 'school_test'
            && ! (bool) (
                $state['has_confirmed_scope']
                ?? false
            )
        ) {
            return [
                self::SCOPE_ORGANIZATION,
                null,
                '学校テストでは出題範囲が学習順序を直接変えるため、問題数を増やす前に今回の範囲を整理します。',
                'school_test_scope',
            ];
        }

        if (in_array(
            $basePrimary,
            [
                StudyActivityPolicyService::LISTENING,
                StudyActivityPolicyService::DICTATION,
                StudyActivityPolicyService::SHADOWING,
            ],
            true,
        )) {
            return [
                $basePrimary,
                null,
                match ($basePrimary) {
                    StudyActivityPolicyService::DICTATION =>
                        'このTaskは聞こえた音を文字へ変換して聞き落としを確認する工程が中心なので、Task適合度どおりDictationを優先します。',
                    StudyActivityPolicyService::SHADOWING =>
                        'このTaskは音声を追ってリズム・強勢・音のつながりを再現する工程が中心なので、Task適合度どおりShadowingを優先します。',
                    default =>
                        'このTaskは音声を聞いて意味と聞き取れない箇所を確認する工程が中心なので、Task適合度どおりListeningを優先します。',
                },
                'task_semantic_fit',
            ];
        }

        if ($type === 'memorization') {
            return [
                StudyActivityPolicyService::RECALL,
                null,
                '暗記・語彙学習では、答えを見る前に思い出すRecallを優先し、定着Evidenceを作ります。',
                'memorization_recall',
            ];
        }

        if ($type === 'skill_learning') {
            return [
                self::PRACTICAL_EVIDENCE,
                null,
                'スキル学習では問題生成だけで現在地を測らず、実際に手を動かした結果や成果物をEvidenceとして使います。',
                'skill_practical_evidence',
            ];
        }

        if ($phase === 'exam_mode') {
            return [
                StudyActivityPolicyService::QUESTION_PRACTICE,
                'exam_mode',
                '本番が近いため、教材へ戻り続けるより本番形式の横断Practiceを優先します。',
                'exam_mode',
            ];
        }

        if ($adaptiveRouteKind === 'study_recall') {
            return [
                StudyActivityPolicyService::RECALL,
                null,
                '現在のStudy Intelligenceが定着確認を優先しているため、Practiceより先にRecallを行います。',
                'adaptive_recall',
            ];
        }

        if ($knowledgeGap['attempt_count'] >= 2) {
            $topics = $knowledgeGap['topics'];
            $suffix = $topics !== []
                ? '（'.implode(' / ', $topics).'）'
                : '';

            return [
                StudyActivityPolicyService::RESOURCE_STUDY,
                null,
                '直近3回のうち複数回でknowledge/concept gapが続いています'.$suffix.'。問題数を増やすより、一度教材・解説から理解を作り直します。',
                'repeated_knowledge_gap',
            ];
        }

        if ($retentionDue !== []) {
            return [
                StudyActivityPolicyService::RECALL,
                null,
                '定着確認の時期が来ている項目（'.implode(' / ', $retentionDue).'）があるため、先に見ずに思い出す確認を行います。',
                'retention_due',
            ];
        }

        if (
            $basePrimary
            === StudyActivityPolicyService::RESOURCE_STUDY
        ) {
            return [
                StudyActivityPolicyService::RESOURCE_STUDY,
                null,
                'このTaskは新しい知識を入れる工程が中心なので、V41.10のTask適合度どおり教材学習を優先します。',
                'task_semantic_fit',
            ];
        }

        if (
            $basePrimary
            === StudyActivityPolicyService::RECALL
        ) {
            return [
                StudyActivityPolicyService::RECALL,
                null,
                'このTaskは記憶・想起の比重が高いため、V41.10のTask適合度どおりRecallを優先します。',
                'task_semantic_fit',
            ];
        }

        $variant = match ($phase) {
            'diagnosis' => 'diagnosis',
            'weakness_reinforcement' =>
                'focused_remediation',
            'exam_mode' => 'exam_mode',
            default => 'broad_practice',
        };

        $reason = match ($variant) {
            'diagnosis' =>
                '現在地をまだ測り切れていないため、まず問題演習で基準Evidenceを作ります。',
            'focused_remediation' =>
                '確認済みの弱点が残っているため、重点補強のPracticeを優先します。',
            default =>
                '現在は問題演習が最も適合し、横断確認を続ける段階です。',
        };

        if (
            $cooldown !== []
            || $mastered !== []
        ) {
            $paused = array_values(array_unique([
                ...$cooldown,
                ...$mastered,
            ]));
            $reason .= ' '.implode(' / ', array_slice($paused, 0, 4)).
                ' は集中補強を休止し、同じ分野の反復を増やしすぎないようにします。';
        }

        return [
            StudyActivityPolicyService::QUESTION_PRACTICE,
            $variant,
            $reason,
            'task_semantic_fit',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $methods
     * @param array{attempt_count:int,topics:array<int,string>} $knowledgeGap
     * @param array<int,string> $retentionDue
     * @param array<string,mixed> $state
     * @return array<int,array<string,mixed>>
     */
    private function applyStateScores(
        array $methods,
        string $primaryKey,
        array $knowledgeGap,
        array $retentionDue,
        string $phase,
        string $type,
        array $state,
    ): array {
        return collect($methods)
            ->map(function (array $method) use (
                $primaryKey,
                $knowledgeGap,
                $retentionDue,
                $phase,
                $type,
                $state,
            ) {
                $score = (int) (
                    $method['fit_score']
                    ?? 20
                );
                $key = (string) (
                    $method['key']
                    ?? ''
                );

                if (
                    $key
                    === StudyActivityPolicyService::RESOURCE_STUDY
                    && $knowledgeGap['attempt_count'] >= 2
                ) {
                    $score += 30;
                }

                if (
                    $key
                    === StudyActivityPolicyService::RECALL
                    && $retentionDue !== []
                ) {
                    $score += 25;
                }

                if (
                    $key
                    === StudyActivityPolicyService::QUESTION_PRACTICE
                    && $phase === 'exam_mode'
                ) {
                    $score += 35;
                }

                if (
                    $key === self::SCOPE_ORGANIZATION
                    && $type === 'school_test'
                    && ! (bool) (
                        $state['has_confirmed_scope']
                        ?? false
                    )
                ) {
                    $score += 80;
                }

                if (
                    $key === self::PRACTICAL_EVIDENCE
                    && $type === 'skill_learning'
                ) {
                    $score += 80;
                }

                if ($key === $primaryKey) {
                    $score = max($score, 100);
                }

                $method['fit_score'] = max(
                    20,
                    min(100, $score),
                );

                return $method;
            })
            ->values()
            ->all();
    }

    /**
     * @return array{attempt_count:int,topics:array<int,string>}
     */
    private function repeatedKnowledgeGap(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): array {
        $attempts = $this->attemptQuery(
            $plan,
            $task,
            $userId,
            $actorToken,
        )
            ->latest('created_at')
            ->latest('id')
            ->take(3)
            ->get();

        $matchingAttempts = 0;
        $topics = collect();

        foreach ($attempts as $attempt) {
            $feedback = collect(data_get(
                $attempt->assessment,
                'question_feedback',
                [],
            ))
                ->filter(fn ($item) => is_array($item))
                ->filter(function (array $item): bool {
                    $correctness = (string) (
                        $item['correctness']
                        ?? ''
                    );
                    $errorType = (string) (
                        $item['error_type']
                        ?? ''
                    );

                    return in_array(
                        $correctness,
                        ['incorrect', 'partial'],
                        true,
                    ) && in_array(
                        $errorType,
                        [
                            'knowledge_gap',
                            'concept_gap',
                        ],
                        true,
                    );
                });

            if ($feedback->isEmpty()) {
                continue;
            }

            $matchingAttempts++;
            $topics = $topics->concat(
                $feedback->flatMap(
                    fn (array $item) =>
                        is_array(
                            $item['weakness_topics']
                            ?? null
                        )
                            ? $item['weakness_topics']
                            : []
                )
            );
        }

        return [
            'attempt_count' => $matchingAttempts,
            'topics' => $this->strings(
                $topics->all(),
            ),
        ];
    }

    /**
     * @return array{total:int,due:int}
     */
    private function recallStats(
        Plan $plan,
        Task $task,
    ): array {
        $items = StudyRecallItem::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('is_active', true)
            ->get();

        return [
            'total' => $items->count(),
            'due' => $items
                ->filter(
                    fn (StudyRecallItem $item) =>
                        $item->isDue()
                )
                ->count(),
        ];
    }

    private function url(
        string $key,
        Plan $plan,
        Task $task,
    ): string {
        return match ($key) {
            StudyActivityPolicyService::RECALL =>
                route(
                    'plans.tasks.study_recall.show',
                    [$plan, $task],
                ),
            StudyActivityPolicyService::RESOURCE_STUDY =>
                route('plans.resources.index', $plan),
            StudyActivityPolicyService::LISTENING,
            StudyActivityPolicyService::DICTATION,
            StudyActivityPolicyService::SHADOWING =>
                route(
                    'plans.tasks.study_language.show',
                    [
                        $plan,
                        $task,
                        'activity' => $key,
                    ],
                ),
            self::SCOPE_ORGANIZATION =>
                route('plans.study_scope.index', $plan),
            self::PRACTICAL_EVIDENCE =>
                route(
                    'plans.tasks.guided_execution.show',
                    [$plan, $task],
                ),
            default =>
                route(
                    'plans.tasks.study_practice.show',
                    [$plan, $task],
                ),
        };
    }

    /**
     * @param array{total:int,due:int} $recallStats
     * @param array<string,mixed>|null $practiceRecommendation
     */
    private function actionLabel(
        string $key,
        ?string $variant,
        int $resourceCount,
        array $recallStats,
        ?array $practiceRecommendation,
    ): string {
        return match ($key) {
            StudyActivityPolicyService::RECALL =>
                $recallStats['total'] > 0
                    ? (
                        $recallStats['due'] > 0
                            ? 'Recallを始める'
                            : 'Recallを確認'
                    )
                    : 'Recallを準備',
            StudyActivityPolicyService::RESOURCE_STUDY =>
                $resourceCount > 0
                    ? '教材を確認'
                    : '教材を登録・確認',
            StudyActivityPolicyService::LISTENING =>
                'Listeningを始める',
            StudyActivityPolicyService::DICTATION =>
                'Dictationを始める',
            StudyActivityPolicyService::SHADOWING =>
                'Shadowingを始める',
            self::SCOPE_ORGANIZATION =>
                '範囲を整理',
            self::PRACTICAL_EVIDENCE =>
                '実行を始める',
            default => (bool) (
                $practiceRecommendation['resume']
                ?? false
            )
                ? '続きから再開'
                : (
                    (int) (
                        $practiceRecommendation[
                            'question_count'
                        ]
                        ?? 0
                    ) > 0
                        ? (int) $practiceRecommendation[
                            'question_count'
                        ].'問始める'
                        : '問題演習を始める'
                ),
        };
    }

    private function attemptQuery(
        Plan $plan,
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ): Builder {
        $query = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query
            ->whereNull('user_id')
            ->where(
                'actor_token',
                (string) $actorToken,
            );
    }

    /**
     * @return array<int,string>
     */
    private function strings(mixed $value): array
    {
        return Collection::wrap(
            is_array($value) ? $value : [],
        )
            ->filter(fn ($item) =>
                is_scalar($item)
                && trim((string) $item) !== ''
            )
            ->map(fn ($item) =>
                mb_substr(
                    trim((string) $item),
                    0,
                    120,
                )
            )
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }
}
