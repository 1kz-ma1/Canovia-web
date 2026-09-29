@php
    $connectedRepositories = collect($githubRepositories ?? [])
        ->filter(fn ($repository) =>
            (string) data_get($repository->metadata, 'github_app_connection.status', '') === 'connected'
        )
        ->values();
    $candidate = is_array($githubChangeCandidate ?? null) ? $githubChangeCandidate : null;
    $handoffResult = is_array($githubHandoffResult ?? null) ? $githubHandoffResult : null;
    $packetIsStale = (bool) ($packetStale ?? false);
    $pullRequestArtifact = $latestExecutionPullRequest ?? null;
    $returnSnapshot = is_array($githubReturnSnapshot ?? null) ? $githubReturnSnapshot : null;
    $remotePull = is_array(data_get($returnSnapshot, 'pull_request')) ? data_get($returnSnapshot, 'pull_request') : [];
    $reviewSummary = is_array(data_get($returnSnapshot, 'review_summary')) ? data_get($returnSnapshot, 'review_summary') : [];
    $ciState = (string) data_get($returnSnapshot, 'ci.state', 'unknown');
    $returnWarnings = array_values((array) data_get($returnSnapshot, 'warnings', []));
    $decisionCandidate = is_array($githubDecisionCandidate ?? null) ? $githubDecisionCandidate : null;
    $decisionRecommendation = (string) data_get($decisionCandidate, 'recommendation', '');
    $decisionActions = array_values((array) data_get($decisionCandidate, 'allowed_actions', []));
@endphp

