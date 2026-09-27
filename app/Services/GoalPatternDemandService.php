<?php

namespace App\Services;

use App\Models\GoalContext;
use App\Models\GoalContextFact;
use App\Models\GuidedExecution;
use Illuminate\Support\Collection;

class GoalPatternDemandService
{
    public const ANALYSIS_LIMIT = 5000;

    /**
     * Read existing operational data instead of creating a parallel demand log.
     * Goal Context and Guided Execution remain the source of truth.
     *
     * @return array<string,mixed>
     */
    public function analyze(string $period = '30'): array
    {
        $since = $period === 'all' ? null : now()->subDays((int) $period);

        $contextQuery = GoalContext::query()
            ->with([
                'plan:id,title,category',
                'facts' => fn ($query) => $query
                    ->where('state', '!=', 'superseded')
                    ->orderBy('id'),
            ]);

        $guidedQuery = GuidedExecution::query()
            ->with([
                'plan:id,title,category',
                'task:id,plan_id,title,description,next_action_note',
            ]);

        if ($since) {
            $contextQuery->where('created_at', '>=', $since);
            $guidedQuery->where('prepared_at', '>=', $since);
        }

        $contextCount = (clone $contextQuery)->count();
        $guidedCount = (clone $guidedQuery)->count();
        $completedGuidedCount = (clone $guidedQuery)
            ->where('status', GuidedExecution::STATUS_COMPLETED)
            ->count();

        $contexts = (clone $contextQuery)
            ->latest('created_at')
            ->limit(self::ANALYSIS_LIMIT)
            ->get();

        $guided = (clone $guidedQuery)
            ->latest('prepared_at')
            ->limit(self::ANALYSIS_LIMIT)
            ->get();

        $patternStats = $this->patternStats($contexts, $guided);
        $signalStats = $this->signalStats($contexts);
        $guidedStructureStats = $this->guidedStructureStats($guided);
        $inputStats = $this->inputStats($contexts);

        return [
            'period' => $period,
            'summary' => [
                'goal_contexts' => $contextCount,
                'guided_executions' => $guidedCount,
                'guided_completed' => $completedGuidedCount,
                'guided_completion_rate' => $guidedCount > 0
                    ? round(($completedGuidedCount / $guidedCount) * 100, 1)
                    : 0.0,
                'analyzed_contexts' => $contexts->count(),
                'analyzed_guided_executions' => $guided->count(),
            ],
            'pattern_stats' => $patternStats,
            'signal_stats' => $signalStats,
            'guided_structure_stats' => $guidedStructureStats,
            'input_stats' => $inputStats,
            'analysis_capped' => $contextCount > self::ANALYSIS_LIMIT
                || $guidedCount > self::ANALYSIS_LIMIT,
        ];
    }

