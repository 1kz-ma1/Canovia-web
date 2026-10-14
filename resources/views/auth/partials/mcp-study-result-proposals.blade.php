@if (config('canovia_mcp.study_result_proposals_enabled') === true && config('app.env') !== 'staging')
    <section class="page-card min-w-0 p-5 sm:p-7" data-mcp-study-result-review>
        <h2 class="text-lg font-bold text-slate-100">ChatGPTから届いた学習結果の確認</h2>
        <p class="mt-2 text-xs leading-5 text-slate-400">
            ChatGPTからの送信だけでは学習実績に反映されません。
            正答数・出題数・弱点分野を確認し、本人が「保存する」を押した場合にだけ記録します。
            Task進捗と既存の採点済み問題は変更しません。
        </p>
        @forelse ($mcpStudyResultProposals as $candidate)
            @php
                $ownedPlan = $candidate->plan && (int) $candidate->plan->user_id === (int) $user->id;
                $ownedTask = $candidate->task && (int) $candidate->task->plan_id === (int) $candidate->plan_id;
            @endphp
            @if ($ownedPlan && $ownedTask)
                <div class="mt-4 rounded-xl border border-white/10 p-4" data-mcp-study-result-proposal>
                    <p class="break-words text-sm font-semibold text-slate-100">{{ $candidate->plan->title }}</p>
                    <p class="mt-1 break-words text-xs text-slate-300">Task：{{ $candidate->task->title }}</p>
                    <p class="mt-2 text-sm text-slate-200">
                        正答 {{ $candidate->correct_count }} / {{ $candidate->question_count }} 問
                        （{{ (int) round(100 * $candidate->correct_count / $candidate->question_count) }}%）
                    </p>
                    <p class="mt-1 break-words text-xs text-amber-200">申告された弱点：{{ implode('・', $candidate->weakness_topics ?? []) ?: 'なし' }}</p>
                    <p class="mt-1 text-xs text-slate-400">確認期限：{{ $candidate->expires_at->format('Y-m-d H:i') }}。AIによる採点結果は未検証です。</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('auth.account.mcp_study_result.decide', ['proposal' => $candidate->id]) }}">
                            @csrf
                            <input type="hidden" name="decision" value="approve">
                            <button class="btn-primary min-h-11 px-4 text-xs" type="submit">この結果を保存する</button>
                        </form>
                        <form method="POST" action="{{ route('auth.account.mcp_study_result.decide', ['proposal' => $candidate->id]) }}">
                            @csrf
                            <input type="hidden" name="decision" value="reject">
                            <button class="btn-secondary min-h-11 px-4 text-xs" type="submit">保存せずに破棄する</button>
                        </form>
                    </div>
                </div>
            @endif
        @empty
            <p class="mt-3 text-sm text-slate-400">確認待ちの学習結果はありません。</p>
        @endforelse
    </section>
@endif
