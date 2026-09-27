@extends(in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'Inbox | Canovia')

@section('content')
    <div class="mx-auto max-w-6xl space-y-5">
        <section class="page-card border-cyan-300/20 p-5 sm:p-6" data-onboarding-target="inbox-capture" data-guide-target="inbox-capture">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <p class="text-xs font-black uppercase tracking-[0.16em] text-cyan-300">CANOVIA INBOX</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">とりあえず、ここに渡す</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-300">分類はあとで大丈夫です。思いつき、URL、スクリーンショット、参考書の写真、PDFなどをCanoviaへ預けておけます。</p>
                </div>
                <span class="badge {{ $pendingCount > 0 ? 'badge-green' : 'badge-slate' }}">未整理 {{ $pendingCount }}件</span>
            </div>

            @if (session('success'))
                <div class="assistant-notice assistant-notice-success mt-4">{{ session('success') }}</div>
            @endif
            @if (session('status'))
                <div class="assistant-notice assistant-notice-info mt-4">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('inbox.store') }}" enctype="multipart/form-data" class="mt-5 space-y-4" data-mutation-once>
                @csrf

                <div class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <label for="inbox-content" class="text-xs font-bold text-slate-300">テキスト・メモ</label>
                        <textarea id="inbox-content" name="content" rows="6" class="input-field mt-2 w-full" placeholder="思いついたこと、やりたいこと、作業結果、教材の文章など">{{ old('content') }}</textarea>
                        @error('content')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label for="inbox-url" class="text-xs font-bold text-slate-300">URL</label>
                            <input id="inbox-url" type="url" name="source_url" value="{{ old('source_url') }}" class="input-field mt-2 w-full" placeholder="https://...">
                            @error('source_url')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="inbox-file" class="text-xs font-bold text-slate-300">画像 / スクリーンショット / PDF</label>
                            <input id="inbox-file" type="file" name="source_file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" class="input-field mt-2 w-full">
                            <p class="mt-2 text-[10px] leading-4 text-slate-600">最大10MB。ファイルは公開領域ではなくprivate storageへ保存します。</p>
                            @error('source_file')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                <details class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                    <summary class="cursor-pointer text-xs font-bold text-slate-300">任意：タイトル・関連Plan</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="inbox-title" class="text-[11px] font-bold text-slate-400">タイトル</label>
                            <input id="inbox-title" type="text" name="title" value="{{ old('title') }}" class="input-field mt-1 w-full" placeholder="未入力なら自動で仮タイトル">
                        </div>
                        <div>
                            <label for="inbox-plan" class="text-[11px] font-bold text-slate-400">関連Plan</label>
                            <select id="inbox-plan" name="plan_id" class="input-field mt-1 w-full">
                                <option value="">あとで決める</option>
                                @foreach ($editablePlans as $plan)
                                    <option value="{{ $plan->id }}" @selected((string) old('plan_id') === (string) $plan->id)>{{ $plan->displayIcon() }} {{ $plan->title }}</option>
                                @endforeach
                            </select>
                            @error('plan_id')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn-primary">Inboxへ入れる</button>
            </form>
        </section>

        <section class="grid gap-4 lg:grid-cols-[1.3fr_0.7fr]">
            <div class="page-card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.16em] text-violet-300">UNSORTED</p>
                        <h2 class="mt-1 text-lg font-black text-slate-50">まだ整理していないもの</h2>
                    </div>
                    <span class="badge badge-slate">{{ $items->count() }}件</span>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($items as $inboxItem)
                        <article id="inbox-item-{{ $inboxItem->id }}" class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="badge badge-slate">{{ $inboxItem->sourceLabel() }}</span>
                                        @if ($inboxItem->plan)
                                            <span class="text-[10px] text-slate-500">{{ $inboxItem->plan->displayIcon() }} {{ $inboxItem->plan->title }}</span>
                                        @else
                                            <span class="text-[10px] text-slate-600">Plan未指定</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 break-words text-sm font-black text-slate-100">{{ $inboxItem->displayTitle() }}</h3>
                                    @if ($inboxItem->content)
                                        <p class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-400">{{ str($inboxItem->content)->limit(280) }}</p>
                                    @endif
                                    @if ($inboxItem->source_url)
                                        <a href="{{ $inboxItem->source_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 block break-all text-xs font-bold text-cyan-300 hover:text-cyan-200">{{ str($inboxItem->source_url)->limit(100) }}</a>
                                    @endif
                                    @if ($inboxItem->storage_path)
                                        <a href="{{ route('inbox.file', $inboxItem) }}" target="_blank" class="mt-2 inline-flex text-xs font-bold text-cyan-300 hover:text-cyan-200">添付ファイルを確認 →</a>
                                    @endif
                                    <p class="mt-2 text-[10px] text-slate-600">{{ $inboxItem->created_at?->diffForHumans() }}</p>
                                </div>
                            </div>
                            @php
                                $routingSuggestion = data_get($inboxItem->metadata, 'routing_suggestion');
                                $suggestedDestination = data_get($routingSuggestion, 'destination', 'keep_inbox');
                                $suggestedPlanTitle = data_get($routingSuggestion, 'suggested_plan_title');
                                $suggestedTaskTitle = data_get($routingSuggestion, 'suggested_task_title');
                            @endphp

                            @if ($routingSuggestion)
                                <div class="mt-3 rounded-xl border border-cyan-300/15 bg-cyan-300/[0.035] p-3" data-guide-target="inbox-routing-review">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs font-black text-cyan-100">Canoviaの整理候補: {{ $routingDestinations[$suggestedDestination] ?? 'Inboxに残す' }}</p>
                                        <span class="badge badge-slate">確信度 {{ (int) data_get($routingSuggestion, 'confidence', 0) }}/100</span>
                                    </div>
                                    <p class="mt-2 text-xs leading-5 text-slate-400">{{ data_get($routingSuggestion, 'reason') ?: '内容から整理先候補を作りました。' }}</p>
                                    @if ($suggestedPlanTitle || $suggestedTaskTitle)
                                        <p class="mt-2 text-[10px] text-slate-600">候補名: {{ $suggestedPlanTitle ?: 'Plan未特定' }}{{ $suggestedTaskTitle ? ' / '.$suggestedTaskTitle : '' }}。IDはAIに決めさせず、下で人が選びます。</p>
                                    @endif
                                </div>
                            @endif

                            <div class="mt-3 border-t border-white/6 pt-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    @auth
                                        <form method="POST" action="{{ route('companion.entry') }}" data-mutation-once>
                                            @csrf
                                            <input type="hidden" name="entry_type" value="inbox_item">
                                            <input type="hidden" name="inbox_item_id" value="{{ $inboxItem->id }}">
                                            @if ($inboxItem->plan_id)
                                                <input type="hidden" name="plan_id" value="{{ $inboxItem->plan_id }}">
                                            @endif
                                            <input type="hidden" name="source_path" value="{{ request()->getRequestUri() }}#inbox-item-{{ $inboxItem->id }}">
                                            <input type="hidden" name="source_route" value="{{ request()->route()?->getName() }}">
                                            <button type="submit" class="btn-secondary px-3 py-2 text-xs">✦ Companionで整理</button>
                                        </form>
                                    @endauth
                                    @if ($canUseInboxAi)
                                        <form method="POST" action="{{ route('inbox.suggest', $inboxItem) }}" data-mutation-once>
                                            @csrf
                                            <button type="submit" class="{{ $routingSuggestion ? 'btn-secondary' : 'btn-primary' }} px-3 py-2 text-xs" data-guide-target="inbox-suggest">{{ $routingSuggestion ? '整理候補を作り直す' : '✦ 行き先を提案' }}</button>
                                        </form>
                                    @endif
                                    <span class="text-[10px] text-slate-600">AIを使わなくても手動で整理できます。</span>
                                </div>

                                <details class="mt-3 rounded-xl border border-white/8 bg-slate-950/20 p-3" @if($routingSuggestion) open @endif>
                                    <summary class="cursor-pointer text-xs font-bold text-slate-300">整理先を確認して確定</summary>
                                    <form method="POST" action="{{ route('inbox.route', $inboxItem) }}" class="mt-3 space-y-3" data-mutation-once data-guide-target="inbox-route">
                                        @csrf
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label class="text-[10px] font-bold text-slate-500">整理先</label>
                                                <select name="destination" class="input-field mt-1 w-full">
                                                    @foreach ($routingDestinations as $destinationKey => $destinationLabel)
                                                        <option value="{{ $destinationKey }}" @selected($suggestedDestination === $destinationKey)>{{ $destinationLabel }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-[10px] font-bold text-slate-500">Plan</label>
                                                <select name="plan_id" class="input-field mt-1 w-full">
                                                    <option value="">Planなし / あとで</option>
                                                    @foreach ($editablePlans as $plan)
                                                        <option value="{{ $plan->id }}" @selected((int) $inboxItem->plan_id === (int) $plan->id)>{{ $plan->displayIcon() }} {{ $plan->title }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-[10px] font-bold text-slate-500">Task</label>
                                                <select name="task_id" class="input-field mt-1 w-full">
                                                    <option value="">Taskなし / あとで</option>
                                                    @foreach ($editablePlans as $plan)
                                                        @foreach ($plan->tasks->whereNotIn('status', ['done', 'cancelled'])->sortBy('sort_order') as $task)
                                                            <option value="{{ $task->id }}">{{ $plan->title }} / {{ $task->title }}</option>
                                                        @endforeach
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <p class="text-[10px] leading-4 text-slate-600">Recall教材は資格学習Plan + Taskが必要で、Candidate抽出時のみAutomatic AIを使います。Evidenceは確認事実として記録しますが、これだけでTask進捗は自動加算しません。</p>
                                        <button type="submit" class="btn-primary px-3 py-2 text-xs">この整理先で確定</button>
                                    </form>
                                </details>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('inbox.status', $inboxItem) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="processed">
                                        <button type="submit" class="btn-secondary px-3 py-2 text-xs">整理済みにする</button>
                                    </form>
                                    <form method="POST" action="{{ route('inbox.status', $inboxItem) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="archived">
                                        <button type="submit" class="btn-secondary px-3 py-2 text-xs">アーカイブ</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-white/10 p-5 text-center">
                            <p class="text-sm font-bold text-slate-300">未整理のInbox Itemはありません。</p>
                            <p class="mt-1 text-xs text-slate-500">何か気づいたら、分類を考えず上から入れておけます。</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="page-card p-5 sm:p-6">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-amber-300">PENDING ACROSS CANOVIA</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">他の場所で確認待ち</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">既存機能のpendingデータをコピーせず、ここから確認できます。</p>

                <div class="mt-4 space-y-4">
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-bold text-slate-200">Recall Candidate</h3>
                            <span class="badge badge-slate">{{ $recallCandidates->count() }}</span>
                        </div>
                        <div class="mt-2 space-y-2">
                            @forelse ($recallCandidates->take(5) as $candidate)
                                <a href="{{ route('plans.tasks.study_recall.show', [$candidate->plan, $candidate->task]) }}" class="block rounded-xl border border-white/8 bg-white/[0.02] p-3 transition hover:border-emerald-300/20">
                                    <p class="text-xs font-bold text-slate-200">{{ str($candidate->prompt)->limit(70) }}</p>
                                    <p class="mt-1 text-[10px] text-slate-500">{{ $candidate->plan?->title }} / {{ $candidate->task?->title }} · 根拠 {{ (int) $candidate->confidence }}/100</p>
                                </a>
                            @empty
                                <p class="text-xs text-slate-600">確認待ちはありません。</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="border-t border-white/8 pt-4">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-bold text-slate-200">Career Capture</h3>
                            <span class="badge badge-slate">{{ $careerCaptures->count() }}</span>
                        </div>
                        <div class="mt-2 space-y-2">
                            @forelse ($careerCaptures->take(5) as $capture)
                                <a href="{{ route('plans.career.index', $capture->plan) }}" class="block rounded-xl border border-white/8 bg-white/[0.02] p-3 transition hover:border-fuchsia-300/20">
                                    <p class="text-xs font-bold text-slate-200">{{ $capture->sourceLabel() }}を整理</p>
                                    <p class="mt-1 text-[10px] text-slate-500">{{ $capture->plan?->title }} · {{ $capture->captured_at?->diffForHumans() }}</p>
                                </a>
                            @empty
                                <p class="text-xs text-slate-600">確認待ちはありません。</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="border-t border-white/8 pt-4">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-bold text-slate-200">Planへ未反映</h3>
                            <span class="badge badge-slate">{{ $pendingPlanUpdates->count() }}</span>
                        </div>
                        <div class="mt-2 space-y-2">
                            @forelse ($pendingPlanUpdates->take(5) as $session)
                                <a href="{{ route('plans.review_assistant.show', ['plan' => $session->plan, 'work_session_id' => $session->id]) }}" class="block rounded-xl border border-white/8 bg-white/[0.02] p-3 transition hover:border-cyan-300/20">
                                    <p class="text-xs font-bold text-slate-200">{{ $session->task?->title ?? '作業結果' }}をPlanへ反映</p>
                                    <p class="mt-1 text-[10px] text-slate-500">{{ $session->plan?->title }} · {{ $session->ended_at?->diffForHumans() }}</p>
                                </a>
                            @empty
                                <p class="text-xs text-slate-600">未反映の作業結果はありません。</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if ($recentItems->isNotEmpty())
            <details class="page-card p-4 sm:p-5">
                <summary class="cursor-pointer text-sm font-black text-slate-200">最近整理したInbox Item</summary>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($recentItems as $inboxItem)
                        <div class="rounded-xl border border-white/8 bg-white/[0.02] p-3">
                            <p class="text-xs font-bold text-slate-300">{{ $inboxItem->displayTitle() }}</p>
                            <p class="mt-1 text-[10px] text-slate-600">{{ $inboxItem->sourceLabel() }} · {{ $inboxItem->status === 'archived' ? 'アーカイブ' : '整理済み' }} · {{ $inboxItem->processed_at?->diffForHumans() }}</p>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        <p class="px-1 text-[11px] leading-5 text-slate-600">AIの提案は確定操作ではありません。Canoviaは整理先候補だけを示し、実際のPlan / Taskと変換先は人が確認してから確定します。</p>
    </div>
@endsection
