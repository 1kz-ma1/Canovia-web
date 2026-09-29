@php
    $connectedRepositories = collect($githubRepositories ?? [])
        ->filter(fn ($repository) =>
            (string) data_get($repository->metadata, 'github_app_connection.status', '') === 'connected'
        )
        ->values();
    $candidate = is_array($githubChangeCandidate ?? null) ? $githubChangeCandidate : null;
    $handoffResult = is_array($githubHandoffResult ?? null) ? $githubHandoffResult : null;
@endphp

@if ($packet || $candidate || $handoffResult)
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
                    <p><span class="text-slate-600">Commit</span><br>{{ IlluminateSupportStr::limit((string) data_get($handoffResult, 'commit_sha'), 12, '') }}</p>
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

                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.confirm', [$plan, $task]) }}">
                            @csrf
                            <button type="submit" class="btn-primary">確認してレビューに出す</button>
                        </form>
                        <form method="POST" action="{{ route('plans.tasks.execution_orchestration.github.discard', [$plan, $task]) }}">
                            @csrf
                            <button type="submit" class="btn-secondary">候補を破棄</button>
                        </form>
                    </div>
                </div>
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