    /**
     * @param Collection<int,GoalContext> $contexts
     * @param Collection<int,GuidedExecution> $guided
     */
    private function patternStats(Collection $contexts, Collection $guided): Collection
    {
        $stats = [];

        foreach ($contexts as $context) {
            $pattern = $this->patternFor(
                (string) $context->desired_state,
                (string) ($context->plan?->category ?? ''),
            );
            $this->initPattern($stats, $pattern);

            $stats[$pattern['key']]['goal_contexts']++;
            $stats[$pattern['key']]['identities'][$this->identityKey($context->user_id, $context->actor_token)] = true;
            $stats[$pattern['key']]['readiness'][$context->readiness_state] =
                ($stats[$pattern['key']]['readiness'][$context->readiness_state] ?? 0) + 1;

            foreach ($context->facts as $fact) {
                if ($fact->state === 'unknown') {
                    $stats[$pattern['key']]['known_unknowns']++;
                }

                if ($fact->key === 'measurement_method') {
                    if ($fact->state === 'unknown') {
                        $stats[$pattern['key']]['measurement_unknowns']++;
                    } elseif ($fact->state === 'confirmed') {
                        $stats[$pattern['key']]['measurement_confirmed']++;
                    }
                }
            }
        }

        $taskCounts = [];
        foreach ($guided as $execution) {
            $pattern = $this->patternFor(
                trim((string) ($execution->plan?->title ?? '')),
                trim((string) ($execution->plan?->category ?? '')),
                trim((string) ($execution->task?->title ?? '')).' '.trim((string) ($execution->task?->description ?? '')),
            );
            $this->initPattern($stats, $pattern);

            $stats[$pattern['key']]['guided_executions']++;
            $stats[$pattern['key']]['identities'][$this->identityKey($execution->user_id, $execution->actor_token)] = true;

            if ($execution->status === GuidedExecution::STATUS_COMPLETED) {
                $stats[$pattern['key']]['guided_completed']++;
            }

            $taskKey = $pattern['key'].':'.(int) $execution->task_id;
            $taskCounts[$taskKey] = ($taskCounts[$taskKey] ?? 0) + 1;
        }

        foreach ($taskCounts as $taskKey => $count) {
            if ($count < 2) {
                continue;
            }

            [$patternKey] = explode(':', $taskKey, 2);
            if (isset($stats[$patternKey])) {
                $stats[$patternKey]['repeat_tasks']++;
                $stats[$patternKey]['repeat_executions'] += $count;
            }
        }

        return collect($stats)
            ->map(function (array $stat) {
                $uniqueIdentities = count($stat['identities']);
                // Cross-user demand is intentionally the strongest signal.
                // Repeated use by one person supports the case, but must not
                // outweigh the same need appearing across multiple identities.
                $opportunityScore =
                    min(50, $uniqueIdentities * 25)
                    + min(20, $stat['guided_executions'] * 3)
                    + min(15, $stat['repeat_tasks'] * 5)
                    + min(15, $stat['measurement_unknowns'] * 5);

                $stat['unique_identities'] = $uniqueIdentities;
                $stat['opportunity_score'] = min(100, $opportunityScore);
                $stat['guided_completion_rate'] = $stat['guided_executions'] > 0
                    ? round(($stat['guided_completed'] / $stat['guided_executions']) * 100, 1)
                    : null;
                unset($stat['identities']);

                return $stat;
            })
            ->sort(function (array $left, array $right) {
                return [
                    $right['opportunity_score'],
                    $right['unique_identities'],
                    $right['guided_executions'],
                    $right['goal_contexts'],
                ] <=> [
                    $left['opportunity_score'],
                    $left['unique_identities'],
                    $left['guided_executions'],
                    $left['goal_contexts'],
                ];
            })
            ->values();
    }

    /**
     * @param array<string,array<string,mixed>> $stats
     * @param array{key:string,label:string} $pattern
     */
    private function initPattern(array &$stats, array $pattern): void
    {
        $stats[$pattern['key']] ??= [
            'key' => $pattern['key'],
            'label' => $pattern['label'],
            'goal_contexts' => 0,
            'unique_identities' => 0,
            'identities' => [],
            'readiness' => ['low' => 0, 'medium' => 0, 'high' => 0],
            'known_unknowns' => 0,
            'measurement_unknowns' => 0,
            'measurement_confirmed' => 0,
            'guided_executions' => 0,
            'guided_completed' => 0,
            'guided_completion_rate' => null,
            'repeat_tasks' => 0,
            'repeat_executions' => 0,
            'opportunity_score' => 0,
        ];
    }

    /**
     * @param Collection<int,GoalContext> $contexts
     */
    private function signalStats(Collection $contexts): Collection
    {
        $stats = [];

        foreach ($contexts as $context) {
            foreach ($context->facts as $fact) {
                $signal = $this->signalFor($fact);
                if ($signal === null) {
                    continue;
                }

                $key = $signal['key'];
                $stats[$key] ??= [
                    'key' => $key,
                    'label' => $signal['label'],
                    'dimension' => $signal['dimension'],
                    'count' => 0,
                    'identities' => [],
                    'unknown_count' => 0,
                    'latest_at' => null,
                ];

                $stats[$key]['count']++;
                $stats[$key]['identities'][$this->identityKey($context->user_id, $context->actor_token)] = true;
                if ($fact->state === 'unknown') {
                    $stats[$key]['unknown_count']++;
                }
                if (! $stats[$key]['latest_at'] || $fact->observed_at?->gt($stats[$key]['latest_at'])) {
                    $stats[$key]['latest_at'] = $fact->observed_at;
                }
            }
        }

        return collect($stats)
            ->map(function (array $stat) {
                $stat['unique_identities'] = count($stat['identities']);
                unset($stat['identities']);

                return $stat;
            })
            ->sort(function (array $left, array $right) {
                return [
                    $right['unique_identities'],
                    $right['count'],
                    $right['unknown_count'],
                ] <=> [
                    $left['unique_identities'],
                    $left['count'],
                    $left['unknown_count'],
                ];
            })
            ->values()
            ->take(30);
    }

