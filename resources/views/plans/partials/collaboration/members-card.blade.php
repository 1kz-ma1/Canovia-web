<article class="{{ $cardClass ?? 'page-card p-6' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-slate-50">共同計画に参加中</h2>
                        <p class="mt-1 text-sm text-slate-400">この計画の最新情報や参加メンバーをここで確認できます。</p>
                    </div>
                    <span class="badge badge-green">{{ ($collaborationRole ?? null) === 'editor' ? '編集者' : '閲覧者' }}</span>
                </div>
            </article>