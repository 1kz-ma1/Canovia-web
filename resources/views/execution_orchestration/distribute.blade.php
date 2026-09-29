@extends('layouts.app')

@section('title', 'Execution Distribution | Canovia')

@section('content')
    @php
        $bundleTargets = collect($bundle['targets'] ?? [])->keyBy(fn ($target) => (int) data_get($target, 'task_id'));
        $actorTypeLabels = $actorTypes;
    @endphp

    <div class="mx-auto max-w-6xl space-y-5 pb-28 md:pb-0" data-execution-distribution-root>
        <header class="page-card border-violet-300/20 p-5 sm:p-6 plan-identity-shell" data-plan-accent="{{ $plan->accentKey() }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-violet-300">EXECUTION DISTRIBUTION</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50">全体Contextを保ったまま、担当ごとに指示を分ける</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-300">{{ $plan->displayIcon() }} {{ $plan->title }}</p>
                    <p class="mt-3 text-xs leading-5 text-slate-500">
                        既存Taskを分配先として選びます。CanoviaはTask / Dependency / Evidence / 制約を各担当へ同じPlan文脈から渡し、担当ごとのExecution Packetまたは外部AI用Promptを作ります。
                    </p>
                </div>
                <a href="{{ route('plans.show', $plan) }}" class="btn-secondary px-3 py-2 text-xs">Planへ戻る</a>
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
                <p class="font-bold">分配内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="page-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">TARGETS</p>
                    <h2 class="mt-1 text-xl font-black text-slate-100">今回指示を渡すTaskを選ぶ</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">最大{{ $maxTargets }}件。担当ラベルは「A」「Validation担当」「Claude」など自由に付けられますが、永続Actorとして保存はしません。</p>
                </div>
                <span class="badge badge-slate">{{ $tasks->count() }} active tasks</span>
            </div>

            <form method="POST" action="{{ route('plans.execution_distribution.prepare', $plan) }}" class="mt-5 space-y-4">
                @csrf

                <div class="space-y-3">
                    @forelse ($tasks as $task)
                        @php
                            $existingTarget = $bundleTargets->get((int) $task->id);
                            $selected = $existingTarget !== null || in_array((int) $task->id, collect(old('selected_task_ids', []))->map(fn ($id) => (int) $id)->all(), true);
                            $dependencyCount = $task->prerequisites->filter(fn ($dependency) => ! in_array($dependency->status, ['done', 'cancelled'], true) && (int) $dependency->progress_percent < 100)->count();
                        @endphp
                        <article class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(10rem,.7fr)_minmax(10rem,.7fr)_8rem] lg:items-end">
                                <label class="flex min-w-0 items-start gap-3">
                                    <input
                                        type="checkbox"
                                        name="selected_task_ids[]"
                                        value="{{ $task->id }}"
                                        @checked($selected)
                                        class="mt-1 h-4 w-4 rounded border-slate-700 bg-slate-950 text-cyan-400"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-sm font-black text-slate-100">{{ $task->title }}</span>
                                        @if ($task->description)
                                            <span class="mt-1 block line-clamp-2 text-xs leading-5 text-slate-500">{{ $task->description }}</span>
                                        @endif
                                        <span class="mt-2 flex flex-wrap gap-2 text-[10px] text-slate-600">
                                            <span>{{ $task->status }}</span>
                                            <span>進捗 {{ (int) $task->progress_percent }}%</span>
                                            @if ($dependencyCount > 0)
                                                <span class="text-amber-300/80">未完了Dependency {{ $dependencyCount }}件</span>
                                            @else
                                                <span class="text-emerald-300/70">Dependency ready</span>
                                            @endif
                                        </span>
                                    </span>
                                </label>

                                <div>
                                    <label class="text-[10px] font-bold text-slate-500">担当ラベル</label>
                                    <input
                                        type="text"
                                        name="actor_label[{{ $task->id }}]"
                                        maxlength="80"
                                        value="{{ old('actor_label.'.$task->id, data_get($existingTarget, 'actor_label')) }}"
                                        class="form-control mt-1"
                                        placeholder="例: A / Validation担当"
                                    >
                                </div>

                                <div>
                                    <label class="text-[10px] font-bold text-slate-500">実行主体</label>
                                    <select name="actor_type[{{ $task->id }}]" class="form-control mt-1">
                                        @foreach ($actorTypes as $value => $label)
                                            <option value="{{ $value }}" @selected(old('actor_type.'.$task->id, data_get($existingTarget, 'actor_type', 'human_ai')) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="text-[10px] font-bold text-slate-500">時間</label>
                                    <input
                                        type="number"
                                        min="5"
                                        max="1440"
                                        name="available_minutes[{{ $task->id }}]"
                                        value="{{ old('available_minutes.'.$task->id, data_get($existingTarget, 'available_minutes')) }}"
                                        class="form-control mt-1"
                                        placeholder="分"
                                    >
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-white/10 p-6 text-center">
                            <p class="text-sm font-bold text-slate-300">分配できる未完了Taskがありません。</p>
                        </div>
                    @endforelse
                </div>

                @if ($tasks->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2 border-t border-white/8 pt-4">
                        @if ($nativeAiEntitled && $nativeAiConfigured)
                            <button type="submit" name="generation_mode" value="native" class="btn-primary">✦ 選択担当をCanoviaで一括生成</button>
                        @endif
                        <button type="submit" name="generation_mode" value="external" class="{{ $nativeAiEntitled && $nativeAiConfigured ? 'btn-secondary' : 'btn-primary' }}">外部AI用Promptを担当ごとに準備</button>
                        @if (! $nativeAiEntitled)
                            <span class="text-[10px] text-slate-600">Native一括生成はAutomatic AI境界です。外部AI用Promptは利用できます。</span>
                        @elseif (! $nativeAiConfigured)
                            <span class="text-[10px] text-slate-600">Native AI未設定のため、外部AI経路を利用します。</span>
                        @endif
                    </div>
                @endif
            </form>
        </section>

        @if ($bundle)
            <section class="page-card border-emerald-300/15 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">DISTRIBUTION BUNDLE</p>
                        <h2 class="mt-1 text-xl font-black text-slate-100">{{ count($bundle['targets'] ?? []) }}担当へ分配する指示</h2>
                        <p class="mt-2 text-xs leading-5 text-slate-500">各担当は別のTaskを実行しますが、Prompt / PacketはそれぞれPlan全体のContextを参照して生成されています。</p>
                    </div>
                    <span class="badge badge-slate">{{ strtoupper((string) data_get($bundle, 'generation_mode', 'external')) }}</span>
                </div>

                <div class="mt-5 space-y-4">
                    @foreach (($bundle['targets'] ?? []) as $target)
                        @php
                            $targetId = (int) data_get($target, 'task_id');
                            $packet = data_get($target, 'packet');
                            $prompt = (string) data_get($target, 'handoff_prompt', '');
                            $stale = (bool) data_get($target, 'stale', false);
                            $packetJson = is_array($packet)
                                ? json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                                : '';
                        @endphp
                        <article class="rounded-2xl border {{ $stale ? 'border-amber-300/20 bg-amber-300/[0.025]' : 'border-white/8 bg-white/[0.025]' }} p-4 sm:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="badge badge-slate">{{ data_get($target, 'actor_label') }}</span>
                                        <span class="badge {{ data_get($target, 'dependency_state') === 'ready' ? 'badge-green' : 'badge-slate' }}">{{ data_get($target, 'dependency_state', 'ready') }}</span>
                                        @if ($stale)
                                            <span class="badge badge-slate">再生成が必要</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 text-base font-black text-slate-100">{{ data_get($target, 'task_title') }}</h3>
                                    <p class="mt-1 text-[10px] text-slate-600">
                                        {{ $actorTypeLabels[data_get($target, 'actor_type', 'human_ai')] ?? '人 + AI' }}
                                        @if (data_get($target, 'available_minutes'))
                                            · {{ (int) data_get($target, 'available_minutes') }}分
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route('plans.tasks.execution_orchestration.show', [$plan, $targetId]) }}" class="btn-secondary px-3 py-2 text-xs">個別Orchestrationを開く</a>
                            </div>

                            @if ($stale)
                                <p class="mt-3 text-xs leading-5 text-amber-200/80">{{ data_get($target, 'stale_reason') }}</p>
                            @endif

                            @if (data_get($target, 'native_error'))
                                <p class="mt-3 text-xs leading-5 text-amber-200/80">{{ data_get($target, 'native_error') }}</p>
                            @endif

                            @if (is_array($packet))
                                <div class="mt-4 rounded-xl border border-violet-300/12 bg-violet-300/[0.025] p-4">
                                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-violet-300">EXECUTION PACKET</p>
                                    <p class="mt-2 text-sm font-black text-slate-100">{{ data_get($packet, 'summary') ?: data_get($target, 'task_title') }}</p>
                                    <p class="mt-2 text-xs leading-5 text-slate-400">{{ data_get($packet, 'objective') }}</p>
                                    @if (! empty(data_get($packet, 'actions', [])))
                                        <div class="mt-3 space-y-2">
                                            @foreach (array_slice((array) data_get($packet, 'actions', []), 0, 4) as $action)
                                                <div class="rounded-lg border border-white/8 bg-slate-950/25 p-3">
                                                    <p class="text-xs font-bold text-slate-200">{{ data_get($action, 'title') }}</p>
                                                    <p class="mt-1 text-[11px] leading-5 text-slate-500">{{ data_get($action, 'details') }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <textarea id="distributionPacket{{ $targetId }}" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $packetJson }}</textarea>
                                <button type="button" class="btn-primary mt-3 px-3 py-2 text-xs" data-copy-target="#distributionPacket{{ $targetId }}">このPacketをコピー</button>
                            @elseif ($prompt !== '')
                                <div class="mt-4 rounded-xl border border-cyan-300/12 bg-cyan-300/[0.025] p-4">
                                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">EXTERNAL AI HANDOFF</p>
                                    <p class="mt-2 text-xs leading-5 text-slate-400">この担当専用のPromptです。Plan全体・Dependency・Evidence・protected scopeを含みます。</p>
                                </div>
                                <textarea id="distributionPrompt{{ $targetId }}" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $prompt }}</textarea>
                                <button type="button" class="btn-primary mt-3 px-3 py-2 text-xs" data-copy-target="#distributionPrompt{{ $targetId }}">この担当のPromptをコピー</button>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            <form method="POST" action="{{ route('plans.execution_distribution.reset', $plan) }}">
                @csrf
                <button type="submit" class="text-xs font-semibold text-slate-600 underline underline-offset-4">分配Bundleをリセット</button>
            </form>
        @endif

        <p class="px-1 text-[11px] leading-5 text-slate-600">
            Distribution Bundleはsession上のProjectionです。担当ラベルや生成結果を新しいTask / Actor Entityとして保存せず、Task・Dependency・EvidenceをSource of Truthに保ちます。
        </p>
    </div>
@endsection
