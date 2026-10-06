@php
    $kindCounts = $overview['kind_counts'];
    $workflowCounts = $overview['workflow_counts'];
    $recentItems = $overview['recent_items'];
    $linkedTasks = $overview['linked_tasks'];
    $snapshot = is_array($overview['remote_snapshot'] ?? null) ? $overview['remote_snapshot'] : null;
    $remoteRepository = is_array(data_get($snapshot, 'repository')) ? data_get($snapshot, 'repository') : [];
    $remoteBranches = collect(data_get($snapshot, 'branches', []));
    $remotePullRequests = collect(data_get($snapshot, 'pull_requests', []));
    $remoteIssues = collect(data_get($snapshot, 'issues', []));
    $remoteRuns = collect(data_get($snapshot, 'actions_runs', []));
    $remoteWarnings = collect(data_get($snapshot, 'warnings', []));
    $canInspectRepository = (bool) ($can_repository_inspect ?? false);
    $canWriteRepository = (bool) ($can_repository_write ?? false);
    $integrationStatus = (array) ($github_integration_status ?? []);
    $evidenceAccessMessage = (string) data_get(
        $integrationStatus,
        'evidence.message',
        'Repository自動取得を利用できません。',
    );
    $writeAccessMessage = (string) data_get(
        $integrationStatus,
        'write.message',
        'RepositoryへのReview Writeを利用できません。',
    );
    $githubWriteConfigured = (bool) ($github_write_configured ?? false);
    $githubAppConnectAvailable = (bool) ($github_app_connect_available ?? false);
    $appConnection = is_array($overview['app_connection'] ?? null) ? $overview['app_connection'] : [];
    $connectionStatus = (string) ($appConnection['status'] ?? 'not_connected');
    $connectionReadReady = (bool) ($appConnection['read_ready'] ?? ($connectionStatus === 'connected'));
    $connectionWriteReady = (bool) ($appConnection['write_ready'] ?? false);
    $connectionManagementUrl = $appConnection['management_url'] ?? null;
    $snapshotSource = (string) data_get($snapshot, 'source', '');
    $isAppSnapshot = $snapshotSource === 'github_app_rest';
    $connectionAccount = trim((string) ($appConnection['account_login'] ?? ''));
    $connectionReadiness = is_array($overview['integration_readiness'] ?? null)
        ? $overview['integration_readiness']
        : [];
    $connectionOwner = (string) ($connectionReadiness['owner'] ?? '');
    $connectionReadinessLabel = (string) ($connectionReadiness['label'] ?? '');
    $connectionReadinessDetail = (string) ($connectionReadiness['detail'] ?? '');
@endphp

<article
    class="overflow-hidden rounded-3xl border border-violet-300/15 bg-slate-950/50 shadow-xl shadow-slate-950/20"
    data-github-repository-overview
    data-github-repository="{{ $overview['repo_full_name'] }}"
