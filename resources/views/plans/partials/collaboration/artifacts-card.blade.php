<article class="{{ $cardClass ?? 'page-card p-6' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-violet-300">PROJECT OUTPUTS</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-50">制作ファイル / 成果物</h2>
                        <p class="mt-1 text-sm text-slate-400">GitHub・Drive・OneDriveなど、制作物の最新版を同じ場所から開けます。</p>
                    </div>
                    <a href="{{ route('plans.artifacts.index', $plan) }}" class="btn-secondary px-3 py-2 text-xs">一覧を開く</a>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse (($recentArtifacts ?? collect()) as $artifact)
                        <a href="{{ $artifact->url }}" target="_blank" rel="noopener noreferrer" class="block rounded-2xl border border-white/8 bg-white/[0.03] p-4 transition hover:border-violet-300/30 hover:bg-violet-300/[0.04]">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <strong class="block truncate text-slate-50">{{ $artifact->title }}</strong>
                                    <p class="mt-1 text-xs text-slate-500">{{ $artifact->providerLabel() }} · 担当 {{ $artifact->assignedUser?->name ?? '未設定' }}</p>
                                </div>
                                @if ($artifact->version_label)<span class="badge badge-green">{{ $artifact->version_label }}</span>@endif
                            </div>
                        </a>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-700 p-4">
                            <p class="text-sm text-slate-500">まだ制作ファイルはありません。</p>
                            @if ($canEdit ?? false)<a href="{{ route('plans.artifacts.index', $plan) }}" class="mt-2 inline-block text-xs font-bold text-cyan-300">最初の制作物を登録 →</a>@endif
                        </div>
                    @endforelse
                </div>
            </article>