    /**
     * @param Collection<int,GuidedExecution> $guided
     */
    private function guidedStructureStats(Collection $guided): Collection
    {
        $stats = [];

        foreach ($guided as $execution) {
            $structure = $this->guidedStructureFor($execution);
            $key = $structure['key'];

            $stats[$key] ??= [
                'key' => $key,
                'label' => $structure['label'],
                'executions' => 0,
                'completed' => 0,
                'identities' => [],
                'outcomes' => [],
                'with_focus' => 0,
                'with_observation' => 0,
                'with_adjustment' => 0,
            ];

            $stats[$key]['executions']++;
            $stats[$key]['identities'][$this->identityKey($execution->user_id, $execution->actor_token)] = true;
            if ($execution->status === GuidedExecution::STATUS_COMPLETED) {
                $stats[$key]['completed']++;
            }
            if (! empty($execution->focus_points)) {
                $stats[$key]['with_focus']++;
            }
            if (! empty($execution->observation_points)) {
                $stats[$key]['with_observation']++;
            }
            if (filled($execution->next_adjustment)) {
                $stats[$key]['with_adjustment']++;
            }
            if (filled($execution->outcome_rating)) {
                $stats[$key]['outcomes'][$execution->outcome_rating] =
                    ($stats[$key]['outcomes'][$execution->outcome_rating] ?? 0) + 1;
            }
        }

        return collect($stats)
            ->map(function (array $stat) {
                $stat['unique_identities'] = count($stat['identities']);
                $stat['completion_rate'] = $stat['executions'] > 0
                    ? round(($stat['completed'] / $stat['executions']) * 100, 1)
                    : 0.0;
                unset($stat['identities']);

                return $stat;
            })
            ->sortByDesc('executions')
            ->values();
    }

    /**
     * @param Collection<int,GoalContext> $contexts
     */
    private function inputStats(Collection $contexts): Collection
    {
        $labels = [
            'balanced' => 'Balanced',
            'quick' => 'Quick',
            'conversational' => 'Conversational',
            'compact' => 'Compact',
        ];

        return $contexts
            ->groupBy(fn (GoalContext $context) => (string) data_get($context->interaction_profile, 'preferred', 'balanced'))
            ->map(function (Collection $rows, string $mode) use ($labels) {
                return [
                    'mode' => $mode,
                    'label' => $labels[$mode] ?? $mode,
                    'contexts' => $rows->count(),
                    'answers' => (int) $rows->sum(fn ($context) => (int) data_get($context->interaction_profile, 'answered', 0)),
                    'skips' => (int) $rows->sum(fn ($context) => (int) data_get($context->interaction_profile, 'skips', 0)),
                ];
            })
            ->sortByDesc('contexts')
            ->values();
    }

    /**
     * @return array{key:string,label:string}
     */
    public function patternFor(string $goal, string $category = '', string $taskText = ''): array
    {
        $text = mb_strtolower(trim($goal.' '.$category.' '.$taskText));

        return match (true) {
            preg_match('/営業|販売|商談|成約|顧客|セールス|売上|台売|契約/u', $text) === 1
                => ['key' => 'sales', 'label' => '営業・販売'],
            preg_match('/サッカー|野球|バスケ|バレーボール|テニス|フットサル|ゴルフ|スポーツ|試合|競技/u', $text) === 1
                => ['key' => 'sports', 'label' => 'スポーツ・競技'],
            preg_match('/筋トレ|ジム|ランニング|ジョギング|運動|体力|フィットネス|ダイエット/u', $text) === 1
                => ['key' => 'fitness', 'label' => '運動・フィットネス'],
            preg_match('/就活|就職|転職|キャリア|面接|応募|内定|career|job/u', $text) === 1
                => ['key' => 'career', 'label' => '就職・キャリア'],
            preg_match('/資格|試験|検定|学習|勉強|toeic|応用情報|基本情報|簿記|受験/u', $text) === 1
                => ['key' => 'study', 'label' => '学習・資格'],
            preg_match('/個人開発|ゲーム開発|制作活動|アプリ|サービス|実装|開発|コード|プログラ/u', $text) === 1
                => ['key' => 'development', 'label' => '開発・制作'],
            preg_match('/演奏|音楽|絵|イラスト|動画|撮影|創作|デザイン|小説|作品/u', $text) === 1
                => ['key' => 'creative', 'label' => '創作・表現'],
            preg_match('/貯金|家計|旅行|引越|片付け|掃除|生活|習慣|料理/u', $text) === 1
                => ['key' => 'life', 'label' => '生活・習慣'],
            default => ['key' => 'other', 'label' => 'その他・未分類'],
        };
    }

