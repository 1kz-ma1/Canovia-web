<div class="space-y-4" data-development-surface-panel="work">
@php
    // Without observed Release Evidence, prefer a real next Task over
    // requiring GitHub setup. Intelligence still owns the Release flow
    // as soon as repository/activity/focus evidence exists.
    $localNextTask = $activeTasks->first();
    $localFirstAction = ! ($developmentGithubRepository ?? null)
        && collect($developmentRecentActivity ?? [])->isEmpty()
        && ! ($developmentFocusTask ?? null)
        && ($localNextTask || $plan->tasks->isEmpty());
@endphp
<section
            id="development-current-action"
            class="page-card overflow-hidden border-violet-300/20 bg-[radial-gradient(circle_at_88%_12%,rgba(167,139,250,.12),transparent_28%),rgba(15,23,42,.32)] p-5 sm:p-6"
            data-development-workspace-action
            data-development-home-next-action
        >
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(15rem,.75fr)] lg:items-start">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-300">NEXT ACTION</p>
                    @if ($localFirstAction)
                        <div data-development-local-first-action>
                            <h2 class="mt-2 text-2xl font-black text-slate-50">
                                {{ $localNextTask?->title ?? '最初の開発Taskを作る' }}
                            </h2>
                            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">
                                @if ($localNextTask)
                                    まずはこのTaskを実行しましょう。GitHubを接続しなくても進められます。成果ができたら、あとからEvidenceを関連付けられます。
                                @else
                                    目標を具体的なTaskに分け、最初の一歩を決めましょう。GitHubの接続は任意で、あとから設定できます。
                                @endif
                            </p>
                        </div>
                    @else
                        <h2 class="mt-2 text-2xl font-black text-slate-50">
                            {{ $presentation?->action?->title ?? '開発状態を確認する' }}
                        </h2>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">
                            {{ $presentation?->action?->intent ?? 'TaskとGitHubの現在状態から、次に進める入口を確認します。' }}
                        </p>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-2">
                        @if ($localFirstAction && $localNextTask)
                            @if ($canEdit ?? false)
                                <a href="{{ route('plans.tasks.execution_orchestration.show', [$plan, $localNextTask]) }}" class="btn-primary min-h-11 px-4" data-development-local-task-action>
                                    このTaskを実行する
                                </a>
                            @endif
                        @elseif ($localFirstAction)
                            @if ($canManage ?? false)
                                <a href="{{ route('plans.ai_task_assistant.show', ['plan' => $plan, 'return_to_workspace' => 1]) }}" class="btn-primary min-h-11 px-4" data-development-local-create-action>
                                    初期タスクを作る
                                </a>
                            @endif
                        @elseif ($presentation)
                            <a href="{{ $presentation->actionUrl }}" class="btn-primary min-h-11 px-4">
                                {{ $presentation->actionLabel }}
                            </a>
                        @endif
                        @unless ($localFirstAction)
                            <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary min-h-11 px-4">
                                GitHub / Evidence
                            </a>
                        @endunless
                    </div>

                    @if (! $localFirstAction && $presentation?->decision)
                        <details class="pk-action-details mt-5">
                            <summary>なぜ今これ？</summary>
                            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $presentation->decision->summary }}</p>
                        </details>
                    @endif
                </div>

                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">CURRENT FOCUS</p>
                    @if ($developmentFocusTask)
                        <p class="mt-2 text-base font-black text-slate-100">{{ $developmentFocusTask->title }}</p>
                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-slate-600">
                            @if (data_get($focusState, 'pull_request_number'))
                                <span>PR #{{ (int) data_get($focusState, 'pull_request_number') }}</span>
                            @endif
                            @if (data_get($focusState, 'branch'))
                                <span>{{ data_get($focusState, 'branch') }}</span>
                            @endif
                            @if ($shortSha)
                                <span>{{ $shortSha }}</span>
                            @endif
                        </div>
                    @elseif ($activeTasks->isNotEmpty())
                        <p class="mt-2 text-base font-black text-slate-100">{{ $activeTasks->first()->title }}</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Release Evidenceがまだなくても、Planの実行はここから続けられます。</p>
                    @else
                        <p class="mt-2 text-sm font-bold text-slate-300">まだ進行中Taskがありません。</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">PlanのTaskを作るか、GitHubを接続して開発状態を取り込みます。</p>
                    @endif
                </div>
            </div>

            @if ($executionContext)
                <details class="mt-5 border-t border-white/8 pt-4" data-development-context-details>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl border border-white/8 bg-slate-950/25 px-4 py-3 text-sm font-black text-slate-300">
                        <span>実装コンテキスト・Brief</span>
                        <span class="text-[10px] font-normal text-slate-600">必要なときだけ開く</span>
                    </summary>
                    <div class="mt-4" data-development-execution-context>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">ACTION CONTEXT</p>
                            <h3 class="mt-1 text-base font-black text-slate-100">
                                {{ data_get($executionContext, 'task.title', '対象Task') }}
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                このTaskに明示リンクされたGitHub Evidenceだけから、現在の実行対象をまとめています。
                            </p>
                        </div>
                        @if (data_get($executionContext, 'latest_evidence_at'))
                            <span class="text-[10px] text-slate-600">
                                Evidence {{ data_get($executionContext, 'latest_evidence_at')?->format('m/d H:i') }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">Repository</p>
                            <p class="mt-1 break-all text-xs font-bold text-slate-200">
                                {{ data_get($executionContext, 'repository') ?: '未確認' }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">Branch / Commit</p>
                            <p class="mt-1 break-all text-xs font-bold text-slate-200">
                                {{ data_get($executionContext, 'branch.name') ?: '未確認' }}
                            </p>
                            @if (data_get($executionContext, 'commit.sha'))
                                <p class="mt-1 text-[10px] text-slate-600">
                                    {{ mb_substr((string) data_get($executionContext, 'commit.sha'), 0, 10) }}
                                    @if (data_get($executionContext, 'commit.verified'))
                                        · verified
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">Pull Request</p>
                            @if (data_get($executionContext, 'pull_request.number'))
                                @if (data_get($executionContext, 'pull_request.url'))
                                    <a href="{{ data_get($executionContext, 'pull_request.url') }}" target="_blank" rel="noopener noreferrer" class="mt-1 block text-xs font-black text-cyan-200 hover:text-cyan-100">
                                        #{{ (int) data_get($executionContext, 'pull_request.number') }} · {{ data_get($executionContext, 'pull_request.state', 'unknown') }}
                                    </a>
                                @else
                                    <p class="mt-1 text-xs font-black text-slate-200">
                                        #{{ (int) data_get($executionContext, 'pull_request.number') }} · {{ data_get($executionContext, 'pull_request.state', 'unknown') }}
                                    </p>
                                @endif
                                @if (data_get($executionContext, 'pull_request.draft'))
                                    <p class="mt-1 text-[10px] text-amber-200">Draft</p>
                                @endif
                            @else
                                <p class="mt-1 text-xs font-bold text-slate-500">未確認</p>
                            @endif
                        </div>
                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">CI / Review</p>
                            <p class="mt-1 text-xs font-black {{ data_get($executionContext, 'ci.state') === 'failure' ? 'text-rose-300' : (data_get($executionContext, 'ci.state') === 'success' ? 'text-emerald-300' : 'text-slate-300') }}">
                                CI {{ $ciLabels[data_get($executionContext, 'ci.state', 'unknown')] ?? data_get($executionContext, 'ci.state', '未確認') }}
                            </p>
                            <p class="mt-1 text-[10px] text-slate-500">
                                Review {{ $reviewLabels[data_get($executionContext, 'review.state', 'UNKNOWN')] ?? data_get($executionContext, 'review.state', '未確認') }}
                                @if (data_get($executionContext, 'review.reviewer'))
                                    · {{ data_get($executionContext, 'review.reviewer') }}
                                @endif
                            </p>
                        </div>
                    </div>

                    @if (data_get($executionContext, 'issue.number') || data_get($executionContext, 'deployment.status'))
                        <div class="mt-2 flex flex-wrap gap-2 text-[10px]">
                            @if (data_get($executionContext, 'issue.number'))
                                <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                    Issue #{{ (int) data_get($executionContext, 'issue.number') }} · {{ data_get($executionContext, 'issue.state', 'unknown') }}
                                </span>
                            @endif
                            @if (data_get($executionContext, 'deployment.status'))
                                <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                    Deploy {{ data_get($executionContext, 'deployment.environment') ?: 'environment' }}
                                    · {{ data_get($executionContext, 'deployment.status') }}
                                    @if (data_get($executionContext, 'deployment.production'))
                                        · production
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endif

                    <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
                        <div class="rounded-2xl border border-violet-300/15 bg-violet-300/[0.025] p-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-violet-300">HANDOFF</p>
                            <p class="mt-2 text-sm font-black text-slate-100">{{ data_get($executionHandoff, 'title', '次の開発Action') }}</p>
                            <p class="mt-2 text-xs leading-5 text-slate-400">{{ data_get($executionHandoff, 'evidence_hint') }}</p>

                            @if (count((array) data_get($executionHandoff, 'done_when', [])) > 0)
                                <div class="mt-3 border-t border-white/8 pt-3">
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">DONE WHEN</p>
                                    <ul class="mt-2 space-y-1.5 text-xs text-slate-400">
                                        @foreach ((array) data_get($executionHandoff, 'done_when', []) as $signal)
                                            <li class="flex gap-2">
                                                <span class="text-cyan-300">✓</span>
                                                <span>{{ $signal }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">RECENT EVIDENCE</p>
                                <span class="text-[10px] text-slate-700">{{ $executionRecentEvidence->count() }}件</span>
                            </div>
                            @if ($executionRecentEvidence->isEmpty())
                                <p class="mt-2 text-xs leading-5 text-slate-600">このTaskのGitHub Evidenceはまだありません。</p>
                            @else
                                <div class="mt-2 space-y-2">
                                    @foreach ($executionRecentEvidence->take(3) as $contextEvidence)
                                        <div class="rounded-xl border border-white/6 bg-slate-950/30 p-2.5">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-[10px] font-black text-slate-500">{{ $contextEvidence['label'] }}</span>
                                                @if ($contextEvidence['occurred_at'])
                                                    <span class="text-[9px] text-slate-700">{{ $contextEvidence['occurred_at']->format('m/d H:i') }}</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-[11px] leading-4 text-slate-400">{{ $contextEvidence['summary'] }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($implementationBrief)
                        <div
                            class="mt-4 rounded-3xl border border-cyan-300/18 bg-[linear-gradient(135deg,rgba(34,211,238,.055),rgba(139,92,246,.04),rgba(15,23,42,.15))] p-4 sm:p-5"
                            data-development-implementation-brief
                            data-development-implementation-brief-mode="{{ data_get($implementationBrief, 'mode', 'continuation') }}"
                        >
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="max-w-3xl">
                                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">
                                        {{ data_get($implementationBrief, 'eyebrow', 'DEVELOPMENT BRIEF') }}
                                    </p>
                                    <h3 class="mt-1 text-lg font-black text-slate-50">
                                        {{ data_get($implementationBrief, 'title', '次の開発Action') }}
                                    </h3>
                                    <p class="mt-2 text-xs leading-5 text-slate-400">
                                        {{ data_get($implementationBrief, 'objective') }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-2 text-[10px]">
                                    @if (data_get($implementationBrief, 'target.repository'))
                                        <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                            {{ data_get($implementationBrief, 'target.repository') }}
                                        </span>
                                    @endif
                                    @if (data_get($implementationBrief, 'target.branch'))
                                        <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                            {{ data_get($implementationBrief, 'target.branch') }}
                                        </span>
                                    @endif
                                    @if (data_get($implementationBrief, 'target.pull_request_number'))
                                        <span class="rounded-full border border-white/8 bg-slate-950/30 px-2.5 py-1 text-slate-500">
                                            PR #{{ (int) data_get($implementationBrief, 'target.pull_request_number') }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,.8fr)]">
                                <div class="rounded-2xl border border-cyan-300/12 bg-slate-950/25 p-4">
                                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-cyan-200">HOW TO PROCEED</p>
                                    <ol class="mt-3 space-y-2.5 text-xs leading-5 text-slate-300">
                                        @foreach ($implementationBriefSteps as $briefStep)
                                            <li class="flex gap-3">
                                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-cyan-300/20 bg-cyan-300/[0.04] text-[9px] font-black text-cyan-300">
                                                    {{ $loop->iteration }}
                                                </span>
                                                <span>{{ $briefStep }}</span>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>

                                <div class="space-y-3">
                                    <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">KNOWN STATE</p>
                                        <ul class="mt-2 space-y-1.5 text-[11px] leading-4 text-slate-500">
                                            @foreach ($implementationBriefFacts as $briefFact)
                                                <li>• {{ $briefFact }}</li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    <div class="rounded-2xl border border-emerald-300/12 bg-emerald-300/[0.025] p-4">
                                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-emerald-300">DONE WHEN</p>
                                        <ul class="mt-2 space-y-1.5 text-[11px] leading-4 text-slate-400">
                                            @foreach ($implementationBriefValidation as $signal)
                                                <li class="flex gap-2">
                                                    <span class="text-emerald-300">✓</span>
                                                    <span>{{ $signal }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            @if (
                                $canEdit
                                && $implementationBriefTaskId > 0
                                && ($providerTriageCiAvailable || $providerTriageReviewAvailable)
                            )
                                <div class="mt-4 rounded-2xl border border-rose-300/12 bg-rose-300/[0.02] p-4" data-development-provider-triage-entry>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-rose-300">PROVIDER DETAIL</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                必要なときだけGitHubからReview本文・CI失敗詳細を一時取得します。取得した本文やannotationはCanoviaへ保存しません。
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap gap-2">
                                            @if ($providerTriageCiAvailable)
                                                <form method="POST" action="{{ route('plans.development_provider_triage.inspect', [$plan, $implementationBriefTaskId]) }}">
                                                    @csrf
                                                    <input type="hidden" name="mode" value="ci">
                                                    <button type="submit" class="btn-secondary min-h-10 px-3 text-xs">
                                                        CI失敗の詳細を取得
                                                    </button>
                                                </form>
                                            @endif

                                            @if ($providerTriageReviewAvailable)
                                                <form method="POST" action="{{ route('plans.development_provider_triage.inspect', [$plan, $implementationBriefTaskId]) }}">
                                                    @csrf
                                                    <input type="hidden" name="mode" value="review">
                                                    <button type="submit" class="btn-secondary min-h-10 px-3 text-xs">
                                                        Review指摘の詳細を取得
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @if ($canEdit && $implementationBriefTaskId > 0)
                                <details class="mt-4 rounded-2xl border border-violet-300/14 bg-violet-300/[0.025] p-4" data-development-coding-agent-handoff>
                                    <summary class="cursor-pointer list-none text-xs font-black text-violet-200">
                                        Coding Agentへ引き継ぐ
                                        <span class="ml-2 text-[10px] font-normal text-slate-600">明示確認後にExecution Contextを準備</span>
                                    </summary>

                                    <form
                                        method="POST"
                                        action="{{ route('plans.tasks.development_coding_agent_handoff.prepare', [$plan, $implementationBriefTaskId]) }}"
                                        class="mt-4 space-y-4"
                                    >
                                        @csrf

                                        <div class="rounded-xl border border-white/8 bg-slate-950/30 p-3">
                                            <p class="text-xs font-bold text-slate-300">引き継ぐ内容</p>
                                            <ul class="mt-2 space-y-1 text-[10px] leading-4 text-slate-600">
                                                <li>• 現在TaskとImplementation Brief</li>
                                                <li>• Plan / Dependency / protected scopeを含む既存Execution Context</li>
                                                <li>• Repository / Branch / PRなど確認済みの開発State</li>
                                            </ul>
                                            <p class="mt-2 text-[10px] leading-4 text-slate-600">
                                                V57.7のReview本文・CI annotationなど一時Provider本文は自動で保存・引き継ぎません。
                                            </p>
                                        </div>

                                        <label class="block">
                                            <span class="text-xs font-semibold text-slate-400">今回使える時間 <span class="text-slate-600">任意</span></span>
                                            <input
                                                type="number"
                                                min="5"
                                                max="1440"
                                                name="available_minutes"
                                                class="form-control mt-2 w-full sm:max-w-48"
                                                placeholder="例: 30"
                                            >
                                        </label>

                                        <label class="flex items-start gap-3 rounded-xl border border-violet-300/10 bg-violet-300/[0.02] p-3">
                                            <input type="checkbox" name="confirmed" value="1" required class="mt-0.5">
                                            <span class="text-xs leading-5 text-slate-400">
                                                現在Taskの範囲と完了条件を確認しました。Coding Agent向けPromptの準備だけを行い、
                                                Agent実行・GitHub write・merge・deployはまだ行わないことを理解しています。
                                            </span>
                                        </label>

                                        <button type="submit" class="btn-primary min-h-10 w-full justify-center sm:w-auto">
                                            Coding Agent向けContextを準備
                                        </button>
                                    </form>
                                </details>
                            @endif

                            <details class="mt-4 rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-development-brief-handoff>
                                <summary class="cursor-pointer list-none text-xs font-black text-slate-300">
                                    このBriefを実装ツールへ渡す
                                    <span class="ml-2 text-[10px] font-normal text-slate-600">source codeやdiffは含みません</span>
                                </summary>

                                <div class="mt-3">
                                    <textarea
                                        id="development-implementation-brief-copy"
                                        readonly
                                        rows="15"
                                        class="form-control w-full resize-y font-mono text-[11px] leading-5"
                                        data-development-brief-copy
                                    >{{ data_get($implementationBrief, 'copy_text') }}</textarea>

                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                        <p class="text-[10px] leading-4 text-slate-600">
                                            Task / Repository / GitHub State / 完了条件だけを渡します。未知の事実は補完しません。
                                        </p>
                                        <button
                                            type="button"
                                            class="btn-secondary min-h-9 px-3 text-xs"
                                            data-development-brief-copy-button
                                            data-copy-target="development-implementation-brief-copy"
                                        >
                                            Briefをコピー
                                        </button>
                                    </div>

                                    @if ($implementationBriefGuardrails->isNotEmpty())
                                        <div class="mt-3 border-t border-white/8 pt-3">
                                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">GUARDRAILS</p>
                                            <ul class="mt-2 space-y-1 text-[10px] leading-4 text-slate-600">
                                                @foreach ($implementationBriefGuardrails as $guardrail)
                                                    <li>• {{ $guardrail }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            </details>
                        </div>
                    @endif
                </div>
                </details>
            @endif
        </section>

        

<details class="page-card p-4 sm:p-5" data-development-active-work-details>
    <summary class="cursor-pointer list-none">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">ACTIVE WORK</p>
                <h2 class="mt-1 text-base font-black text-slate-100">他の進行中Task</h2>
            </div>
            <span class="badge badge-slate">{{ $activeTasks->count() }}件</span>
        </div>
    </summary>
    <div class="mt-4">
<section
            id="development-active-work"
            class="page-card p-5 sm:p-6"
            data-development-home-active
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">ACTIVE DEVELOPMENT</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">今動いているTask</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Focus Taskを先頭に、進行中・未着手の開発Taskを確認します。</p>
                </div>
                <a href="{{ route('plans.show', $plan) }}" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">Plan全体を見る →</a>
            </div>

            @if ($activeTasks->isEmpty())
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-5">
                    <p class="text-sm font-black text-slate-200">進行中のTaskはありません。</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Plan詳細からTaskを追加・整理すると、ここに現在の開発対象が並びます。</p>
                </div>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($activeTasks as $task)
                        @php
                            $githubArtifacts = $task->artifacts;
                            $isFocusTask = $developmentFocusTask && (int) $developmentFocusTask->id === (int) $task->id;
                            $progress = max(0, min(100, (int) $task->progress_percent));
                        @endphp
                        <article class="rounded-2xl border {{ $isFocusTask ? 'border-cyan-300/25 bg-cyan-300/[0.035]' : 'border-white/8 bg-slate-950/25' }} p-4" data-development-active-task="{{ $task->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($isFocusTask)
                                            <span class="text-[10px] font-black text-cyan-300">FOCUS</span>
                                        @endif
                                        <span class="text-[10px] font-bold text-slate-500">{{ $taskStatusLabels[$task->status] ?? $task->status }}</span>
                                    </div>
                                    <h3 class="mt-2 text-sm font-black leading-5 text-slate-100">{{ $task->title }}</h3>
                                </div>
                                <span class="shrink-0 text-xs font-black text-slate-400">{{ $progress }}%</span>
                            </div>

                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-800">
                                <div class="h-full rounded-full bg-cyan-300/70" style="width: {{ $progress }}%"></div>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-slate-600">
                                @if ($task->remaining_minutes !== null)
                                    <span>残り {{ (int) $task->remaining_minutes }}分</span>
                                @endif
                                <span>GitHub {{ $githubArtifacts->count() }}件</span>
                            </div>

                            @if ($canEdit)
                                <a href="{{ route('plans.tasks.execution_orchestration.show', [$plan, $task]) }}" class="mt-4 inline-flex text-xs font-bold text-cyan-300 hover:text-cyan-200">
                                    このTaskを進める →
                                </a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        
    </div>
</details>
</div>