>
    <div class="border-b border-white/8 bg-gradient-to-r from-violet-300/[0.055] via-cyan-300/[0.025] to-transparent p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-violet-300/20 bg-violet-300/[0.07] px-2 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-violet-200">
                        Repository
                    </span>
                    @if ($snapshot)
                        <span class="rounded-full border border-emerald-300/15 bg-emerald-300/[0.035] px-2 py-1 text-[10px] font-bold text-emerald-200">
                            {{ $isAppSnapshot ? 'GitHub App sync' : 'Public preview' }}
                        </span>
                    @elseif (! $overview['repository_registered'])
                        <span class="rounded-full border border-slate-700 px-2 py-1 text-[10px] text-slate-500">関連URLから認識</span>
                    @endif
                </div>

                <h3 class="mt-3 break-words text-lg font-black text-slate-50 md:text-xl">{{ $overview['repo_full_name'] }}</h3>

                @if (filled(data_get($remoteRepository, 'description')))
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">{{ data_get($remoteRepository, 'description') }}</p>
                @endif

                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500">
                    <span>{{ $overview['plan_icon'] }} {{ $overview['plan_title'] }}</span>
                    @if (filled(data_get($remoteRepository, 'default_branch')))
                        <span>default: {{ data_get($remoteRepository, 'default_branch') }}</span>
                    @endif
                    @if (filled(data_get($remoteRepository, 'language')))
                        <span>{{ data_get($remoteRepository, 'language') }}</span>
                    @endif
                    @if (filled(data_get($remoteRepository, 'visibility')))
                        <span>{{ data_get($remoteRepository, 'visibility') }}</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($overview['repository_registered'] && $overview['can_edit'] && $canInspectRepository)
                    <form method="POST" action="{{ route('github_workflow.repository.refresh', $overview['repository_artifact_id']) }}">
                        @csrf
                        <button type="submit" class="btn-primary px-3 py-2 text-xs">
                            @if ($connectionStatus === 'connected')
                                Repository同期
                            @elseif ($snapshot)
                                Public Preview更新
                            @else
                                Public Previewを試す
                            @endif
                        </button>
                    </form>
                @endif

                <a
                    href="{{ $overview['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn-secondary px-3 py-2 text-xs"
                >Repositoryを開く ↗</a>

                @if ($overview['details_url'])
                    <a href="{{ $overview['details_url'] }}" class="btn-secondary px-3 py-2 text-xs">Artifact詳細</a>
                @endif
            </div>
        </div>

        <p class="mt-4 max-w-3xl text-xs leading-6 text-slate-400">
            Repositoryは「今やる」項目ではなく、この開発のルートです。
            GitHubの現在構造とCanovia上のTask・判断状態を重ねて、全体像から次の作業へ降りられるようにします。
        </p>
    </div>

    @if ($snapshot)
        <section class="border-b border-emerald-300/10 bg-emerald-300/[0.018] p-5" data-github-remote-snapshot>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">GITHUB NOW</p>
                    <h4 class="mt-1 text-sm font-black text-slate-100">GitHubから取得した現在の構造</h4>
                    <p class="mt-1 text-[10px] text-slate-600">一覧はBranch / PR / Issue 最大12件、Actions最大10件の取得範囲です。</p>
                </div>
                <div class="text-right text-[10px] leading-5 text-slate-600">
                    @if (filled(data_get($snapshot, 'fetched_at')))
                        <p>取得 {{ data_get($snapshot, 'fetched_at') }}</p>
                    @endif
                    @if (data_get($snapshot, 'rate_limit_remaining') !== null)
                        <p>API remaining {{ data_get($snapshot, 'rate_limit_remaining') }}</p>
                    @endif
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                    <strong class="block text-xl text-slate-50">{{ $remoteBranches->count() }}</strong>
                    <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Branches</span>
                    <div class="mt-3 space-y-1">
                        @foreach ($remoteBranches->take(4) as $branch)
                            <p class="truncate text-[11px] text-slate-400">
                                {{ data_get($branch, 'name') }}
                                @if (data_get($branch, 'protected'))
                                    <span class="text-emerald-300/70">protected</span>
                                @endif
                            </p>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                    <strong class="block text-xl text-slate-50">{{ $remotePullRequests->count() }}</strong>
                    <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Open PR</span>
                    <div class="mt-3 space-y-2">
                        @foreach ($remotePullRequests->take(3) as $pullRequest)
                            @if (filled(data_get($pullRequest, 'url')))
                                <a href="{{ data_get($pullRequest, 'url') }}" target="_blank" rel="noopener noreferrer" class="block truncate text-[11px] text-cyan-200 hover:underline">
                                    #{{ data_get($pullRequest, 'number') }} {{ data_get($pullRequest, 'title') }}
                                </a>
                            @else
                                <p class="truncate text-[11px] text-slate-400">#{{ data_get($pullRequest, 'number') }} {{ data_get($pullRequest, 'title') }}</p>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                    <strong class="block text-xl text-slate-50">{{ $remoteIssues->count() }}</strong>
                    <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Open Issues</span>
                    <div class="mt-3 space-y-2">
                        @foreach ($remoteIssues->take(3) as $issue)
                            @if (filled(data_get($issue, 'url')))
                                <a href="{{ data_get($issue, 'url') }}" target="_blank" rel="noopener noreferrer" class="block truncate text-[11px] text-cyan-200 hover:underline">
                                    #{{ data_get($issue, 'number') }} {{ data_get($issue, 'title') }}
                                </a>
                            @else
                                <p class="truncate text-[11px] text-slate-400">#{{ data_get($issue, 'number') }} {{ data_get($issue, 'title') }}</p>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                    <strong class="block text-xl text-slate-50">{{ $remoteRuns->count() }}</strong>
                    <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Recent Actions</span>
                    <div class="mt-3 space-y-2">
                        @foreach ($remoteRuns->take(3) as $run)
                            <div class="min-w-0">
                                @if (filled(data_get($run, 'url')))
                                    <a href="{{ data_get($run, 'url') }}" target="_blank" rel="noopener noreferrer" class="block truncate text-[11px] text-cyan-200 hover:underline">
                                        {{ data_get($run, 'name') ?: 'Actions run' }}
                                    </a>
                                @else
                                    <p class="truncate text-[11px] text-slate-400">{{ data_get($run, 'name') ?: 'Actions run' }}</p>
                                @endif
                                <p class="truncate text-[10px] text-slate-600">
                                    {{ data_get($run, 'branch') }}
                                    · {{ data_get($run, 'conclusion') ?: data_get($run, 'status', 'unknown') }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 text-[10px] text-slate-600">
                @if (data_get($remoteRepository, 'pushed_at'))
                    <span>last push {{ data_get($remoteRepository, 'pushed_at') }}</span>
                @endif
                @if (data_get($remoteRepository, 'stars') !== null)
                    <span>★ {{ (int) data_get($remoteRepository, 'stars') }}</span>
                @endif
                @if (data_get($remoteRepository, 'forks') !== null)
                    <span>fork {{ (int) data_get($remoteRepository, 'forks') }}</span>
                @endif
                @if (data_get($remoteRepository, 'archived'))
                    <span class="text-amber-300/80">archived</span>
                @endif
            </div>

            @if ($remoteWarnings->isNotEmpty())
                <p class="mt-3 text-[10px] leading-5 text-amber-200/70">{{ $remoteWarnings->join(' ') }}</p>
            @endif
        </section>
    @elseif ($overview['repository_registered'])
        <section class="border-b border-dashed border-cyan-300/15 bg-cyan-300/[0.018] p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="max-w-2xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">GITHUB NOW</p>
                    <h4 class="mt-1 text-sm font-black text-slate-100">Repository URLだけで終わらせない</h4>
                    <p class="mt-2 text-xs leading-6 text-slate-500">
                        GitHub App接続済みならPublic / Privateを問わず同期します。未接続RepositoryはPublicの場合だけ限定Previewできます。
                    </p>
                </div>
                @if ($overview['can_edit'] && $canInspectRepository)
                    <form method="POST" action="{{ route('github_workflow.repository.refresh', $overview['repository_artifact_id']) }}">
                        @csrf
                        <button type="submit" class="btn-primary px-3 py-2 text-xs">GitHubから読み込む</button>
                    </form>
                @elseif (! $canInspectRepository)
                    <div class="max-w-md rounded-xl border border-violet-300/15 bg-violet-300/[0.035] px-3 py-2">
                        <p class="text-[10px] font-black text-violet-200">Developer GitHub Evidenceは現在利用不可</p>
                        <p class="mt-1 text-[10px] leading-5 text-slate-500">{{ $evidenceAccessMessage }}</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($overview['repository_registered'])
        <section class="border-b border-violet-300/12 bg-violet-300/[0.018] p-5" data-github-connection>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">GITHUB CONNECTION</p>
                        @if ($connectionStatus === 'connected')
                            <span class="rounded-full border border-emerald-300/15 bg-emerald-300/[0.04] px-2 py-1 text-[10px] font-bold text-emerald-200">GitHub接続済み</span>
                        @elseif (in_array($connectionStatus, ['connecting', 'pending', 'permission_update_required', 'revoked', 'verification_failed'], true))
                            <span class="rounded-full border border-amber-300/15 bg-amber-300/[0.04] px-2 py-1 text-[10px] font-bold text-amber-200">確認が必要</span>
                        @else
                            <span class="rounded-full border border-slate-700 px-2 py-1 text-[10px] font-bold text-slate-500">未接続</span>
                        @endif
                    </div>

                    <h4 class="mt-2 text-sm font-black text-slate-100">RepositoryをGitHub Appで接続</h4>
                    <p class="mt-2 text-xs leading-6 text-slate-500">
                        GitHubでCanovia Appに許可したRepositoryだけを同期します。Public / Privateで導線は変わりません。
                        接続だけならread権限で利用でき、PATやGitHubパスワードをCanoviaへ渡す必要はありません。
                    </p>

                    @if ($connectionReadinessDetail !== '' && ($connectionReadiness['state'] ?? '') !== 'ready')
                        <p class="mt-3 rounded-xl border border-white/8 bg-slate-950/25 px-3 py-2 text-[10px] leading-5 text-slate-500">
                            {{ $connectionReadinessDetail }}
                        </p>
                    @endif
                </div>

                @if ($overview['can_edit'] && $canInspectRepository)
                    <div class="flex flex-wrap gap-2">
                        @if ($connectionStatus !== 'connected' && $githubAppConnectAvailable)
                            <form method="POST" action="{{ route('github_workflow.app.connect', $overview['repository_artifact_id']) }}">
                                @csrf
                                <button type="submit" class="btn-primary px-3 py-2 text-xs">GitHubを接続</button>
                            </form>
                        @endif

                        @if ($connectionStatus !== 'not_connected')
                            <form method="POST" action="{{ route('github_workflow.app.check', $overview['repository_artifact_id']) }}">
                                @csrf
                                <button type="submit" class="btn-secondary px-3 py-2 text-xs">接続状態を確認</button>
                            </form>
                        @endif

                        @if ($connectionManagementUrl)
                            <a href="{{ $connectionManagementUrl }}" target="_blank" rel="noopener noreferrer" class="btn-secondary px-3 py-2 text-xs">
                                GitHubで管理 ↗
                            </a>
                        @endif
                    </div>
                @endif
            </div>

            @if (! $overview['can_edit'])
                <p class="mt-4 text-xs text-slate-500">GitHub接続の変更はCanoviaのEditor以上が行えます。</p>
            @elseif (! $canInspectRepository)
                <div class="mt-4 rounded-xl border border-violet-300/12 bg-violet-300/[0.025] p-3">
                    <p class="text-xs font-bold text-violet-100">GitHub接続は現在利用できません</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">{{ $evidenceAccessMessage }}</p>
                </div>
            @elseif (! $githubWriteConfigured)
                <div class="mt-4 rounded-xl border border-amber-300/12 bg-amber-300/[0.025] p-3">
                    <p class="text-xs font-bold text-amber-100">Canovia運営側のGitHub App設定が必要です</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        App ID / Private KeyはCanovia運営側だけがserver-sideに保持します。一般ユーザーへ秘密鍵やPATを入力させません。
                    </p>
                </div>
            @elseif (! $githubAppConnectAvailable && $connectionStatus !== 'connected')
                <div class="mt-4 rounded-xl border border-amber-300/12 bg-amber-300/[0.025] p-3">
                    <p class="text-xs font-bold text-amber-100">GitHub Appの接続URL設定が必要です</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        Canovia運営側でInstall URLを設定すると、ここからRepository選択へ進めます。
                    </p>
                </div>
            @elseif ($connectionStatus === 'connected')
                <div class="mt-4 rounded-xl border border-emerald-300/12 bg-emerald-300/[0.025] p-3">
                    <p class="text-xs font-bold text-emerald-100">
                        このRepositoryはGitHub Appで同期できます
                        @if ($connectionAccount !== '')
                            · {{ $connectionAccount }}
                        @endif
                    </p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        {{ filled(data_get($remoteRepository, 'visibility'))
                            ? ucfirst((string) data_get($remoteRepository, 'visibility')).' Repository'
                            : 'Public / Private Repository' }}
                        をInstallation scope内でreadします。Webhook / PR / Issue / CommitのEvidenceも同じ接続を使います。
                    </p>
                </div>
            @elseif (in_array($connectionStatus, ['connecting', 'pending'], true))
                <div class="mt-4 rounded-xl border border-amber-300/12 bg-amber-300/[0.025] p-3">
                    <p class="text-xs font-bold text-amber-100">GitHub側の接続完了を待っています</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        OrganizationではOwner承認が必要な場合があります。承認後に「接続状態を確認」を押してください。
                    </p>
                </div>
            @elseif ($connectionStatus === 'permission_update_required')
                <div class="mt-4 rounded-xl border border-amber-300/12 bg-amber-300/[0.025] p-3">
                    <p class="text-xs font-bold text-amber-100">GitHub Appのread権限を確認してください</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        Contents / Pull Requestsのread権限が必要です。IssuesやActionsは付与された権限の範囲だけ同期します。
                    </p>
                </div>
            @elseif (in_array($connectionStatus, ['revoked', 'verification_failed'], true))
                <div class="mt-4 rounded-xl border border-rose-300/12 bg-rose-300/[0.025] p-3">
                    <p class="text-xs font-bold text-rose-100">GitHub接続を再確認してください</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        Installationが削除されたか、対象RepositoryがCanovia Appのscopeから外れた可能性があります。
                    </p>
                </div>
            @else
                <div class="mt-4 rounded-xl border border-dashed border-violet-300/15 bg-violet-300/[0.02] p-4">
                    <p class="text-xs font-bold text-violet-100">GitHub App未接続</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        Private RepositoryをPublicへ変更する必要はありません。「GitHubを接続」から対象Repositoryを許可してください。
                    </p>
                </div>
            @endif

            @if ($connectionStatus === 'connected')
                <div class="mt-5 border-t border-white/8 pt-5" data-github-optional-write>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">OPTIONAL WRITE</p>
                            <h4 class="mt-1 text-sm font-black text-slate-100">Canoviaから変更をレビューに出す</h4>
                            <p class="mt-1 text-[11px] leading-5 text-slate-500">
                                Read接続とは別機能です。必要な場合だけContents / Pull Requestsのwrite権限を使います。
                            </p>
                        </div>
                        @if ($connectionWriteReady)
                            <span class="badge badge-slate">Write ready</span>
                        @else
                            <span class="badge badge-slate">Read only</span>
                        @endif
                    </div>

                    @if (! $canWriteRepository)
                        <p class="mt-3 text-[11px] leading-5 text-slate-500">
                            {{ $writeAccessMessage }} GitHubのread同期はそのまま利用できます。
                        </p>
                    @elseif (! $connectionWriteReady)
                        <div class="mt-3 rounded-xl border border-slate-700 bg-slate-950/25 p-3">
                            <p class="text-xs font-bold text-slate-300">Read接続は完了しています</p>
                            <p class="mt-1 text-[11px] leading-5 text-slate-500">
                                CanoviaからPRを作成したい場合だけ、GitHub側でContents / Pull Requestsのwrite権限を追加してください。
                            </p>
                        </div>
                    @else
                        <details class="mt-3 rounded-2xl border border-white/8 bg-slate-950/35 p-4">
                            <summary class="cursor-pointer list-none text-sm font-black text-slate-100">＋ 変更をレビューに出す</summary>

                            <form method="POST" action="{{ route('github_workflow.repository.change', $overview['repository_artifact_id']) }}" class="mt-4 grid gap-3 lg:grid-cols-2">
                                @csrf

                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-400">変更するファイル</span>
                                    <input type="text" name="file_path" value="{{ old('file_path') }}" class="form-control mt-2 w-full" maxlength="240" required>
                                    <span class="mt-1 block text-[10px] text-slate-600">.github/workflows 配下は対象外です。</span>
                                </label>

                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-400">レビュー用タイトル</span>
                                    <input type="text" name="pull_request_title" value="{{ old('pull_request_title') }}" class="form-control mt-2 w-full" maxlength="240" required>
                                </label>

                                <label class="block lg:col-span-2">
                                    <span class="text-xs font-semibold text-slate-400">ファイルの新しい内容</span>
                                    <textarea name="file_content" class="form-control mt-2 min-h-64 w-full font-mono text-xs" maxlength="200000" required>{{ old('file_content') }}</textarea>
                                </label>

                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-400">変更メモ</span>
                                    <input type="text" name="commit_message" value="{{ old('commit_message') }}" class="form-control mt-2 w-full" maxlength="240" required>
                                </label>

                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-400">レビュー説明（任意）</span>
                                    <textarea name="pull_request_body" class="form-control mt-2 min-h-24 w-full text-xs" maxlength="20000">{{ old('pull_request_body') }}</textarea>
                                </label>

                                <div class="lg:col-span-2">
                                    <button type="submit" class="btn-primary w-full justify-center">変更をレビューに出す</button>
                                </div>
                            </form>
                        </details>
                    @endif
                </div>
            @endif
        </section>
    @endif

    <div class="grid gap-0 lg:grid-cols-[1fr_1fr_1.15fr]">
        <section class="border-b border-white/8 p-5 lg:border-b-0 lg:border-r">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">CANOVIA REFERENCES</p>
            <h4 class="mt-1 text-sm font-black text-slate-100">Canoviaへ明示的に登録したGitHub項目</h4>

            <div class="mt-4 grid grid-cols-3 gap-2">
                @foreach ([
                    ['PR', $kindCounts['pull_request']],
                    ['Issue', $kindCounts['issue']],
                    ['Branch', $kindCounts['branch']],
                    ['Commit', $kindCounts['commit']],
                    ['Actions', $kindCounts['actions_run']],
                    ['Other', $kindCounts['other']],
                ] as [$label, $count])
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] px-3 py-2">
                        <strong class="block text-base text-slate-100">{{ $count }}</strong>
                        <span class="text-[10px] text-slate-600">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            @if ($overview['work_count'] === 0)
                <div class="mt-4 rounded-xl border border-dashed border-cyan-300/15 bg-cyan-300/[0.02] p-3">
                    <p class="text-xs font-bold text-cyan-100">Canovia側の個別項目はまだありません</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        GitHub snapshotは全体把握、個別ArtifactはCanovia上でTaskや判断状態と結び付けたい項目に使います。
                    </p>
                </div>
            @endif
        </section>

        <section class="border-b border-white/8 p-5 lg:border-b-0 lg:border-r">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CANOVIA FLOW</p>
            <h4 class="mt-1 text-sm font-black text-slate-100">Canoviaでは今どこで止まっているか</h4>

            <div class="mt-4 space-y-2">
                @foreach ([
                    ['now', '今やる'],
                    ['review', 'レビュー待ち'],
                    ['changes', '修正必要'],
                    ['merge', 'マージ待ち'],
                    ['done', '完了'],
                    ['unclassified', '未整理'],
                ] as [$key, $label])
                    <div class="flex items-center justify-between rounded-xl border border-white/8 bg-white/[0.02] px-3 py-2">
                        <span class="text-xs text-slate-400">{{ $label }}</span>
                        <strong class="text-xs text-slate-100">{{ $workflowCounts[$key] }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="p-5">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">CONNECTED WORK</p>
            <h4 class="mt-1 text-sm font-black text-slate-100">このRepositoryにつながる作業</h4>

            @if ($linkedTasks->isNotEmpty())
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($linkedTasks->take(5) as $task)
                        <span class="rounded-full border border-emerald-300/12 bg-emerald-300/[0.025] px-2.5 py-1.5 text-[11px] text-slate-300">
                            {{ $task['title'] }}
                        </span>
                    @endforeach
                    @if ($linkedTasks->count() > 5)
                        <span class="rounded-full border border-slate-800 px-2.5 py-1.5 text-[11px] text-slate-500">+{{ $linkedTasks->count() - 5 }}</span>
                    @endif
                </div>
            @else
                <p class="mt-4 text-xs leading-5 text-slate-500">まだTaskとの明示的な紐付けはありません。</p>
            @endif

            @if ($recentItems->isNotEmpty())
                <div class="mt-5 space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">RECENT REFERENCES</p>
                    @foreach ($recentItems as $item)
                        <a
                            href="#github-item-{{ $item['id'] }}"
                            class="flex items-center justify-between gap-3 rounded-xl border border-white/8 bg-white/[0.02] px-3 py-2 transition hover:border-cyan-300/20"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-xs font-semibold text-slate-300">{{ $item['reference'] }}</span>
                                <span class="mt-0.5 block truncate text-[10px] text-slate-600">{{ $item['title'] }}</span>
                            </span>
                            <span class="shrink-0 text-[10px] text-slate-500">{{ $item['workflow_state_label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="border-t border-white/8 px-5 py-3">
        <p class="text-[10px] leading-5 text-slate-600">
            GitHub snapshotは取得時点のread-only情報です。Canoviaの「今やる / レビュー待ち」等は別の判断レイヤーで、GitHubのopen / merged / CI状態から自動変更しません。
            read-only snapshotのユーザー別Private Repository接続はまだありません。GitHub Writeは、Repository管理者が明示的にinstallしたCanovia GitHub Appの権限だけを使います。
        </p>
    </div>
</article>
