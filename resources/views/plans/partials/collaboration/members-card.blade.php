<article class="{{ $cardClass ?? 'page-card p-6' }}">
                <div>
                    <h2 class="text-lg font-bold text-slate-50">参加メンバー</h2>
                    <p class="mt-1 text-sm text-slate-400">閲覧者は見るだけ。編集者はタスク更新や作業記録ができます。</p>
                </div>

                <div class="mt-5 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-400/20 bg-amber-400/5 p-4">
                        <div class="min-w-0">
                            <strong class="block truncate text-slate-50">{{ $plan->user?->name ?? 'オーナー' }}</strong>
                            @if ($canManage ?? false)<span class="text-xs text-slate-400">{{ $plan->user?->email }}</span>@endif
                        </div>
                        <span class="badge badge-green">オーナー</span>
                    </div>

                    @forelse ($plan->memberships as $member)
                        <div class="rounded-2xl border border-slate-700/80 bg-slate-950/35 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <strong class="block truncate text-slate-50">{{ $member->user?->name ?? 'メンバー' }}</strong>
                                    @if ($canManage ?? false)<span class="text-xs text-slate-400">{{ $member->user?->email }}</span>@endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($canManage ?? false)
                                        <form method="POST" action="{{ route('plans.collaboration.members.update', [$plan, $member]) }}" class="flex items-center gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <select name="role" class="form-control py-2 text-sm" onchange="this.form.submit()">
                                                <option value="viewer" @selected($member->role === 'viewer')>閲覧者</option>
                                                <option value="editor" @selected($member->role === 'editor')>編集者</option>
                                            </select>
                                        </form>
                                        <form method="POST" action="{{ route('plans.collaboration.members.remove', [$plan, $member]) }}" onsubmit="return confirm('このメンバーを共同計画から外しますか？');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-secondary px-3 py-2 text-xs">外す</button>
                                        </form>
                                    @else
                                        <span class="badge badge-slate">{{ $member->role === 'editor' ? '編集者' : '閲覧者' }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">まだ参加者はいません。共有URLか参加コードを送ってみましょう。</div>
                    @endforelse
                </div>
            </article>