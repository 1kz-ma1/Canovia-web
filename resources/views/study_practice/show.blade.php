@extends('layouts.app')

@section('title', 'AI演習 | Canovia')

@section('content')
    <div
        class="mx-auto max-w-5xl space-y-5"
        data-study-practice-root
        data-study-practice-scroll-to="{{ $studyPracticeScrollTo ?? session('study_practice_scroll_to') }}"
    >
        @if ($resumeFastPath ?? false)
            @php
                $resumeProviderLabel = match ($currentPracticeSession?->question_provider) {
                    'question_bank' => 'Canovia Question Bank',
                    'native_ai' => 'Canovia Native AI',
                    'hybrid_ai' => 'Canovia Hybrid',
                    'external_ai' => '外部AI',
                    default => '既存の演習',
                };
            @endphp
            <section class="page-card border-cyan-300/25 p-5 sm:p-6" data-study-practice-resume-fast-path>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[11px] font-black uppercase tracking-[0.16em] text-cyan-300">CONTINUE PRACTICE</p>
                        <h1 class="mt-2 text-2xl font-black text-slate-50">{{ $exerciseTitle ?: '演習の続きを再開' }}</h1>
                        <p class="mt-2 text-sm text-slate-400">{{ $plan->displayIcon() }} {{ $plan->title }} / {{ $task->title }}</p>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">前回の問題セットと保存済みの回答をそのまま復元しました。問題生成や出題方針の再計算は行わず、このSessionの続きから再開します。</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="badge badge-slate">{{ (int) data_get($resumeProgress, 'answered', 0) }}/{{ (int) data_get($resumeProgress, 'total', count($questions ?? [])) }}問 回答済み</span>
                            <span class="badge badge-slate">{{ $resumeProviderLabel }}</span>
                            @if ($currentPracticeSession)
                                <span class="badge badge-slate">Session #{{ $currentPracticeSession->id }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">Planへ戻る</a>
                        <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="btn-secondary" data-adaptive-learning-entry>1問から学習（新方式）</a>
                        <form method="POST" action="{{ route('plans.tasks.study_practice.reset', [$plan, $task]) }}">
                            @csrf
                            <button type="submit" class="btn-secondary">新しい演習を作る</button>
                        </form>
                    </div>
                </div>
            </section>
        @else
        <section class="page-card border-cyan-300/20 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-300">CANOVIA TOOL / AI PRACTICE</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">AI演習</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ $plan->displayIcon() }} {{ $plan->title }} / {{ $task->title }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">Planへ戻る</a>
                    <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="btn-secondary" data-adaptive-learning-entry>1問ごとに採点（Bank）</a>
                    @if ($questions)
                        <form method="POST" action="{{ route('plans.tasks.study_practice.reset', [$plan, $task]) }}">
                            @csrf
                            <button type="submit" class="btn-secondary">演習をやり直す</button>
                        </form>
                    @endif
                </div>
            </div>
            <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-300">CanoviaがTaskと学習履歴から今回の演習方針を決め、問題ソースを自動選択します。Question Bankで十分にカバーできる場合はCanovia内で直接出題・採点し、不足する場合だけ外部AIへ引き継ぎます。</p>

            <div class="mt-4 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.04] p-4" data-guide-target="practice-strategy">
                @php
                    $examProfile = is_array($practiceStrategy['exam_profile'] ?? null) ? $practiceStrategy['exam_profile'] : [];
                    $weaknessPriority = is_array($practiceStrategy['weakness_priority'] ?? null) ? $practiceStrategy['weakness_priority'] : [];
                    $questionMix = is_array($practiceStrategy['question_mix'] ?? null) ? $practiceStrategy['question_mix'] : [];
                    $primaryTopics = collect($weaknessPriority['primary_topics'] ?? []);
                    $secondaryTopics = collect($weaknessPriority['secondary_topics'] ?? []);
                    $monitorTopics = collect($weaknessPriority['monitor_topics'] ?? []);
                    $learningPhase = is_array($practiceStrategy['learning_phase'] ?? null) ? $practiceStrategy['learning_phase'] : [];
                    $weaknessControl = is_array($practiceStrategy['weakness_control'] ?? null) ? $practiceStrategy['weakness_control'] : [];
                    $topicStates = collect($weaknessControl['topic_states'] ?? []);
                    $activePhaseTopics = collect($weaknessControl['active_topics'] ?? []);
                    $graduatedPhaseTopics = collect($weaknessControl['graduated_topics'] ?? []);
                    $cappedPhaseTopics = collect($weaknessControl['capped_topics'] ?? []);
                    $phaseKey = (string) ($learningPhase['phase'] ?? 'general_practice');
                    $phaseLabel = (string) ($learningPhase['label'] ?? '総合演習');
                    $daysUntilExam = $learningPhase['days_until_exam'] ?? null;
                    $planWideWeaknessHandoff = (array) data_get(
                        $practiceStrategy,
                        'routing_policy.plan_wide_weakness_handoff',
                        [],
                    );
                    $planWideAppliedTopics = collect(
                        $planWideWeaknessHandoff['applied_topics'] ?? [],
                    )->filter();
                @endphp
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-cyan-300">PRACTICE STRATEGY</p>
                        <h2 class="mt-1 text-base font-black text-slate-100">{{ $practiceStrategy['label'] ?? 'Task理解度確認' }}</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-400">{{ $practiceStrategy['reason'] ?? '' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-slate">現在：{{ $phaseLabel }}</span>
                        @if ($daysUntilExam !== null)
                            <span class="badge badge-slate">試験まで {{ (int) $daysUntilExam }}日</span>
                        @endif
                        @if (! empty($examProfile['label']))
                            <span class="badge badge-slate">{{ $examProfile['label'] }}</span>
                        @endif
                        <span class="badge badge-slate">{{ (int) ($practiceStrategy['target_question_count'] ?? 10) }}問目安</span>
                    </div>
                </div>

                @if (($practiceStrategy['key'] ?? null) === 'weakness_reinforcement')
                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <div class="rounded-xl border border-cyan-300/10 bg-slate-950/20 px-3 py-2">
                            <p class="text-[10px] font-black text-cyan-300">重点弱点</p>
                            <p class="mt-1 text-sm font-bold text-slate-100">{{ (int) ($questionMix['primary'] ?? 0) }}問</p>
                            <p class="mt-1 text-[10px] leading-4 text-slate-500">{{ $primaryTopics->isNotEmpty() ? $primaryTopics->implode(' / ') : '単発ミスは重点固定しない' }}</p>
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/20 px-3 py-2">
                            <p class="text-[10px] font-black text-slate-400">他の弱点・再確認</p>
                            <p class="mt-1 text-sm font-bold text-slate-100">{{ (int) ($questionMix['secondary'] ?? 0) }}問</p>
                            <p class="mt-1 text-[10px] leading-4 text-slate-500">{{ $secondaryTopics->isNotEmpty() ? $secondaryTopics->implode(' / ') : 'なし' }}</p>
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/20 px-3 py-2">
                            <p class="text-[10px] font-black text-slate-400">横断診断</p>
                            <p class="mt-1 text-sm font-bold text-slate-100">{{ (int) ($questionMix['diagnostic'] ?? 0) }}問</p>
                            <p class="mt-1 text-[10px] leading-4 text-slate-500">別の弱点が隠れていないか確認</p>
                        </div>
                    </div>
                    @if ($monitorTopics->isNotEmpty())
                        <p class="mt-3 text-[11px] leading-5 text-slate-500">監視中：{{ $monitorTopics->implode(' / ') }}。最近の出題量や確度を見て、必要なら後の演習で再確認します。</p>
                    @endif
                    @if ($activePhaseTopics->isNotEmpty())
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($topicStates->whereIn('topic', $activePhaseTopics)->take(4) as $topicState)
                                <div class="rounded-xl border border-cyan-300/10 bg-slate-950/20 px-3 py-2">
                                    <p class="text-xs font-bold text-slate-200">{{ $topicState['topic'] }}</p>
                                    <p class="mt-1 text-[10px] leading-4 text-slate-500">
                                        補完Evidence: {{ (int) ($topicState['targeted_sessions'] ?? 0) }} Session / 推定{{ (int) ($topicState['targeted_question_budget'] ?? 0) }}問
                                        · 卒業までSessionあと{{ (int) ($topicState['remaining_sessions_to_graduation'] ?? 0) }}
                                        / 推定{{ (int) ($topicState['remaining_question_budget_to_graduation'] ?? 0) }}問
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @elseif (collect($practiceStrategy['focus_topics'] ?? [])->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (($practiceStrategy['focus_topics'] ?? []) as $topic)
                            <span class="badge badge-slate">{{ $topic }}</span>
                        @endforeach
                    </div>
                @endif

                @if ($phaseKey === 'general_practice')
                    <div class="mt-3 rounded-xl border border-emerald-300/10 bg-emerald-300/[0.035] px-3 py-2">
                        <p class="text-xs font-bold text-emerald-100">弱点だけに寄せず、総合演習で全体成績を再確認します。</p>
                        @if ($graduatedPhaseTopics->isNotEmpty())
                            <p class="mt-1 text-[10px] leading-4 text-slate-500">集中補完を卒業：{{ $graduatedPhaseTopics->take(4)->implode(' / ') }}</p>
                        @endif
                        @if ($cappedPhaseTopics->isNotEmpty())
                            <p class="mt-1 text-[10px] leading-4 text-slate-500">深掘り上限により総合演習へ戻したTopic：{{ $cappedPhaseTopics->take(4)->implode(' / ') }}</p>
                        @endif
                    </div>
                @elseif ($phaseKey === 'exam_mode')
                    <div class="mt-3 rounded-xl border border-amber-300/15 bg-amber-300/[0.04] px-3 py-2">
                        <p class="text-xs font-bold text-amber-100">本番バランスを優先します。新しい細かい弱点探索より、AP科目A相当の総合確認を進めます。</p>
                    </div>
                @endif

                @if ($planWideAppliedTopics->isNotEmpty())
                    <div
                        class="mt-3 rounded-xl border border-sky-300/15 bg-sky-300/[0.035] px-3 py-2"
                        data-plan-wide-weakness-handoff
                    >
                        <p class="text-xs font-bold text-sky-100">
                            100問後のPlan全体再確認:
                            {{ $planWideAppliedTopics->implode(' / ') }}
                        </p>
                        <p class="mt-1 text-[10px] leading-4 text-slate-500">
                            Plan全体で繰り返し弱かったTopicを最大{{ (int) ($planWideWeaknessHandoff['maximum_recheck_questions'] ?? 2) }}問だけ再確認します。残りは分野横断の探索を維持します。
                        </p>
                    </div>
                @endif

                @php
                    $providerKey = $practiceProvider['provider'] ?? 'external_ai';
                    $providerLabel = match ($providerKey) {
                        'question_bank' => 'Canovia Question Bank',
                        'native_ai' => 'Canovia Native AI',
                        'hybrid_ai' => 'Canovia Hybrid（Bank優先 + AI補完）',
                        'external_ai' => '外部AI',
                        default => $providerKey,
                    };
                    $providerPackTitle = data_get($practiceProvider, 'payload.pack.title')
                        ?: data_get($currentPracticeSession?->provider_payload, 'pack.title');
                @endphp
                <p class="mt-3 text-[11px] leading-5 text-slate-500">
                    問題ソース：{{ $providerLabel }}
                    @if ($providerPackTitle)
                        · {{ $providerPackTitle }}
                    @endif
                    @if ($currentPracticeSession)
                        · Session #{{ $currentPracticeSession->id }}
                    @endif
                </p>
            </div>

            @include('study_practice.partials.cumulative-checkpoint', [
                'checkpoint' => $practiceCheckpoint ?? [],
            ])

            @php
                $recommendedActivity = (array) data_get($practiceReliability ?? [], 'recommended_activity', []);
                $questionPracticeIsPrimary = data_get($recommendedActivity, 'key') === 'question_practice';
            @endphp
            <div class="mt-4 rounded-2xl border border-violet-300/15 bg-violet-300/[0.035] p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-[0.14em] text-violet-300">PRACTICE RELIABILITY</p>
                        <h2 class="mt-1 text-base font-black text-slate-100">この演習の信頼度の目安</h2>
                    </div>
                    <span class="badge badge-slate">{{ data_get($practiceReliability, 'overall_label', '中') }} · {{ (int) data_get($practiceReliability, 'overall_score', 0) }}/100</span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ((array) data_get($practiceReliability, 'metrics', []) as $metric)
                        <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-bold text-slate-200">{{ $metric['label'] }}</span>
                                <span class="text-[10px] font-bold text-slate-500">{{ $metric['label_level'] }} · {{ (int) $metric['score'] }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-800">
                                <div class="h-full rounded-full bg-current text-violet-300" style="width: {{ max(0, min(100, (int) $metric['score'])) }}%"></div>
                            </div>
                            <p class="mt-2 text-[10px] leading-4 text-slate-500">{{ $metric['note'] }}</p>
                        </div>
                    @endforeach
                </div>

                @php
                    $candidateSignal = (array) data_get(
                        $practiceReliability ?? [],
                        'candidate_signal',
                        [],
                    );
                    $candidateSignalStatus = (string) (
                        $candidateSignal['status']
                        ?? 'unavailable'
                    );
                @endphp

                @if (
                    filled($candidateSignal['exam_profile_key'] ?? null)
                    && $candidateSignalStatus !== 'unavailable'
                )
                    <details
                        class="mt-3 rounded-xl border border-white/8 bg-slate-950/20 p-3"
                        data-practice-candidate-reliability
                    >
                        <summary class="cursor-pointer text-xs font-black text-slate-200">
                            Question Candidate運営実績
                        </summary>

                        <div class="mt-3 border-t border-white/8 pt-3">
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ([
                                    [
                                        'label' => 'Human Review',
                                        'value' => (int) ($candidateSignal['reviewed_count'] ?? 0),
                                    ],
                                    [
                                        'label' => 'Bankへ昇格',
                                        'value' => (int) ($candidateSignal['promoted_count'] ?? 0),
                                    ],
                                    [
                                        'label' => '採点済み再利用',
                                        'value' => (int) ($candidateSignal['assessed_reuse_count'] ?? 0),
                                    ],
                                    [
                                        'label' => 'Signal強度',
                                        'value' => ((int) ($candidateSignal['evidence_strength_percent'] ?? 0)).'%',
                                    ],
                                ] as $stat)
                                    <div class="rounded-lg border border-white/6 bg-black/10 p-2 text-center">
                                        <p class="text-[10px] text-slate-600">{{ $stat['label'] }}</p>
                                        <strong class="mt-1 block text-sm text-slate-100">{{ $stat['value'] }}</strong>
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-3 text-[10px] leading-4 text-slate-500">
                                {{ $candidateSignal['note'] ?? '' }}
                            </p>

                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-600">
                                <span>
                                    promoted / reviewed:
                                    {{ ($candidateSignal['promotion_rate_percent'] ?? null) !== null
                                        ? ((int) $candidateSignal['promotion_rate_percent']).'%'
                                        : '—' }}
                                </span>
                                <span>
                                    selected reuse:
                                    {{ (int) ($candidateSignal['selected_reuse_count'] ?? 0) }}
                                </span>
                                <span>
                                    current Session:
                                    {{ (int) ($candidateSignal['current_session_promoted_candidate_count'] ?? 0) }}
                                </span>
                                <span>
                                    Reliability補正:
                                    {{ ((int) ($candidateSignal['applied_adjustment'] ?? 0)) >= 0 ? '+' : '' }}{{ (int) ($candidateSignal['applied_adjustment'] ?? 0) }}pt
                                </span>
                            </div>

                            <p class="mt-2 text-[10px] leading-4 text-amber-200/70">
                                学習者の正答率はQuestion品質の判定に使っていません。
                            </p>
                        </div>
                    </details>
                @endif

                <p class="mt-3 text-[10px] leading-4 text-slate-600">{{ data_get($practiceReliability, 'disclaimer') }}</p>

                @if (! $questionPracticeIsPrimary)
                    <div class="mt-3 rounded-xl border border-amber-300/15 bg-amber-300/[0.04] p-3">
                        <p class="text-xs font-bold text-amber-100">このTaskでは {{ data_get($recommendedActivity, 'label', '別の学習方法') }} の方が相性が高いと判定しています。</p>
                        <a href="{{ route('plans.tasks.study_activity.show', [$plan, $task]) }}" class="mt-2 inline-block text-xs font-bold text-amber-200 hover:text-amber-100">推奨学習方法を見る →</a>
                    </div>
                @endif
            </div>
        </section>
        @endif

        @if (session('success'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif
        @if (session('status'))
            <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">{{ session('status') }}</div>
        @endif

        @php
            $practiceStageIndex = match ($practiceStage ?? 'setup') {
                'answering' => 2,
                'evaluation' => 3,
                'result' => 4,
                default => 1,
            };
            $practiceSteps = [
                1 => ['label' => '問題準備', 'short' => '準備'],
                2 => ['label' => '回答', 'short' => '回答'],
                3 => ['label' => 'AI評価', 'short' => '評価'],
                4 => ['label' => '結果・次Action', 'short' => '結果'],
            ];
        @endphp

        @unless ($resumeFastPath ?? false)
        <section class="page-card px-4 py-3 sm:px-5" aria-label="AI演習の進行状況">
            <div class="grid grid-cols-4 gap-2">
                @foreach ($practiceSteps as $index => $step)
                    @php
                        $isComplete = $index < $practiceStageIndex;
                        $isCurrent = $index === $practiceStageIndex;
                    @endphp
                    <div class="min-w-0 rounded-xl border px-2 py-2 text-center {{ $isCurrent ? 'border-cyan-300/30 bg-cyan-300/[0.06]' : ($isComplete ? 'border-emerald-300/15 bg-emerald-300/[0.035]' : 'border-slate-800 bg-slate-950/25') }}">
                        <span class="block text-[10px] font-black {{ $isCurrent ? 'text-cyan-200' : ($isComplete ? 'text-emerald-300' : 'text-slate-600') }}">
                            {{ $isComplete ? '✓' : $index }}
                        </span>
                        <span class="mt-1 block truncate text-[10px] font-bold sm:text-xs {{ $isCurrent ? 'text-slate-100' : 'text-slate-500' }}">
                            <span class="sm:hidden">{{ $step['short'] }}</span>
                            <span class="hidden sm:inline">{{ $step['label'] }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
        @endunless

        @php
            $questionJsonError = $errors->first('questions_json');
            $questionJsonRepairPrompt = $questionJsonError ? implode("\n", [
                'CanoviaのAI演習・問題JSONでエラーが発生しました。',
                '下の「元のCanovia問題作成プロンプト」を仕様と対象Plan・Taskの唯一の正として扱ってください。',
                'エラー解消に必要な箇所だけ修正し、問題文・response_fields・選択肢・難易度・出題意図など正しい内容はできるだけ保持してください。',
                'schema_versionは"1.0"、flowは"study_practice"のままにしてください。',
                'target_plan.idは '.$plan->id.'、target_task.idは '.$task->id.' のままにし、別のIDを推測・生成しないでください。',
                '修正後はJSONとして構文解析できることを確認してください。',
                '説明文・Markdown・コードフェンス・コメントを付けず、有効なJSONだけを返してください。',
                '',
                '【Canoviaのエラー】',
                $questionJsonError,
                '',
                '【エラーになったJSON】',
                old('questions_json', ''),
                '',
                '【元のCanovia問題作成プロンプト】',
                $generationPrompt,
            ]) : null;

            $assessmentJsonError = $errors->first('assessment_json');
            $assessmentJsonRepairPrompt = $assessmentJsonError ? implode("\n", [
                'CanoviaのAI演習・評価JSONでエラーが発生しました。',
                '下の「元のCanovia評価プロンプト」を仕様と対象Plan・Taskの唯一の正として扱ってください。',
                'エラー解消に必要な箇所だけ修正し、採点結果・question_feedback・思考過程フィードバック・強み・弱点・評価根拠・next_action・next_stepなど正しい内容はできるだけ保持してください。',
                'schema_versionは"1.0"、flowは"study_assessment"のままにしてください。',
                'target_plan.idは '.$plan->id.'、target_task.idは '.$task->id.' のままにし、別のIDを推測・生成しないでください。',
                'score_percentとrecommended_task_progress_percentは0〜100の整数にしてください。',
                '修正後はJSONとして構文解析できることを確認してください。',
                '説明文・Markdown・コードフェンス・コメントを付けず、有効なJSONだけを返してください。',
                '',
                '【Canoviaのエラー】',
                $assessmentJsonError,
                '',
                '【エラーになったJSON】',
                old('assessment_json', ''),
                '',
                '【元のCanovia評価プロンプト】',
                $evaluationPrompt ?: '（評価プロンプトを取得できませんでした。Canoviaで回答をまとめ直してください。）',
            ]) : null;
        @endphp

        @if (! $questions)
            @if (($practiceProvider['mode'] ?? 'handoff') === 'direct')
                <section class="page-card border-emerald-300/20 p-5 sm:p-6">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-8 place-items-center rounded-full bg-emerald-300/10 text-sm font-black text-emerald-200">1</span>
                        <div>
                            <h2 class="font-black text-slate-100">Canovia問題集から演習を始める</h2>
                            <p class="text-xs text-slate-500">今回のTaskをQuestion Bankだけで十分にカバーできます。外部AIへのコピペは不要です。</p>
                        </div>
                    </div>

                    @if (data_get($practiceProvider, 'payload.coverage.required_count'))
                        <div class="mt-4 grid gap-2 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                                <p class="text-[10px] text-slate-500">今回の問題数</p>
                                <strong class="mt-1 block text-lg text-slate-100">{{ data_get($practiceProvider, 'payload.coverage.required_count') }}</strong>
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                                <p class="text-[10px] text-slate-500">Pack内の有効問題</p>
                                <strong class="mt-1 block text-lg text-slate-100">{{ data_get($practiceProvider, 'payload.coverage.active_count') }}</strong>
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                                <p class="text-[10px] text-slate-500">重点分野一致</p>
                                <strong class="mt-1 block text-lg text-slate-100">{{ data_get($practiceProvider, 'payload.coverage.focus_match_count') }}</strong>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('plans.tasks.study_practice.prepare', [$plan, $task]) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="prepare_request_id" value="{{ $prepareRequestId }}">
                        <button type="submit" class="btn-primary">演習を始める</button>
                    </form>
                </section>
            @else
                @if ($nativeAiAvailable)
                    <section class="page-card border-cyan-300/25 p-5 sm:p-6">
                        <div class="flex items-center gap-3">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-cyan-300/10 text-sm font-black text-cyan-200">1</span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="font-black text-slate-100">Canovia内で演習を準備</h2>
                                    <span class="badge badge-green">Premium</span>
                                </div>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Question Bankで使える問題を先に選び、足りない分だけNative AIで補完します。コピーや貼り付けは不要です。</p>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('plans.tasks.study_practice.native.prepare', [$plan, $task]) }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="prepare_request_id" value="{{ $prepareRequestId }}">
                            <button type="submit" class="btn-primary">Canoviaで演習を始める</button>
                        </form>

                        <p class="mt-3 text-[11px] leading-5 text-slate-500">
                            AI Capacity: {{ data_get($nativeAiCapacity, 'policy.label', data_get($nativeAiCapacity, 'tier', 'standard')) }}
                            · Bankだけで揃う場合はAI生成を行いません。Native AIが必要な場面で利用できない場合も、外部AIの手動フローへ切り替えられます。
                        </p>
                    </section>

                    <details class="page-card p-5 sm:p-6" @if(session('native_ai_fallback')) open @endif>
                        <summary class="cursor-pointer text-sm font-black text-slate-200">外部AIを使う / Native AIが使えない場合</summary>
                        <div class="mt-4 space-y-4">
                @elseif ($nativeAiEntitled && ! $nativeAiConfigured)
                    <section class="page-card border-amber-300/15 p-5 sm:p-6">
                        <p class="text-sm font-black text-slate-100">Native AIは現在準備中です</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Premium権利は有効ですが、サーバー側のNative AI設定がまだ有効化されていません。下の外部AIフローはそのまま使えます。</p>
                    </section>
                @endif

                <section class="page-card p-5 sm:p-6">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-8 place-items-center rounded-full bg-cyan-300/10 text-sm font-black text-cyan-200">1</span>
                        <div>
                            <h2 class="font-black text-slate-100">演習問題を準備する</h2>
                            <p class="text-xs text-slate-500">Question BankのCoverageが足りないため、今回だけ外部AIへ引き継ぎます。</p>
                        </div>
                    </div>

                    <textarea id="studyPracticeGenerationPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $generationPrompt }}</textarea>

                    <div class="mt-4 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4">
                        <p class="text-sm font-semibold text-slate-100">AIへ渡す準備はCanovia側で完了しています</p>
                        <p class="mt-1 text-xs leading-5 text-slate-400">原文を読む必要はありません。普段使っているAIへ、そのままコピーして送ってください。</p>
                        <button type="button" class="btn-primary mt-4" data-copy-target="#studyPracticeGenerationPrompt">演習準備プロンプトをコピー</button>

                        <details class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <summary class="cursor-pointer text-xs font-semibold text-slate-300">このプロンプトに含まれる情報</summary>
                            <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-500">
                                <li>・Plan / TaskのID・タイトル・説明・現在進捗</li>
                                <li>・Canoviaが決めた今回の演習方針と重点分野</li>
                                <li>・最近の演習履歴、弱点、次Action</li>
                                <li>・問題数、回答UI、JSON形式の制約</li>
                                <li>・対象Plan / Taskを変更しないための安全条件</li>
                            </ul>
                        </details>
                    </div>
                </section>

                <section class="page-card p-5 sm:p-6">
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-8 place-items-center rounded-full bg-cyan-300/10 text-sm font-black text-cyan-200">2</span>
                        <div>
                            <h2 class="font-black text-slate-100">問題をCanoviaへ戻す</h2>
                            <p class="text-xs text-slate-500">AI側で返答をコピーしたら、Canoviaでは1回押すだけで貼り付けと読み込みを進められます。</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('plans.tasks.study_practice.import', [$plan, $task]) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="prepare_request_id" value="{{ $prepareRequestId }}">

                        <button
                            type="button"
                            class="btn-primary"
                            data-paste-target="#questions_json"
                            data-paste-submit="1"
                            data-paste-fallback="#questionJsonManualInput"
                            data-paste-status="#questionJsonPasteStatus"
                        >クリップボードから貼り付けて読み込む</button>
                        <p id="questionJsonPasteStatus" class="mt-2 hidden text-xs leading-5 text-slate-400" aria-live="polite"></p>
                        @error('questions_json')<p class="mt-2 text-sm font-semibold text-rose-300">{{ $message }}</p>@enderror

                        <details id="questionJsonManualInput" class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3" @if($questionJsonError || old('questions_json')) open @endif>
                            <summary class="cursor-pointer text-xs font-semibold text-slate-300">手動で貼り付ける / JSONを確認する</summary>
                            <textarea id="questions_json" name="questions_json" class="form-control mt-3 min-h-[180px] font-mono text-xs" placeholder="AIが返したJSONを貼り付け">{{ old('questions_json') }}</textarea>
                            <button type="submit" class="btn-secondary mt-3">このJSONを読み込む</button>
                        </details>

                        @if ($questionJsonRepairPrompt)
                            <div class="mt-4 rounded-2xl border border-rose-300/20 bg-rose-300/[0.04] p-4">
                                <p class="text-sm font-black text-rose-100">修正依頼を作りました</p>
                                <p class="mt-1 text-xs leading-5 text-slate-400">最初に問題を作ったAIへ修正依頼を送り、返ってきたJSONをもう一度貼り付けてください。</p>
                                <textarea id="studyPracticeQuestionRepairPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $questionJsonRepairPrompt }}</textarea>
                                <button type="button" class="btn-primary mt-3" data-copy-target="#studyPracticeQuestionRepairPrompt">修正依頼をコピー</button>
                                <details class="ai-handoff-details mt-3 rounded-xl border border-rose-300/10 bg-slate-950/25 p-3">
                                    <summary class="cursor-pointer text-xs font-semibold text-rose-100/80">修正依頼に含まれる情報</summary>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">Canoviaのエラー内容、返されたJSON、元の問題作成Prompt、正しいPlan / Task IDを含みます。</p>
                                </details>
                            </div>
                        @endif
                    </form>
                </section>

                @if ($nativeAiAvailable)
                        </div>
                    </details>
                @endif
            @endif
        @endif

        @if ($assessment)
            <section id="practice-assessment" class="page-card scroll-mt-24 border-emerald-300/20 p-5 sm:p-6">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-300">ASSESSMENT / NEXT STEP</p>

                @if ($nextStep)
                    @php
                        $progressionKind = (string) data_get($studyProgression ?? [], 'kind', '');
                        $progressionLabel = trim((string) data_get($studyProgression ?? [], 'label', ''));
                        $progressionReason = trim((string) data_get($studyProgression ?? [], 'reason', ''));
                        $nextTask = data_get($studyProgression ?? [], 'next_task');
                        $displayNextLabel = in_array($progressionKind, ['verify_mastery', 'advance_task', 'plan_complete'], true) && $progressionLabel !== ''
                            ? $progressionLabel
                            : $nextStep['label'];
                        $displayNextReason = in_array($progressionKind, ['verify_mastery', 'advance_task', 'plan_complete'], true) && $progressionReason !== ''
                            ? $progressionReason
                            : $nextStep['reason'];
                    @endphp
                    <div class="mt-3 rounded-2xl border border-cyan-300/25 bg-cyan-300/[0.055] p-4 sm:p-5">
                        <p class="text-[11px] font-black uppercase tracking-[0.14em] text-cyan-300">NEXT ACTION</p>
                        <h2 class="mt-2 text-xl font-black leading-8 text-slate-50">{{ $displayNextLabel }}</h2>
                        @if ($displayNextReason)
                            <p class="mt-2 text-sm leading-6 text-slate-300">{{ $displayNextReason }}</p>
                        @endif
                        @if ($progressionKind === 'verify_mastery')
                            <p class="mt-3 text-xs leading-5 text-cyan-100/80">1回の高得点だけでは完了にせず、別の問題でも理解が安定しているかCanoviaが確認します。</p>
                        @else
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (($nextStep['focus_topics'] ?? []) as $topic)
                                    <span class="badge badge-slate">{{ $topic }}</span>
                                @endforeach
                                @if (($nextStep['kind'] ?? '') === 'practice' && ! empty($nextStep['question_count']))
                                    <span class="badge badge-slate">{{ $nextStep['question_count'] }}問</span>
                                @endif
                            </div>
                        @endif

                        <div class="mt-4">
                            @if ($currentAttempt && ! $currentAttempt->applied_at)
                                <form method="POST" action="{{ route('plans.tasks.study_practice.apply', [$plan, $task]) }}" data-mutation-once>
                                    @csrf
                                    <input type="hidden" name="attempt_id" value="{{ $currentAttempt->id }}">
                                    <input type="hidden" name="request_hash" value="{{ $currentAttempt->request_hash }}">
                                    <input type="hidden" name="continue_after_apply" value="1">
                                    <button type="submit" class="btn-primary">
                                        {{ $progressionKind === 'verify_mastery' ? '結果を反映して仕上げ確認へ' : ($progressionKind === 'advance_task' ? '結果を反映して次のTaskへ' : '結果を反映して次へ') }}
                                    </button>
                                </form>
                            @elseif ($currentAttempt?->applied_at && $progressionKind === 'verify_mastery')
                                <form method="POST" action="{{ route('plans.tasks.study_practice.reset', [$plan, $task]) }}">
                                    @csrf
                                    <input type="hidden" name="continue" value="1">
                                    <button type="submit" class="btn-primary">仕上げ確認を始める</button>
                                </form>
                            @elseif ($currentAttempt?->applied_at && $progressionKind === 'advance_task' && $nextTask)
                                <a href="{{ route('plans.tasks.study_practice.show', [$plan, $nextTask]) }}" class="btn-primary">次のTask「{{ $nextTask->title }}」へ</a>
                            @elseif ($currentAttempt?->applied_at && $progressionKind === 'plan_complete')
                                <a href="{{ route('plans.show', $plan) }}" class="btn-primary">Plan全体を確認する</a>
                            @elseif ($currentAttempt?->applied_at && ($nextStep['kind'] ?? '') === 'practice')
                                <form method="POST" action="{{ route('plans.tasks.study_practice.reset', [$plan, $task]) }}">
                                    @csrf
                                    <input type="hidden" name="continue" value="1">
                                    <button type="submit" class="btn-primary">この内容で次の演習へ</button>
                                </form>
                            @elseif ($currentAttempt?->applied_at && ($nextStep['kind'] ?? '') === 'plan_update')
                                <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-primary">学習結果をもとに計画を見直す</a>
                            @elseif ($currentAttempt?->applied_at)
                                <a href="{{ route('plans.show', $plan) }}" class="btn-primary">Taskへ戻って次の行動へ</a>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap items-end gap-4">
                    <div><p class="text-xs text-slate-500">今回の評価</p><strong class="text-4xl text-slate-50">{{ $assessment['score_percent'] }}%</strong></div>
                    <div>
                        <p class="text-xs text-slate-500">{{ ($currentPracticeSession?->assessment_provider ?? '') === 'question_bank_grader' ? 'Task進捗（自動変更なし）' : '評価提案のTask進捗' }}</p>
                        <strong class="text-2xl text-cyan-200">{{ $assessment['recommended_task_progress_percent'] }}%</strong>
                    </div>
                    @if ($currentAttempt?->applied_at)
                        <span class="badge badge-green">Taskへ反映済み</span>
                    @else
                        <span class="badge badge-slate">確認待ち</span>
                    @endif
                </div>

                <details class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/20 p-4">
                    <summary class="cursor-pointer text-sm font-black text-slate-200">詳しい評価を確認</summary>
                    <div class="mt-4">
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div class="rounded-2xl border border-emerald-300/15 bg-emerald-300/[0.04] p-4">
                                <h3 class="font-bold text-emerald-100">理解できている点</h3>
                                <ul class="mt-2 space-y-2 text-sm text-slate-300">@forelse($assessment['strengths'] as $item)<li>・{{ $item }}</li>@empty<li class="text-slate-500">記載なし</li>@endforelse</ul>
                            </div>
                            <div class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4">
                                <h3 class="font-bold text-amber-100">補強する点</h3>
                                <ul class="mt-2 space-y-2 text-sm text-slate-300">@forelse($assessment['weaknesses'] as $item)<li>・{{ $item }}</li>@empty<li class="text-slate-500">記載なし</li>@endforelse</ul>
                            </div>
                        </div>
                        @if (collect($assessment['question_feedback'] ?? [])->isNotEmpty())
                            <div class="mt-5 space-y-3">
                                <h3 class="text-sm font-black text-slate-100">問題ごとのフィードバック</h3>
                                @foreach ($assessment['question_feedback'] as $feedback)
                                    @php
                                        $correctnessLabel = match ($feedback['correctness'] ?? 'ungraded') {
                                            'correct' => '正解',
                                            'partial' => '一部正解',
                                            'incorrect' => '要復習',
                                            default => '評価対象外',
                                        };
                                        $correctnessClass = match ($feedback['correctness'] ?? 'ungraded') {
                                            'correct' => 'badge-green',
                                            'partial' => 'badge-slate',
                                            'incorrect' => 'badge-amber',
                                            default => 'badge-slate',
                                        };
                                        $errorTypeLabel = match ($feedback['error_type'] ?? 'none') {
                                            'knowledge_gap' => '知識不足',
                                            'concept_gap' => '概念理解',
                                            'reasoning_gap' => '推論',
                                            'condition_reading' => '条件読解',
                                            'unit_error' => '単位ミス',
                                            'calculation_slip' => '計算ミス',
                                            'careless' => 'ケアレス',
                                            'unknown' => '原因未確定',
                                            default => null,
                                        };
                                    @endphp
                                    <article class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <strong class="text-sm text-slate-100">{{ $feedback['question_id'] }}</strong>
                                            <span class="badge {{ $correctnessClass }}">{{ $correctnessLabel }}</span>
                                            @if ($errorTypeLabel)
                                                <span class="badge badge-slate">{{ $errorTypeLabel }}</span>
                                            @endif
                                        </div>
                                        @if ($feedback['feedback'])
                                            <p class="mt-2 text-sm leading-6 text-slate-300">{{ $feedback['feedback'] }}</p>
                                        @endif
                                        @if ($feedback['reasoning_feedback'])
                                            <div class="mt-2 rounded-xl border border-violet-300/15 bg-violet-300/[0.04] p-3">
                                                <p class="text-[11px] font-bold text-violet-200">思考過程フィードバック</p>
                                                <p class="mt-1 text-xs leading-5 text-slate-300">{{ $feedback['reasoning_feedback'] }}</p>
                                            </div>
                                        @endif
                                        @if (collect($feedback['weakness_topics'] ?? [])->isNotEmpty())
                                            <p class="mt-2 text-xs leading-5 text-slate-400">弱点候補：{{ collect($feedback['weakness_topics'])->implode(' / ') }}</p>
                                        @endif
                                        @if (collect($feedback['misconceptions'] ?? [])->isNotEmpty())
                                            <p class="mt-2 text-xs leading-5 text-amber-100">誤解ポイント：{{ collect($feedback['misconceptions'])->implode(' / ') }}</p>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endif

                        @if ($assessment['evidence_summary'])
                            <div class="mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-4"><p class="text-xs font-bold text-slate-500">評価根拠</p><p class="mt-1 text-sm leading-6 text-slate-300">{{ $assessment['evidence_summary'] }}</p></div>
                        @endif

                    </div>
                </details>
                @if ($currentAttempt?->applied_at)
                    <div class="mt-4 rounded-xl border border-emerald-300/15 bg-emerald-300/[0.04] p-4">
                        <p class="text-sm font-bold text-emerald-100">Taskへ反映済み</p>
                        <p class="mt-1 text-xs text-slate-400">進捗 {{ $currentAttempt->progress_before_percent ?? '—' }}% → {{ $currentAttempt->progress_after_percent ?? '—' }}%。AI演習だけを理由に、既存の進捗を下げることはありません。</p>
                    </div>
                @endif
            </section>
        @endif

        @if ($questions)
            @if (($practiceStage ?? 'answering') === 'result')
                <details id="practice-questions" class="page-card scroll-mt-24 p-4 sm:p-5">
                    <summary class="cursor-pointer list-none text-sm font-black text-slate-200">
                        回答済み {{ count($questions) }}問 · 今回の回答を見直す
                    </summary>
                    <div class="mt-4 space-y-3">
                        @php $questionReviewMap = collect($questions)->keyBy('id'); @endphp
                        @foreach (($answers ?? []) as $answer)
                            @php $reviewQuestion = $questionReviewMap->get($answer['question_id'] ?? ''); @endphp
                            <article class="rounded-2xl border border-slate-800 bg-slate-950/30 p-4">
                                <p class="text-sm font-bold leading-6 text-slate-100">{{ data_get($reviewQuestion, 'prompt', $answer['question_id'] ?? '問題') }}</p>
                                <div class="mt-3 space-y-2">
                                    @foreach (($answer['fields'] ?? []) as $field)
                                        @php
                                            $reviewValue = $field['value'] ?? '';
                                            $reviewText = is_array($reviewValue) ? implode(', ', $reviewValue) : (string) $reviewValue;
                                        @endphp
                                        <div class="rounded-xl border border-slate-800/80 bg-slate-950/40 p-3">
                                            <p class="text-[10px] font-bold text-slate-500">{{ $field['label'] ?? $field['field_id'] ?? '回答' }}</p>
                                            <p class="mt-1 whitespace-pre-wrap text-xs leading-5 text-slate-300">{{ $reviewText !== '' ? $reviewText : '未入力' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </details>
            @else
                <details id="practice-questions" class="page-card scroll-mt-24 p-3 sm:p-5" @if (($practiceStage ?? 'answering') === 'answering') open @endif>
                    <summary class="cursor-pointer list-none {{ ($practiceStage ?? 'answering') === 'answering' ? 'hidden' : '' }}">
                        <span class="text-sm font-black text-slate-200">回答済み {{ count($questions) }}問 · 回答を見直す</span>
                    </summary>
                    <div class="{{ ($practiceStage ?? 'answering') === 'answering' ? '' : 'mt-4' }}">
                <div class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-cyan-300/10 text-sm font-black text-cyan-200">{{ ($resumeFastPath ?? false) ? '↻' : '2' }}</span>
                    <div>
                        <h2 class="font-black text-slate-100">{{ ($resumeFastPath ?? false) ? '続きから回答' : ($exerciseTitle ?: '演習に回答') }}</h2>
                        <p class="text-xs text-slate-500">
                            @if ($resumeFastPath ?? false)
                                保存済みの回答を復元しています。未回答の問題からそのまま続けられます。
                            @else
                                {{ count($questions) }}問。
                                {{ ($currentPracticeSession?->question_provider ?? '') === 'question_bank' ? '機械採点できる問題はCanoviaがその場で採点します。' : '回答は評価用プロンプトへまとめられます。' }}
                            @endif
                        </p>
                    </div>
                </div>

                <form
                    method="POST"
                    action="{{ route('plans.tasks.study_practice.answers', [$plan, $task]) }}"
                    class="mt-3 space-y-3"
                    @if ($currentPracticeSession && in_array($currentPracticeSession->status, ['ready', 'in_progress'], true))
                        data-study-practice-draft-form
                        data-draft-url="{{ route('plans.tasks.study_practice.draft', [$plan, $task]) }}"
                        data-draft-session-id="{{ $currentPracticeSession->id }}"
                        data-draft-session-token="{{ $currentPracticeSession->session_token }}"
                        data-study-practice-one-question
                        data-draft-saved-at="{{ $currentPracticeSession->draft_saved_at?->toIso8601String() }}"
                    @endif
                >
                    @csrf
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-cyan-300/20 bg-cyan-300/[0.035] px-3 py-2" data-study-practice-pager hidden style="display: none">
                        <p class="text-sm font-bold text-cyan-200" aria-live="polite" data-study-practice-question-progress></p>
                        <p class="hidden text-xs text-slate-400 sm:block">入力は自動保存。採点は最後にまとめて行います。</p>
                    </div>
                    @foreach ($questions as $index => $question)
                        <fieldset class="min-w-0 rounded-2xl border border-white/8 bg-white/[0.025] p-3 scroll-mt-28 sm:p-4" tabindex="-1" data-study-practice-question>
                            <legend class="px-1 text-sm font-black text-slate-100">Q{{ $index + 1 }}</legend>
                            <p class="canovia-study-question mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-200 sm:leading-7">{{ $question['prompt'] }}</p>
                            @if (! empty($question['source_reference']))
                                <p class="mt-2 text-[10px] leading-4 text-slate-600">出典：{{ $question['source_reference'] }}</p>
                            @endif

                            <div class="mt-3 space-y-3">
                                @foreach (($question['response_fields'] ?? []) as $field)
                                    @php
                                        $fieldName = 'answers['.$question['id'].']['.$field['id'].']';
                                        $fieldError = 'answers.'.$question['id'].'.'.$field['id'];
                                        $fieldValue = old(
                                            $fieldError,
                                            data_get($draftAnswers ?? [], $question['id'].'.'.$field['id'])
                                        );
                                        $fieldValues = is_array($fieldValue)
                                            ? array_map('strval', $fieldValue)
                                            : [];
                                    @endphp
                                    @if ($field['type'] === 'textarea' && ! ($field['required'] ?? true))
                                        <details class="rounded-xl border border-slate-800/80 bg-slate-950/30 p-3" data-practice-required-field="false" data-study-practice-optional-note @if(filled($fieldValue) || $errors->has($fieldError)) open @endif>
                                            <summary class="cursor-pointer text-sm font-semibold text-slate-300">
                                                {{ $field['label'] }} <span class="ml-1 text-xs font-normal text-slate-500">任意 · 開いて入力</span>
                                            </summary>
                                            <textarea name="{{ $fieldName }}" aria-label="{{ $field['label'] }}" class="form-control mt-3 min-h-[96px] w-full resize-y text-base" rows="3" placeholder="{{ ($field['placeholder'] ?? '') ?: '必要なら考え方を入力' }}">{{ $fieldValue }}</textarea>
                                            @error($fieldError)<p class="mt-2 text-sm font-semibold text-rose-300" data-practice-answer-error>{{ $message }}</p>@enderror
                                        </details>
                                    @else
                                    <div class="rounded-xl border border-slate-800/80 bg-slate-950/30 p-2.5 sm:p-3" data-practice-required-field="{{ ($field['required'] ?? true) ? 'true' : 'false' }}">
                                        <label class="text-xs font-bold text-slate-300">
                                            {{ $field['label'] }}
                                            <span class="ml-1 text-[10px] {{ ($field['required'] ?? true) ? 'text-cyan-300' : 'text-slate-600' }}">
                                                {{ ($field['required'] ?? true) ? '必須' : '任意' }}
                                            </span>
                                        </label>

                                        @if ($field['type'] === 'single_choice')
                                            <div class="mt-2 grid gap-1.5 lg:grid-cols-2">
                                                @foreach ($field['choices'] as $choice)
                                                    <label class="flex min-h-11 min-w-0 cursor-pointer items-center gap-3 rounded-lg border border-slate-800 bg-slate-950/40 px-3 py-2 text-sm text-slate-300">
                                                        <input type="radio" name="{{ $fieldName }}" value="{{ $choice['id'] }}" class="shrink-0" @checked((string) $fieldValue === (string) $choice['id'])>
                                                        <span class="min-w-0 break-words"><strong class="text-slate-100">{{ $choice['id'] }}</strong> {{ $choice['label'] }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @elseif ($field['type'] === 'multiple_choice')
                                            <div class="mt-2 grid gap-1.5 lg:grid-cols-2">
                                                @foreach ($field['choices'] as $choice)
                                                    <label class="flex min-h-11 min-w-0 cursor-pointer items-center gap-3 rounded-lg border border-slate-800 bg-slate-950/40 px-3 py-2 text-sm text-slate-300">
                                                        <input type="checkbox" name="{{ $fieldName }}[]" value="{{ $choice['id'] }}" class="shrink-0" @checked(in_array((string) $choice['id'], $fieldValues, true))>
                                                        <span class="min-w-0 break-words"><strong class="text-slate-100">{{ $choice['id'] }}</strong> {{ $choice['label'] }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @elseif ($field['type'] === 'number')
                                            <input type="number" step="any" name="{{ $fieldName }}" value="{{ $fieldValue }}" class="form-control mt-2" placeholder="{{ ($field['placeholder'] ?? '') ?: '数値を入力' }}">
                                        @elseif ($field['type'] === 'short_text')
                                            <input type="text" name="{{ $fieldName }}" value="{{ $fieldValue }}" class="form-control mt-2" placeholder="{{ ($field['placeholder'] ?? '') ?: '短く回答' }}">
                                        @else
                                            <textarea name="{{ $fieldName }}" class="form-control mt-2 min-h-[112px] w-full resize-y text-base sm:min-h-[128px]" rows="4" placeholder="{{ ($field['placeholder'] ?? '') ?: '回答・考え方を入力' }}">{{ $fieldValue }}</textarea>
                                        @endif

                                        @error($fieldError)<p class="mt-2 text-sm font-semibold text-rose-300" data-practice-answer-error>{{ $message }}</p>@enderror
                                    </div>
                                    @endif
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    <div class="sticky bottom-0 z-20 flex flex-wrap items-center justify-end gap-2 rounded-xl border border-slate-700/80 bg-slate-950/95 p-2 shadow-lg" data-study-practice-actions>
                        <div class="flex min-w-0 flex-1 items-center justify-between gap-2" data-study-practice-pager hidden style="display: none">
                            <button type="button" class="btn-secondary min-h-11" data-study-practice-previous>← 前の問題</button>
                            <button type="button" class="btn-primary ml-auto min-h-11" data-study-practice-next>次の問題 →</button>
                        </div>
                        <button type="submit" class="btn-primary ml-auto min-h-11" data-study-practice-final-submit>回答をまとめて評価へ進む</button>
                    </div>
                </form>
                    </div>
                </details>
            @endif
        @endif

        @if ($evaluationPrompt)
            <details id="practice-evaluation" class="page-card scroll-mt-24 p-5 sm:p-6" @if (($practiceStage ?? 'evaluation') === 'evaluation') open @endif>
                <summary class="cursor-pointer list-none {{ ($practiceStage ?? 'evaluation') === 'evaluation' ? 'hidden' : '' }}">
                    <span class="text-sm font-black text-slate-200">AI評価の受け渡しを確認する</span>
                </summary>
                <div class="{{ ($practiceStage ?? 'evaluation') === 'evaluation' ? '' : 'mt-4' }}">
                <div class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-violet-300/10 text-sm font-black text-violet-200">3</span>
                    <div>
                        <h2 class="font-black text-slate-100">AIに採点・評価してもらう</h2>
                        <p class="text-xs text-slate-500">問題とあなたの回答はCanoviaが評価依頼へまとめています。原文を読む必要はありません。</p>
                    </div>
                </div>

                <textarea id="studyPracticeEvaluationPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $evaluationPrompt }}</textarea>

                <div class="mt-4 rounded-2xl border border-violet-300/15 bg-violet-300/[0.035] p-4">
                    <button type="button" class="btn-primary" data-copy-target="#studyPracticeEvaluationPrompt">評価プロンプトをコピー</button>
                    <details class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <summary class="cursor-pointer text-xs font-semibold text-slate-300">この評価依頼に含まれる情報</summary>
                        <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-500">
                            <li>・今回出題された問題と回答内容</li>
                            <li>・選択回答とは別に、入力した計算過程・判断理由</li>
                            <li>・Question Bank問題では正答Rule・解説・学習メタデータ</li>
                            <li>・問題ごとの正誤、弱点、次Actionと構造化next_stepを返す評価形式</li>
                            <li>・正しいPlan / Task IDと進捗反映の安全条件</li>
                        </ul>
                    </details>
                </div>

                <form method="POST" action="{{ route('plans.tasks.study_practice.assessment', [$plan, $task]) }}" class="mt-5 border-t border-slate-800 pt-5">
                    @csrf

                    <p class="text-sm font-semibold text-slate-100">AIの評価をCanoviaへ戻す</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">AI側で評価JSONをコピーしてから、下のボタンを押してください。</p>

                    <button
                        type="button"
                        class="btn-primary mt-3"
                        data-paste-target="#assessment_json"
                        data-paste-submit="1"
                        data-paste-fallback="#assessmentJsonManualInput"
                        data-paste-status="#assessmentJsonPasteStatus"
                    >クリップボードから貼り付けて確認</button>
                    <p id="assessmentJsonPasteStatus" class="mt-2 hidden text-xs leading-5 text-slate-400" aria-live="polite"></p>
                    @error('assessment_json')<p class="mt-2 text-sm font-semibold text-rose-300">{{ $message }}</p>@enderror

                    <details id="assessmentJsonManualInput" class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3" @if($assessmentJsonError || old('assessment_json')) open @endif>
                        <summary class="cursor-pointer text-xs font-semibold text-slate-300">手動で貼り付ける / 評価JSONを確認する</summary>
                        <textarea id="assessment_json" name="assessment_json" class="form-control mt-3 min-h-[180px] font-mono text-xs" placeholder="評価JSONを貼り付け">{{ old('assessment_json') }}</textarea>
                        <button type="submit" class="btn-secondary mt-3">この評価JSONを確認する</button>
                    </details>

                    @if ($assessmentJsonRepairPrompt)
                        <div class="mt-4 rounded-2xl border border-rose-300/20 bg-rose-300/[0.04] p-4">
                            <p class="text-sm font-black text-rose-100">評価JSONの修正依頼を作りました</p>
                            <p class="mt-1 text-xs leading-5 text-slate-400">評価を作ったAIへ修正依頼を送り、返ってきたJSONをもう一度貼り付けてください。</p>
                            <textarea id="studyPracticeAssessmentRepairPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $assessmentJsonRepairPrompt }}</textarea>
                            <button type="button" class="btn-primary mt-3" data-copy-target="#studyPracticeAssessmentRepairPrompt">修正依頼をコピー</button>
                            <details class="ai-handoff-details mt-3 rounded-xl border border-rose-300/10 bg-slate-950/25 p-3">
                                <summary class="cursor-pointer text-xs font-semibold text-rose-100/80">修正依頼に含まれる情報</summary>
                                <p class="mt-2 text-xs leading-5 text-slate-500">Canoviaのエラー内容、評価JSON、元の評価Prompt、正しいPlan / Task IDを含みます。</p>
                            </details>
                        </div>
                    @endif
                </form>
                </div>
            </details>
        @endif

        @if (($recentAttempts ?? collect())->isNotEmpty())
            <details class="page-card p-4 sm:p-5" data-study-practice-history>
                <summary class="cursor-pointer text-sm font-semibold text-slate-200">学習履歴を振り返る <span class="ml-1 text-xs font-normal text-slate-400">（{{ $recentAttempts->count() }}件）</span></summary>
                <p class="mt-3 text-xs text-slate-400">ここで見つかった弱点は、次回の問題生成Promptへ自動で引き継がれます。</p>
                <div class="mt-3 space-y-3">
                    @foreach ($recentAttempts as $attempt)
                        <article class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <strong class="text-slate-100">{{ $attempt->exercise_title ?: 'AI演習' }}</strong>
                                    <p class="mt-1 text-xs text-slate-500">{{ $attempt->created_at?->format('Y-m-d H:i') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="badge badge-slate">score {{ $attempt->score_percent }}%</span>
                                    <span class="badge {{ $attempt->applied_at ? 'badge-green' : 'badge-slate' }}">{{ $attempt->applied_at ? '反映済み' : '未反映' }}</span>
                                </div>
                            </div>
                            @if (collect($attempt->weaknesses ?? [])->isNotEmpty())
                                <p class="mt-3 text-xs leading-5 text-amber-100">弱点：{{ collect($attempt->weaknesses)->implode(' / ') }}</p>
                            @endif
                            @if ($attempt->next_action)
                                <p class="mt-2 text-xs leading-5 text-cyan-100">次：{{ $attempt->next_action }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endsection
