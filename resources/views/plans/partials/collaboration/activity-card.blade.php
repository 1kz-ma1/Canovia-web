<article class="{{ $cardClass ?? 'page-card p-5' }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-slate-50">最新情報</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-500">共同計画で最近行われた更新です。</p>
                    </div>
                    <span class="badge badge-slate">{{ ($recentActivities ?? collect())->count() }}件</span>
                </div>

                @php
                    $activityLabels = [
                        'member_joined' => '共同計画に参加',
                        'member_role_changed' => 'メンバー権限を変更',
                        'member_removed' => 'メンバーを外しました',
                        'plan_updated' => '計画を更新',
                        'task_created' => 'タスクを追加',
                        'task_updated' => 'タスクを更新',
                        'task_completed' => 'タスクを完了',
                        'task_deleted' => 'タスクを削除',
                        'plan_ai_updated' => 'AI更新を反映',
                        'resource_created' => '関連資料を追加',
                        'resource_updated' => '関連資料を更新',
                        'resource_deleted' => '関連資料を削除',
                        'resource_ai_assigned' => 'AIで資料を整理',
                        'artifact_created' => '制作ファイルを追加',
                        'artifact_updated' => '制作ファイルを更新',
                        'artifact_deleted' => '制作ファイルを削除',
                        'invite_regenerated' => '招待情報を再発行',
                    ];
                @endphp
                <div class="mt-4 space-y-3">
                    @forelse (($recentActivities ?? collect()) as $activity)
                        @php
                            $meta = $activity->metadata ?? [];
                            $targetTitle = $meta['task_title'] ?? $meta['resource_title'] ?? $meta['artifact_title'] ?? $meta['member_name'] ?? null;
                            $actorName = $activity->user?->name ?? 'Canovia';
                        @endphp
                        <div class="rounded-2xl border border-white/8 bg-white/[0.035] p-3">
                            <div class="flex items-start gap-3">
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-cyan-300 shadow-[0_0_12px_rgba(103,232,249,.7)]"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm leading-6 text-slate-200">
                                        <strong class="text-slate-50">{{ $actorName }}</strong>が{{ $activityLabels[$activity->action] ?? '計画を更新' }}@if($targetTitle)<span class="text-slate-400">「{{ $targetTitle }}」</span>@endif
                                    </p>
                                    <p class="mt-1 text-[11px] text-slate-500">{{ $activity->created_at?->diffForHumans() }}</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-2xl border border-dashed border-slate-700 p-4 text-sm leading-6 text-slate-500">まだ共同更新はありません。参加や編集が行われるとここに表示されます。</p>
                    @endforelse
                </div>
            </article>