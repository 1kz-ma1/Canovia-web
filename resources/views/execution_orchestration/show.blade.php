@extends('layouts.app')

@section('title', 'Execution Packet | Canovia')

@section('content')
    @php
        $dependencyState = (string) ($context['dependency_state'] ?? 'ready');
        $taskComplete = $task->status === 'done' || (int) $task->progress_percent >= 100;
        $modeLabels = [
            'execute' => '実行',
            'prepare' => '先行準備',
            'coordinate' => '連携・調整',
            'validate' => '検証',
            'clarify' => '確認が必要',
            'wait' => '待機',
        ];
        $packetJson = $packet
            ? json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : '';
    @endphp

    <div class="mx-auto max-w-6xl space-y-5 pb-28 md:pb-0" data-execution-orchestration-root>
        <header class="page-card border-violet-300/20 p-5 sm:p-6 plan-identity-shell" data-plan-accent="{{ $plan->accentKey() }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-violet-300">EXECUTION ORCHESTRATION</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50">{{ $task->title }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-300">{{ $plan->displayIcon() }} {{ $plan->title }}</p>
                    <p class="mt-3 text-xs leading-5 text-slate-500">
                        Taskを増やす機能ではありません。Plan全体・Dependency・Evidence・制約を見て、このTaskを今どう進めるかだけをExecution Packetとして組み立てます。
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('plans.execution_distribution.show', $plan) }}" class="btn-secondary px-3 py-2 text-xs">複数担当へ分配</a>
                    <span class="badge {{ $taskComplete || $dependencyState === 'ready' ? 'badge-green' : 'badge-slate' }}">
                        {{ $taskComplete ? '完了済み' : ($dependencyState === 'ready' ? '実行可能' : '前提待ち') }}
                    </span>
                </div>
            </div>
        </header>

        @if (session('success'))
            <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div>
        @endif
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">入力内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($packetStale)
            <div class="assistant-notice assistant-notice-info">
                このPacket生成後にTask / Dependency / Evidenceなどの状態が変わっています。下のPacketは履歴として読めますが、実行前に再生成してください。
            </div>
        @endif

        @if ($executionRequest)
            <section class="page-card border-emerald-300/15 p-4 sm:p-5" data-execution-request>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">CONFIRMED EXECUTION REQUEST</p>
                        <h2 class="mt-1 text-base font-black text-slate-100">{{ data_get($executionRequest, 'source.type') === 'companion_candidate' ? 'Companionで確認した依頼を引き継いでいます' : 'Inboxで確認した依頼を引き継いでいます' }}</h2>
                        <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-300">{{ data_get($executionRequest, 'instruction') }}</p>
                        <p class="mt-2 text-[10px] leading-4 text-slate-600">
                            元: {{ data_get($executionRequest, 'source.title', 'Inbox Item') }}
                            · 対象: {{ data_get($executionRequest, 'target_task.title', $task->title) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-green">人が確認済み</span>
                        <span class="badge badge-slate">{{ $actorTypes[data_get($executionRequest, 'actor_type', 'human_ai')] ?? '人 + AI' }}</span>
                        @if (data_get($executionRequest, 'available_minutes'))
                            <span class="badge badge-slate">{{ (int) data_get($executionRequest, 'available_minutes') }}分</span>
                        @endif
                    </div>
                </div>
                <p class="mt-3 text-[10px] leading-4 text-emerald-100/60">この依頼は目的を伝えるContextです。Dependency・protected scope・確認済み事実を上書きせず、現在のPlan状態から安全なExecution Packetを組み立てます。</p>
            </section>
        @endif

        <section class="grid gap-4 lg:grid-cols-[1.15fr_.85fr]">
            <article class="page-card p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">CURRENT STATE</p>
                        <h2 class="mt-1 text-xl font-black text-slate-100">
                            {{ $taskComplete ? 'このTaskは完了しています' : ($dependencyState === 'ready' ? 'このTaskは開始できます' : '本作業はDependency待ちです') }}
                        </h2>
                    </div>
                    <span class="badge {{ $dependencyState === 'ready' ? 'badge-green' : 'badge-slate' }}">
                        {{ count($context['blockers'] ?? []) }} blockers
                    </span>
                </div>

                @if (! empty($context['dependencies']))
                    <div class="mt-5 space-y-2">
                        @foreach ($context['dependencies'] as $dependency)
                            <div class="flex items-start justify-between gap-3 rounded-xl border border-white/8 bg-white/[0.025] p-3">
                                <div>
                                    <p class="text-sm font-bold text-slate-200">{{ $dependency['title'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">進捗 {{ $dependency['progress_percent'] }}% · {{ $dependency['status'] }}</p>
                                </div>
                                <span class="badge {{ $dependency['satisfied'] ? 'badge-green' : 'badge-slate' }}">
                                    {{ $dependency['satisfied'] ? '完了' : '待ち' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="mt-4 text-sm leading-6 text-slate-400">登録されている前提Taskはありません。</p>
                @endif

                @if ($dependencyState === 'blocked')
                    <div class="mt-4 rounded-2xl border border-amber-300/15 bg-amber-300/[0.035] p-4">
                        <p class="text-sm font-bold text-amber-100">待つだけとは限りません</p>
                        <p class="mt-1 text-xs leading-5 text-slate-400">Canoviaは未完成の成果を存在すると仮定せず、現在確認できる入力だけで安全に先行できる準備があるかを判断します。</p>
                    </div>
                @endif
            </article>

            <article class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">GENERATE</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">今この担当へ渡す指示を作る</h2>

                @if ($taskComplete)
                    <div class="mt-5 rounded-2xl border border-emerald-300/12 bg-emerald-300/[0.025] p-4">
                        <p class="text-sm font-bold text-emerald-100">このTaskへの新しい指示生成は停止しています</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">
                            完了済みTaskへ新しいPacketを作らず、下のExecution Coordinationからreadyになった後続Taskやstaleな担当指示を確認してください。
                        </p>
                    </div>
                @else
                    <form method="POST" action="{{ route('plans.tasks.execution_orchestration.prepare', [$plan, $task]) }}" class="mt-5 space-y-4">
                        @csrf
                        <div>
                            <label class="text-xs font-bold text-slate-300">実行主体</label>
                        <select name="actor_type" class="form-control mt-2">
                            @foreach ($actorTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('actor_type', $actorType) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-300">今回使える時間 <span class="text-slate-600">任意</span></label>
                        <input type="number" min="5" max="1440" name="available_minutes" value="{{ old('available_minutes', $availableMinutes) }}" class="form-control mt-2" placeholder="例: 30">
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @if ($nativeAiAvailable)
                            <button type="submit" name="generation_mode" value="native" class="btn-primary w-full justify-center">✦ Canoviaで生成</button>
                        @else
                            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3 text-xs leading-5 text-slate-500">
                                @if (! $nativeAiEntitled)
                                    Canovia内の自動生成はPremium境界です。外部AIへの共有はそのまま使えます。
                                @elseif (! $nativeAiConfigured)
                                    Native AI設定がないため、外部AI経路を利用します。
                                @endif
                            </div>
                        @endif
                            <button type="submit" name="generation_mode" value="external" class="btn-secondary w-full justify-center">外部AI用Promptを準備</button>
                        </div>
                    </form>
                @endif
            </article>
        </section>

        @if ($handoffPrompt !== '')
            <section class="page-card border-cyan-300/15 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">EXTERNAL AI HANDOFF</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">全体Context込みのPromptを準備しました</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">普段使っているChatGPT / Claude / Gemini / Codex等へそのまま渡し、返ってきたJSONを下へ貼ってください。</p>

                <textarea id="executionPacketPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $handoffPrompt }}</textarea>
                <button type="button" class="btn-primary mt-4" data-copy-target="#executionPacketPrompt">Promptをコピー</button>

                <details class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                    <summary class="cursor-pointer text-xs font-semibold text-slate-300">Promptに含まれるContext</summary>
                    <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-500">
                        <li>・Plan / 選択Task / 全Taskの現在状態</li>
                        <li>・Dependency / Blocker / Dependents</li>
                        <li>・Resource / Artifact / 最近のEvidence・実績</li>
                        <li>・confirmed constraint / known unknown / protected scope</li>
                        @if ($executionRequest)
                            <li>・{{ data_get($executionRequest, 'source.type') === 'companion_candidate' ? 'Companion' : 'Inbox' }}で人が確認したExecution Request</li>
                        @endif
                    </ul>
                </details>

                <form method="POST" action="{{ route('plans.tasks.execution_orchestration.import', [$plan, $task]) }}" class="mt-5">
                    @csrf
                    <label class="text-xs font-bold text-slate-300">AIから返ったExecution Packet JSON</label>
                    <textarea name="packet_json" rows="10" required class="form-control mt-2 font-mono text-xs" placeholder='{"schema_version":"1.0","flow":"execution_packet",...}'>{{ old('packet_json') }}</textarea>
                    <button type="submit" class="btn-secondary mt-3">Packetとして読み込む</button>
                </form>
            </section>
        @endif

        @if ($packet)
            <section class="page-card border-violet-300/20 p-5 sm:p-6" data-execution-packet>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">EXECUTION PACKET</p>
                        <h2 class="mt-1 text-xl font-black text-slate-100">{{ $packet['summary'] ?: $task->title }}</h2>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="badge badge-slate">{{ $modeLabels[$packet['execution_mode']] ?? $packet['execution_mode'] }}</span>
                        <span class="badge {{ $packet['dependency_state'] === 'ready' ? 'badge-green' : 'badge-slate' }}">
                            {{ $packet['dependency_state'] === 'ready' ? 'ready' : 'blocked' }}
                        </span>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <article class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                        <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">CURRENT SITUATION</p>
                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $packet['current_situation'] }}</p>
                    </article>
                    <article class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                        <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">ROLE / OBJECTIVE</p>
                        <p class="mt-2 text-sm font-bold text-slate-200">{{ $packet['role'] }}</p>
                        <p class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-400">{{ $packet['objective'] }}</p>
                        <p class="mt-3 text-xs leading-5 text-slate-500">{{ $packet['reason'] }}</p>
                    </article>
                </div>

                <div class="mt-5">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-emerald-300">NOW</p>
                    <div class="mt-2 space-y-3">
                        @forelse ($packet['actions'] as $action)
                            <article class="rounded-2xl border border-emerald-300/12 bg-emerald-300/[0.025] p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 class="text-sm font-black text-slate-100">{{ $action['title'] }}</h3>
                                    @if ($action['estimated_minutes'] > 0)
                                        <span class="badge badge-slate">約{{ $action['estimated_minutes'] }}分</span>
                                    @endif
                                </div>
                                <p class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-400">{{ $action['details'] }}</p>
                            </article>
                        @empty
                            <p class="text-sm text-slate-500">今すぐ安全に実行できるActionはありません。</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ([
                        'inputs' => ['INPUT', '使ってよい入力'],
                        'outputs' => ['OUTPUT', '今回の成果'],
                        'dependencies' => ['DEPENDENCY', '依存の扱い'],
                        'do_not_touch' => ['DO NOT TOUCH', '今回触らない'],
                        'completion_criteria' => ['DONE WHEN', '完了条件'],
                        'confirmation_required' => ['CONFIRM', '確認が必要'],
                    ] as $key => [$eyebrow, $title])
                        <article class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">{{ $eyebrow }}</p>
                            <h3 class="mt-1 text-sm font-black text-slate-200">{{ $title }}</h3>
                            <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-400">
                                @forelse ($packet[$key] as $item)
                                    <li>• {{ $item }}</li>
                                @empty
                                    <li class="text-slate-600">なし</li>
                                @endforelse
                            </ul>
                        </article>
                    @endforeach
                </div>

                @if (! empty($packet['assumptions']))
                    <div class="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.025] p-4">
                        <p class="text-xs font-bold text-amber-100">Current assumptions</p>
                        <ul class="mt-2 space-y-1 text-xs text-slate-400">
                            @foreach ($packet['assumptions'] as $item)
                                <li>• {{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-5 rounded-xl border border-cyan-300/12 bg-cyan-300/[0.025] p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">NEXT PHASE</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">{{ $packet['next_phase'] ?: 'このPacketの結果をCanoviaへ戻し、Dependencyと次Actionを再評価します。' }}</p>
                </div>

                <textarea id="executionPacketJson" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $packetJson }}</textarea>
                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="button" class="btn-primary" data-copy-target="#executionPacketJson">Packetをコピー</button>
                    <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-secondary">結果をPlan / Taskへ反映</a>
                    <a href="{{ route('plans.artifacts.index', $plan) }}" class="btn-secondary">成果物を登録</a>
                    <a href="{{ route('tasks.edit', $task) }}" class="btn-secondary">Dependencyを編集</a>
                </div>
            </section>
        @endif

        @include('execution_orchestration.partials.github_handoff')

        @include('execution_orchestration.partials.coordination')

        @if ($packet || $handoffPrompt !== '')
            <form method="POST" action="{{ route('plans.tasks.execution_orchestration.reset', [$plan, $task]) }}">
                @csrf
                <button type="submit" class="text-xs font-semibold text-slate-600 underline underline-offset-4">生成内容をリセット</button>
            </form>
        @endif
    </div>
@endsection