    /**
     * @return array{key:string,label:string,dimension:string}|null
     */
    private function signalFor(GoalContextFact $fact): ?array
    {
        if (! in_array($fact->state, ['confirmed', 'unknown'], true)) {
            return null;
        }

        if ($fact->key === 'current_state_stage') {
            $stage = (string) data_get($fact->value_json, 'stage', 'unknown');

            return [
                'key' => 'current_state:'.$stage,
                'label' => match ($stage) {
                    'starting' => '現在地: これから始める',
                    'returning' => '現在地: 再開',
                    'active' => '現在地: 取組中',
                    'measurable' => '現在地: 数値で説明可能',
                    default => '現在地: その他',
                },
                'dimension' => 'current_state',
            ];
        }

        if ($fact->key === 'current_state_summary') {
            return [
                'key' => 'current_state:free_text',
                'label' => '現在地: 自由記述',
                'dimension' => 'current_state',
            ];
        }

        if ($fact->key === 'success_signal_style') {
            $style = (string) data_get($fact->value_json, 'style', 'unknown');

            return [
                'key' => 'success_signal:'.$style,
                'label' => match ($style) {
                    'numeric' => '成功基準: 数値',
                    'capability' => '成功基準: 能力',
                    'result' => '成功基準: 結果・評価',
                    default => '成功基準: その他',
                },
                'dimension' => 'success_signal',
            ];
        }

        if ($fact->key === 'success_signal') {
            return [
                'key' => 'success_signal:'.($fact->state === 'unknown' ? 'unknown' : 'free_text'),
                'label' => $fact->state === 'unknown' ? '成功基準: 未確定' : '成功基準: 自由記述',
                'dimension' => 'success_signal',
            ];
        }

        if ($fact->key === 'constraints_general') {
            $kind = (string) data_get($fact->value_json, 'kind', '');

            return [
                'key' => 'constraint:'.($fact->state === 'unknown' ? 'unknown' : ($kind ?: 'free_text')),
                'label' => match (true) {
                    $fact->state === 'unknown' => '制約: 未確定',
                    $kind === 'none' => '制約: 特になし',
                    $kind === 'time' => '制約: 時間',
                    $kind === 'deadline' => '制約: 期限',
                    $kind === 'money' => '制約: 予算',
                    $kind === 'environment' => '制約: 場所・環境',
                    default => '制約: 自由記述',
                },
                'dimension' => 'constraint',
            ];
        }

        if ($fact->key === 'measurement_method') {
            $mode = (string) data_get($fact->value_json, 'mode', '');

            return [
                'key' => 'measurement:'.($fact->state === 'unknown' ? 'unknown' : ($mode ?: 'free_text')),
                'label' => match (true) {
                    $fact->state === 'unknown' => '観測方法: 未確定',
                    $mode === 'metric' => '観測方法: 数値',
                    $mode === 'artifact' => '観測方法: 写真・動画・成果物',
                    $mode === 'external' => '観測方法: 第三者反応',
                    $mode === 'reflection' => '観測方法: 自己振り返り',
                    default => '観測方法: 自由記述',
                },
                'dimension' => 'measurement',
            ];
        }

        return null;
    }

    /**
     * @return array{key:string,label:string}
     */
    private function guidedStructureFor(GuidedExecution $execution): array
    {
        $text = mb_strtolower(trim(
            ($execution->task?->title ?? '').' '.($execution->task?->description ?? '').' '.$execution->intent
        ));

        return match (true) {
            preg_match('/商談|営業|販売|顧客|接客|交渉|提案|契約/u', $text) === 1
                => ['key' => 'sales_conversation', 'label' => '営業・商談'],
            preg_match('/サッカー|試合|練習|トレーニング|競技/u', $text) === 1
                => ['key' => 'sports_practice', 'label' => 'スポーツ練習・試合'],
            preg_match('/面接|面談|応募/u', $text) === 1
                => ['key' => 'interview', 'label' => '面接・面談'],
            preg_match('/プレゼン|発表|スピーチ|登壇/u', $text) === 1
                => ['key' => 'presentation', 'label' => 'プレゼン・発表'],
            preg_match('/筋トレ|ジム|ランニング|ジョギング|運動/u', $text) === 1
                => ['key' => 'fitness_training', 'label' => '運動・トレーニング'],
            preg_match('/会議|ミーティング|相談|話す|聞く/u', $text) === 1
                => ['key' => 'conversation', 'label' => '対話・ミーティング'],
            default => ['key' => 'real_world_other', 'label' => 'その他の現実行動'],
        };
    }

    private function identityKey(?int $userId, ?string $actorToken): string
    {
        return $userId
            ? 'u:'.$userId
            : 'a:'.hash('sha256', (string) $actorToken);
    }
}