@if ($packet || $candidate || $handoffResult || $pullRequestArtifact)
    <section class="page-card border-emerald-300/15 p-5 sm:p-6" data-execution-github-handoff>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">GITHUB HANDOFF</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">成果を、確認してからRepositoryへ渡す</h2>
                <p class="mt-2 text-xs leading-6 text-slate-500">
                    Execution Packetは指示、GitHub変更候補は実際に反映する内容です。
                    ここで候補を準備しただけではBranch / Commit / Pull Requestは作りません。人が内容を確認して確定したときだけGitHubへ送ります。
                </p>
            </div>

            <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary px-3 py-2 text-xs">
                GitHub全体像
            </a>
        </div>

        @if ($handoffResult)
            <div class="mt-5 rounded-2xl border border-emerald-300/15 bg-emerald-300/[0.03] p-4">
                <p class="text-xs font-black text-emerald-100">レビュー用Pull Requestへ反映済み</p>
                <div class="mt-3 grid gap-2 text-xs text-slate-400 sm:grid-cols-2">
                    <p><span class="text-slate-600">Repository</span><br>{{ data_get($handoffResult, 'repo_full_name') }}</p>
                    <p><span class="text-slate-600">File</span><br>{{ data_get($handoffResult, 'file_path') }}</p>
                    <p><span class="text-slate-600">Branch</span><br>{{ data_get($handoffResult, 'branch') }}</p>
                    <p><span class="text-slate-600">Commit</span><br>{{ \Illuminate\Support\Str::limit((string) data_get($handoffResult, 'commit_sha'), 12, '') }}</p>
                </div>
                @if (filled(data_get($handoffResult, 'pull_request_url')))
                    <a
                        href="{{ data_get($handoffResult, 'pull_request_url') }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn-primary mt-4"
                    >PR #{{ data_get($handoffResult, 'pull_request_number') }} をGitHubで確認 ↗</a>
                @endif
                <p class="mt-3 text-[11px] leading-5 text-slate-500">
                    PRを作った事実だけでTask進捗・完了・Evidenceは変更していません。レビュー結果をCanoviaへ戻してから次の状態を判断します。
                </p>
            </div>
        @endif

        @if ($pullRequestArtifact)
            <div class="mt-5 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.025] p-4" data-github-return-layer>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">RETURN LAYER</p>
                        <h3 class="mt-1 text-base font-black text-slate-100">GitHubの結果をCanoviaへ戻す</h3>
                        <p class="mt-2 text-[11px] leading-5 text-slate-500">
                            Review / Merge / optional CIをGitHubから確認し、元TaskへGitHub Evidenceとして記録します。
                            GitHub側の状態だけでTask完了やCanoviaのworkflow laneは変更しません。
                        </p>
                    </div>

                    <a
                        href="{{ $pullRequestArtifact->url }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn-secondary px-3 py-2 text-xs"
                    >PR #{{ $pullRequestArtifact->external_id ?: data_get($remotePull, 'number') }} ↗</a>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                        <p class="text-[10px] text-slate-600">REMOTE PR</p>
                        @if ((bool) data_get($remotePull, 'merged', false))
                            <p class="mt-1 text-sm font-black text-emerald-200">Merged</p>
                        @elseif (filled(data_get($remotePull, 'state')))
                            <p class="mt-1 text-sm font-black text-slate-200">{{ ucfirst((string) data_get($remotePull, 'state')) }}</p>
                        @else
                            <p class="mt-1 text-sm font-black text-slate-500">未確認</p>
                        @endif
                    </div>

                    <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                        <p class="text-[10px] text-slate-600">REVIEW</p>
                        @if ((int) data_get($reviewSummary, 'changes_requested_reviewers', 0) > 0)
                            <p class="mt-1 text-sm font-black text-amber-200">修正依頼 {{ (int) data_get($reviewSummary, 'changes_requested_reviewers') }}人</p>
                        @elseif ((int) data_get($reviewSummary, 'approved_reviewers', 0) > 0)
                            <p class="mt-1 text-sm font-black text-emerald-200">承認確認 {{ (int) data_get($reviewSummary, 'approved_reviewers') }}人</p>
                        @else
                            <p class="mt-1 text-sm font-black text-slate-500">判断未確認</p>
                        @endif
                    </div>

                    <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                        <p class="text-[10px] text-slate-600">CI</p>
                        <p class="mt-1 text-sm font-black {{
                            $ciState === 'success'
                                ? 'text-emerald-200'
                                : ($ciState === 'failure'
                                    ? 'text-rose-200'
                                    : ($ciState === 'pending' ? 'text-amber-200' : 'text-slate-500'))
                        }}">
                            {{
                                match ($ciState) {
                                    'success' => '成功',
                                    'failure' => '失敗',
                                    'pending' => '実行中',
                                    default => '未取得',
                                }
                            }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                        <p class="text-[10px] text-slate-600">LAST SYNC</p>
                        <p class="mt-1 text-xs font-bold text-slate-300">
                            {{ filled(data_get($returnSnapshot, 'fetched_at')) ? data_get($returnSnapshot, 'fetched_at') : 'まだ同期していません' }}
                        </p>
                    </div>
                </div>

                @if ($returnWarnings !== [])
                    <div class="mt-4 rounded-xl border border-amber-300/10 bg-amber-300/[0.02] p-3">
                        <p class="text-[10px] font-black uppercase tracking-[.12em] text-amber-300">OPTIONAL SIGNALS</p>
                        <ul class="mt-2 space-y-1 text-[11px] leading-5 text-slate-500">
                            @foreach ($returnWarnings as $warning)
                                <li>・{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($decisionCandidate)
                    <div class="mt-4 rounded-2xl border border-violet-300/15 bg-violet-300/[0.025] p-4" data-github-evidence-decision>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[.14em] text-violet-300">EVIDENCE DECISION</p>
                                <h4 class="mt-1 text-sm font-black text-slate-100">{{ data_get($decisionCandidate, 'headline') }}</h4>
                                <p class="mt-2 max-w-3xl text-[11px] leading-5 text-slate-500">{{ data_get($decisionCandidate, 'reason') }}</p>
                            </div>
                            <span class="badge badge-slate">
                                {{
                                    match ($decisionRecommendation) {
                                        'complete' => '完了候補',
                                        'continue' => '修正継続候補',
                                        'wait' => '待機候補',
                                        'manual_review' => '要判断',
                                        'no_change' => '変更なし',
                                        default => '確認',
                                    }
                                }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                                <p class="text-[10px] text-slate-600">TASK</p>
                                <p class="mt-1 text-xs font-bold text-slate-200">{{ $task->title }}</p>
                                <p class="mt-1 text-[10px] text-slate-500">{{ $task->status }} · {{ (int) $task->progress_percent }}%</p>
                            </div>
                            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                                <p class="text-[10px] text-slate-600">MERGE</p>
                                <p class="mt-1 text-xs font-bold {{ data_get($decisionCandidate, 'signals.merged') ? 'text-emerald-200' : 'text-slate-400' }}">
                                    {{ data_get($decisionCandidate, 'signals.merged') ? '確認済み' : '未確認' }}
                                </p>
                            </div>
                            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                                <p class="text-[10px] text-slate-600">REVIEW SIGNAL</p>
                                <p class="mt-1 text-xs font-bold {{ (int) data_get($decisionCandidate, 'signals.changes_requested_reviewers', 0) > 0 ? 'text-amber-200' : 'text-slate-300' }}">
                                    承認 {{ (int) data_get($decisionCandidate, 'signals.approved_reviewers', 0) }}
                                    · 修正 {{ (int) data_get($decisionCandidate, 'signals.changes_requested_reviewers', 0) }}
                                </p>
                            </div>
                            <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                                <p class="text-[10px] text-slate-600">CI SIGNAL</p>
                                <p class="mt-1 text-xs font-bold {{
                                    data_get($decisionCandidate, 'signals.ci_state') === 'success'
                                        ? 'text-emerald-200'
                                        : (data_get($decisionCandidate, 'signals.ci_state') === 'failure'
                                            ? 'text-rose-200'
                                            : 'text-slate-400')
                                }}">
                                    {{
                                        match ((string) data_get($decisionCandidate, 'signals.ci_state', 'unknown')) {
                                            'success' => '成功',
                                            'failure' => '失敗',
                                            'pending' => '実行中',
                                            default => '未取得',
                                        }
                                    }}
                                </p>
                            </div>
                        </div>

                        @if ($decisionRecommendation === 'manual_review')
                            <div class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[0.025] p-3 text-[11px] leading-5 text-amber-100/80">
                                Mergeと否定的signalが同時にあります。Canoviaはどちらを優先するか決めません。完了として扱うか、追加対応を続けるかを確認してください。
                            </div>
                        @endif

                        @if ($errors->has('github_decision'))
                            <div class="mt-4 rounded-xl border border-rose-300/15 bg-rose-300/[0.025] p-3 text-[11px] leading-5 text-rose-100/80">
                                {{ $errors->first('github_decision') }}
                            </div>
                        @endif

                        @if ($decisionActions !== [])
                            <p class="mt-4 text-[11px] leading-5 text-slate-500">
                                反映ボタンを押す直前にGitHubを再確認します。Review / Merge / CIやTask状態が変わっていれば反映を止め、最新状態を表示します。
                            </p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($decisionActions as $decisionAction)
                                    <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.decision.apply', [$plan, $task, $pullRequestArtifact]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="{{ $decisionAction }}">
                                        <input type="hidden" name="expected_snapshot_fingerprint" value="{{ data_get($decisionCandidate, 'snapshot_fingerprint') }}">
                                        <input type="hidden" name="expected_task_fingerprint" value="{{ data_get($decisionCandidate, 'task_fingerprint') }}">
                                        <button
                                            type="submit"
                                            class="{{ $decisionAction === $decisionRecommendation ? 'btn-primary' : 'btn-secondary' }}"
                                        >
                                            {{
                                                match ($decisionAction) {
                                                    'complete' => 'このTaskを完了として反映',
                                                    'continue' => '修正対応を続ける',
                                                    'wait' => '次の確認Actionだけ更新',
                                                    default => '反映',
                                                }
                                            }}
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-4 text-[11px] leading-5 text-slate-500">
                                このTaskにはReturn Layerから反映する変更はありません。GitHub EvidenceはContextとして引き続き利用できます。
                            </p>
                        @endif
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($githubEvidenceEntitled && $githubWriteConfigured)
                        <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.return_sync', [$plan, $task, $pullRequestArtifact]) }}">
                            @csrf
                            <button type="submit" class="btn-primary">GitHubから結果を確認</button>
                        </form>
                    @elseif (! $githubEvidenceEntitled)
                        <div class="rounded-xl border border-violet-300/10 bg-violet-300/[0.02] px-3 py-2 text-[11px] text-slate-500">
                            GitHub Evidenceの取得にはDeveloper GitHub Evidence capabilityが必要です。
                        </div>
                    @else
                        <div class="rounded-xl border border-amber-300/10 bg-amber-300/[0.02] px-3 py-2 text-[11px] text-slate-500">
                            Canovia運営側のGitHub App設定が必要です。
                        </div>
                    @endif

                    <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-secondary">計画全体を見直す</a>
                    <a href="{{ route('plans.execution_distribution.show', $plan) }}" class="btn-secondary">担当Contextを再評価</a>
                </div>

                @if ((bool) data_get($remotePull, 'merged', false))
                    <p class="mt-3 text-[11px] leading-5 text-emerald-100/70">
                        MergeはGitHubで確認済みです。ただし「このTaskの成功条件を満たしたか」は別判断なので、Taskを自動完了にはしていません。
                    </p>
                @endif
            </div>
        @endif

        @if ($candidate)
            <div class="mt-5 overflow-hidden rounded-2xl border border-amber-300/15 bg-amber-300/[0.025]" data-github-change-candidate>
                <div class="border-b border-white/8 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[.14em] text-amber-300">HUMAN CONFIRMATION</p>
                            <h3 class="mt-1 text-base font-black text-slate-100">この内容をGitHubへ送りますか？</h3>
                        </div>
                        <span class="badge badge-slate">
                            {{ data_get($candidate, 'preview.file_action') === 'created' ? '新規ファイル' : '既存ファイル更新' }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <p class="text-[10px] text-slate-600">Repository</p>
                            <p class="mt-1 break-all text-slate-300">{{ data_get($candidate, 'repository.repo_full_name') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-slate-600">File</p>
                            <p class="mt-1 break-all text-slate-300">{{ data_get($candidate, 'change.file_path') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-slate-600">Base</p>
                            <p class="mt-1 text-slate-300">{{ data_get($candidate, 'preview.base_branch') }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-slate-600">Size</p>
                            <p class="mt-1 text-slate-300">
                                {{ number_format((int) data_get($candidate, 'preview.current_bytes', 0)) }}
                                → {{ number_format((int) data_get($candidate, 'preview.proposed_bytes', 0)) }} bytes
                            </p>
                        </div>
                    </div>
                </div>

                <div class="p-4">
                    <p class="text-xs font-bold text-slate-300">レビュー用タイトル</p>
                    <p class="mt-1 text-sm text-slate-100">{{ data_get($candidate, 'change.pull_request_title') }}</p>
                    <p class="mt-4 text-xs font-bold text-slate-300">変更メモ</p>
                    <p class="mt-1 text-xs text-slate-400">{{ data_get($candidate, 'change.commit_message') }}</p>

                    @if (filled(data_get($candidate, 'change.pull_request_body')))
                        <p class="mt-4 text-xs font-bold text-slate-300">レビュー説明</p>
                        <p class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-400">{{ data_get($candidate, 'change.pull_request_body') }}</p>
                    @endif

                    <label class="mt-5 block text-xs font-bold text-slate-300">GitHubへ反映するファイル内容</label>
                    <textarea readonly rows="16" class="form-control mt-2 w-full font-mono text-xs">{{ data_get($candidate, 'change.content') }}</textarea>

                    <div class="mt-4 rounded-xl border border-cyan-300/10 bg-cyan-300/[0.02] p-3 text-[11px] leading-5 text-slate-500">
                        候補を作った時点のファイルSHAも保持しています。確認中にGitHub側の対象ファイルが更新・作成・削除された場合は、
                        上書きを避けるためPR作成を停止して候補の作り直しを求めます。
                    </div>

                    @if ($packetIsStale)
                        <div class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[0.025] p-3 text-[11px] leading-5 text-amber-100/80">
                            この候補の元になったExecution Contextが変わっています。GitHubへ送らず、Packetと変更候補を現在Contextから作り直してください。
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-2">
                        @unless ($packetIsStale)
                            <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.confirm', [$plan, $task]) }}">
                                @csrf
                                <button type="submit" class="btn-primary">確認してレビューに出す</button>
                            </form>
                        @endunless
                        <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.discard', [$plan, $task]) }}">
                            @csrf
                            <button type="submit" class="btn-secondary">候補を破棄</button>
                        </form>
                    </div>
                </div>
            </div>
        @elseif ($packetIsStale)
            <div class="mt-5 rounded-xl border border-amber-300/12 bg-amber-300/[0.025] p-4">
                <p class="text-xs font-bold text-amber-100">現在ContextからExecution Packetを再生成してください</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">
                    古いPacketを元にGitHub変更候補を作ると、現在のDependency・Evidence・Task scopeとずれる可能性があるため停止しています。
                </p>
            </div>
        @elseif (! $githubWriteEntitled)
            <div class="mt-5 rounded-xl border border-violet-300/12 bg-violet-300/[0.025] p-4">
                <p class="text-xs font-bold text-violet-100">GitHubへの反映はDeveloper GitHub Write</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">
                    Execution Packetの生成・外部AI利用とは分離し、Repositoryへ書き込む権限だけを別Capabilityにしています。
                </p>
            </div>
        @elseif (! $githubWriteConfigured)
            <div class="mt-5 rounded-xl border border-amber-300/12 bg-amber-300/[0.025] p-4">
                <p class="text-xs font-bold text-amber-100">Canovia運営側のGitHub App設定が必要です</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">
                    App ID / Private Keyはserver-sideだけで管理します。一般ユーザーへPATや秘密鍵を入力させません。
                </p>
            </div>
        @elseif ($connectedRepositories->isEmpty())
            <div class="mt-5 rounded-xl border border-dashed border-cyan-300/15 bg-cyan-300/[0.02] p-4">
                <p class="text-xs font-bold text-cyan-100">このPlanには接続済みRepositoryがありません</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">
                    GitHub全体像からRepositoryを登録し、「GitHubを接続」を完了してください。OrganizationではOwner承認が必要な場合があります。
                </p>
                <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary mt-3">Repository接続を確認</a>
            </div>
        @else
            <details class="mt-5 rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                <summary class="cursor-pointer list-none text-sm font-black text-slate-100">＋ 実行結果をGitHub変更候補にする</summary>

                <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.prepare', [$plan, $task]) }}" class="mt-4 grid gap-3 lg:grid-cols-2">
                    @csrf

                    <label class="block lg:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">反映先Repository</span>
                        <select name="repository_artifact_id" class="form-control mt-2 w-full" required>
                            @foreach ($connectedRepositories as $repository)
                                <option
                                    value="{{ $repository->id }}"
                                    @selected((int) old('repository_artifact_id', $connectedRepositories->count() === 1 ? $repository->id : 0) === (int) $repository->id)
                                >
                                    {{ $repository->title ?: $repository->url }}
                                </option>
                            @endforeach
                        </select>
                        <span class="mt-1 block text-[10px] text-slate-600">AIではなく人が反映先Repositoryを選びます。</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">変更するファイル</span>
                        <input
                            type="text"
                            name="file_path"
                            value="{{ old('file_path') }}"
                            class="form-control mt-2 w-full"
                            maxlength="240"
                            placeholder="例: app/Services/MapService.php"
                            required
                        >
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">レビュー用タイトル</span>
                        <input
                            type="text"
                            name="pull_request_title"
                            value="{{ old('pull_request_title', $task->title) }}"
                            class="form-control mt-2 w-full"
                            maxlength="240"
                            required
                        >
                    </label>

                    <label class="block lg:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">実行結果のファイル内容</span>
                        <textarea
                            name="file_content"
                            rows="14"
                            class="form-control mt-2 w-full font-mono text-xs"
                            maxlength="200000"
                            placeholder="担当者 / AIが完成させた、このファイルの完全な内容を貼り付けます"
                            required
                        >{{ old('file_content') }}</textarea>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">変更メモ</span>
                        <input
                            type="text"
                            name="commit_message"
                            value="{{ old('commit_message') }}"
                            class="form-control mt-2 w-full"
                            maxlength="240"
                            placeholder="例: fix map zoom interaction"
                            required
                        >
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">レビュー説明（任意）</span>
                        <textarea
                            name="pull_request_body"
                            rows="4"
                            class="form-control mt-2 w-full text-xs"
                            maxlength="20000"
                            placeholder="何を変えたか / 確認してほしいこと"
                        >{{ old('pull_request_body') }}</textarea>
                    </label>

                    <div class="lg:col-span-2 rounded-xl border border-white/8 bg-white/[0.02] p-3 text-[11px] leading-5 text-slate-500">
                        「変更候補を準備」ではGitHubの現在ファイルをread-onlyで確認するだけです。
                        Branch / Commit / Pull Requestは、次の確認画面で人が確定するまで作りません。
                    </div>

                    <div class="lg:col-span-2">
                        <button type="submit" class="btn-primary w-full justify-center">変更候補を準備</button>
                    </div>
                </form>
            </details>
        @endif
    </section>
@endif
