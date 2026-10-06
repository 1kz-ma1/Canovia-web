<section
            id="development-github-activity"
            class="page-card border-cyan-300/15 p-5 sm:p-6"
            data-development-home-recent-activity
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">RECENT GITHUB REALITY</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">最近GitHubで何が起きたか</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        観測した事実を新しい順に表示します。Task候補は自動確定せず、必要なものだけ確認して関連付けます。
                    </p>
                </div>
                @if ($unresolvedActivity->isNotEmpty())
                    <span class="badge badge-slate">{{ $unresolvedActivity->count() }}件 要整理</span>
                @elseif ($recentActivity->isNotEmpty())
                    <span class="badge badge-slate">整理済み</span>
                @endif
            </div>

            @if ($recentActivity->isEmpty())
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-5" data-development-github-connection>
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-black text-slate-200">GitHub Activityはまだありません。</p>
                                @if ($githubAppConnectionStatus === 'connected')
                                    <span class="badge badge-slate">Connected</span>
                                @elseif ($githubRepository)
                                    <span class="badge badge-slate">Not connected</span>
                                @endif
                            </div>
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                @if ($githubAppConnectionStatus === 'connected')
                                    RepositoryはGitHub Appに接続済みです。次のWebhook / Bootstrap同期でPR・Issue・Commitなどの現実Stateがここに入ります。
                                @elseif ($githubRepository)
                                    Private RepositoryをPublicへ変更する必要はありません。GitHub AppにこのRepositoryを許可すると同じDeveloper Homeへ同期できます。
                                @else
                                    GitHub未接続でもDeveloper Homeは使えます。Repositoryを登録してGitHub Appを接続すると、PR・Issue・Commitなどを自動で取り込めます。
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            @if (
                                $canEdit
                                && $githubRepository
                                && $githubAppConnectionStatus !== 'connected'
                                && $githubEvidenceAllowed
                                && $githubConnectAvailable
                            )
                                <form method="POST" action="{{ route('github_workflow.app.connect', $githubRepository) }}">
                                    @csrf
                                    <button type="submit" class="btn-primary min-h-10 px-3 text-xs">GitHubを接続</button>
                                </form>
                            @endif

                            <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary min-h-10 px-3 text-xs">
                                {{ $githubRepository ? '接続設定' : 'Repositoryを追加' }}
                            </a>
                        </div>
                    </div>

                    @if ($githubRepository && $githubAppConnectionStatus !== 'connected' && filled(data_get($githubConnection, 'detail')))
                        <p class="mt-3 text-[11px] leading-5 text-slate-600">{{ data_get($githubConnection, 'detail') }}</p>
                    @endif
                </div>
            @else
                <div class="mt-4 grid gap-3 xl:grid-cols-2">
                    @foreach ($recentActivity as $observation)
                        @php
                            $suggestedTask = $observation->suggestedTask;
                            $resolvedArtifact = $observation->resolvedArtifact;
                            $linkedTask = $resolvedArtifact ? $resolvedArtifact->tasks->first() : null;
                            $confidence = $observation->suggestion_confidence !== null
                                ? (int) round(((float) $observation->suggestion_confidence) * 100)
                                : null;
                            $label = $observationKindLabels[$observation->kind] ?? $observation->kind;
                            $displayTitle = trim((string) $observation->title);
                            if ($displayTitle === '') {
                                $displayTitle = match ($observation->kind) {
                                    'pull_request' => 'PR #'.(int) $observation->provider_number,
                                    'issue' => 'Issue #'.(int) $observation->provider_number,
                                    'branch' => (string) $observation->ref,
                                    'commit' => 'Commit '.mb_substr((string) $observation->sha, 0, 10),
                                    'deployment' => 'Deployment #'.(int) $observation->provider_number,
                                    default => 'GitHub activity',
                                };
                            }
                            $canAssociateObservation = $unresolvedActivityIds->contains((int) $observation->id);
                        @endphp

                        <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-development-observation="{{ $observation->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-slate">{{ $label }}</span>
                                @if ($observation->state)
                                    <span class="text-[10px] font-bold text-slate-500">{{ $observation->state }}</span>
                                @endif
                                @if ($linkedTask)
                                    <span class="text-[10px] font-black text-emerald-300">Task連携済み</span>
                                @elseif ($canAssociateObservation)
                                    <span class="text-[10px] font-black text-amber-200">要整理</span>
                                @else
                                    <span class="text-[10px] font-black text-slate-500">観測済み</span>
                                @endif
                                @if ($observation->last_observed_at)
                                    <span class="ml-auto text-[10px] text-slate-600">{{ $observation->last_observed_at->format('m/d H:i') }}</span>
                                @endif
                            </div>

                            <div class="mt-2 min-w-0">
                                @if ($observation->url)
                                    <a href="{{ $observation->url }}" target="_blank" rel="noopener noreferrer" class="break-words text-sm font-black text-slate-100 hover:text-cyan-200">
                                        {{ $displayTitle }}
                                    </a>
                                @else
                                    <p class="break-words text-sm font-black text-slate-100">{{ $displayTitle }}</p>
                                @endif

                                @if ($observation->ref)
                                    <p class="mt-1 break-all text-[11px] text-slate-600">{{ $observation->ref }}</p>
                                @endif

                                @if ($linkedTask)
                                    <p class="mt-3 text-xs text-emerald-200">Task: {{ $linkedTask->title }}</p>
                                @elseif ($suggestedTask && $canAssociateObservation)
                                    <p class="mt-3 text-xs text-cyan-200" data-development-observation-suggestion>
                                        候補: {{ $suggestedTask->title }}
                                        @if ($confidence !== null)
                                            <span class="text-slate-500">· {{ $confidence }}%</span>
                                        @endif
                                    </p>
                                @elseif ($canAssociateObservation)
                                    <p class="mt-3 text-xs text-slate-600">候補を一意に決められませんでした。Taskを選んでください。</p>
                                @endif
                            </div>

                            @if ($canEdit && $canAssociateObservation)
                                <form method="POST" action="{{ route('plans.development_observations.link', [$plan, $observation]) }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
                                    @csrf
                                    <select name="task_id" required class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-xs font-bold text-slate-100">
                                        <option value="">Taskを選択</option>
                                        @foreach ($associationTasks as $associationTask)
                                            <option value="{{ $associationTask->id }}" @selected($suggestedTask && (int) $suggestedTask->id === (int) $associationTask->id)>
                                                {{ $associationTask->title }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn-primary min-h-10 px-3 text-xs">関連付ける</button>
                                </form>
                                <form method="POST" action="{{ route('plans.development_observations.ignore', [$plan, $observation]) }}" class="mt-2 text-right">
                                    @csrf
                                    <button type="submit" class="text-[11px] font-bold text-slate-600 hover:text-slate-400">今回は無視</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        