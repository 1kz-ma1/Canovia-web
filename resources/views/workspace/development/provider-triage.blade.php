@extends('layouts.app')

@section('title', 'Provider Triage | Canovia')

@section('content')
@php
    $target = is_array(data_get($triage, 'target'))
        ? data_get($triage, 'target')
        : [];
    $action = is_array(data_get($triage, 'action'))
        ? data_get($triage, 'action')
        : [];
    $ci = is_array(data_get($triage, 'ci'))
        ? data_get($triage, 'ci')
        : [];
    $review = is_array(data_get($triage, 'review'))
        ? data_get($triage, 'review')
        : [];
    $ciJobs = collect(data_get($ci, 'jobs', []));
    $ciChecks = collect(data_get($ci, 'check_runs', []));
    $ciAnnotations = collect(data_get($ci, 'annotations', []));
    $ciStatuses = collect(data_get($ci, 'statuses', []));
    $reviews = collect(data_get($review, 'reviews', []));
    $inlineComments = collect(data_get($review, 'inline_comments', []));
    $nextSteps = collect(data_get($triage, 'next_steps', []));
    $warnings = collect(data_get($triage, 'warnings', []));
    $guardrails = collect(data_get($triage, 'guardrails', []));
    $mode = (string) data_get($triage, 'mode', 'auto');
    $showCi = in_array($mode, ['auto', 'ci'], true);
    $showReview = in_array($mode, ['auto', 'review'], true);
@endphp

<div class="mx-auto max-w-6xl space-y-5" data-development-provider-triage data-provider-triage-mode="{{ $mode }}">
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-rose-300/[0.07] via-violet-300/[0.04] to-transparent px-5 py-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-rose-300">PROVIDER-LINKED TRIAGE</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">GitHubの詳細を、現在Taskの文脈で確認する。</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        この画面は明示操作でGitHubから一時取得した情報だけを表示します。Review本文・CI annotationはCanoviaへ保存しません。
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ $backUrl }}" class="btn-secondary min-h-10 px-3 text-xs">Developer Homeへ戻る</a>
                    @if (data_get($target, 'pull_request_url'))
                        <a
                            href="{{ data_get($target, 'pull_request_url') }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn-secondary min-h-10 px-3 text-xs"
                        >
                            Pull Requestを開く ↗
                        </a>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4 sm:p-6">
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">Task</p>
                <p class="mt-2 text-sm font-black text-slate-100">{{ data_get($target, 'task_title', 'unknown') }}</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">Repository</p>
                <p class="mt-2 break-all text-xs font-bold text-slate-200">{{ data_get($target, 'repository') ?: 'unknown' }}</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">Pull Request</p>
                <p class="mt-2 text-sm font-black text-slate-100">
                    {{ data_get($target, 'pull_request_number') ? '#'.(int) data_get($target, 'pull_request_number') : 'unknown' }}
                </p>
                @if (data_get($target, 'pull_request_title'))
                    <p class="mt-1 text-[10px] leading-4 text-slate-600">{{ data_get($target, 'pull_request_title') }}</p>
                @endif
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">Branch / Head</p>
                <p class="mt-2 break-all text-xs font-bold text-slate-200">{{ data_get($target, 'head_ref') ?: 'unknown' }}</p>
                @if (data_get($target, 'head_sha'))
                    <p class="mt-1 text-[10px] text-slate-600">{{ mb_substr((string) data_get($target, 'head_sha'), 0, 12) }}</p>
                @endif
            </div>
        </div>
    </section>

    <section class="page-card border-violet-300/15 p-5 sm:p-6" data-provider-triage-current-action>
        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CURRENT ACTION</p>
        <h2 class="mt-2 text-xl font-black text-slate-50">{{ data_get($action, 'title', 'Provider detailを確認する') }}</h2>
        @if (data_get($action, 'intent'))
            <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-400">{{ data_get($action, 'intent') }}</p>
        @endif
    </section>

    @if ($showCi)
        <section class="page-card border-rose-300/15 p-5 sm:p-6" data-provider-triage-ci>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-rose-300">CI DETAIL</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">失敗箇所をProviderの詳細から絞る</h2>
                </div>
                <div class="flex flex-wrap gap-2 text-[10px] text-slate-500">
                    <span class="rounded-full border border-white/8 px-2 py-1">Jobs {{ $ciJobs->count() }}</span>
                    <span class="rounded-full border border-white/8 px-2 py-1">Checks {{ $ciChecks->count() }}</span>
                    <span class="rounded-full border border-white/8 px-2 py-1">Annotations {{ $ciAnnotations->count() }}</span>
                </div>
            </div>

            @if ($ciJobs->isEmpty() && $ciChecks->isEmpty() && $ciAnnotations->isEmpty() && $ciStatuses->isEmpty())
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-5">
                    <p class="text-sm font-black text-slate-300">CIの詳細失敗情報は取得できませんでした。</p>
                    <p class="mt-2 text-xs leading-5 text-slate-600">
                        Actions / Checks / Statuses権限が不足しているか、現在head SHAに失敗詳細がない可能性があります。
                    </p>
                </div>
            @else
                @if ($ciAnnotations->isNotEmpty())
                    <div class="mt-4 space-y-3">
                        <p class="text-xs font-black text-slate-300">Check annotations</p>
                        @foreach ($ciAnnotations as $annotation)
                            <article class="rounded-2xl border border-rose-300/15 bg-rose-300/[0.025] p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p class="text-xs font-black text-rose-200">{{ data_get($annotation, 'check_name', 'Check') }}</p>
                                        <p class="mt-1 text-[10px] text-slate-600">
                                            {{ data_get($annotation, 'path') ?: 'path unknown' }}
                                            @if (data_get($annotation, 'start_line'))
                                                :{{ (int) data_get($annotation, 'start_line') }}
                                            @endif
                                        </p>
                                    </div>
                                    @if (data_get($annotation, 'level'))
                                        <span class="badge badge-slate">{{ data_get($annotation, 'level') }}</span>
                                    @endif
                                </div>

                                @if (data_get($annotation, 'title'))
                                    <p class="mt-3 text-xs font-bold text-slate-200">{{ data_get($annotation, 'title') }}</p>
                                @endif
                                <p class="mt-2 whitespace-pre-wrap text-xs leading-5 text-slate-400">{{ data_get($annotation, 'message') }}</p>
                            </article>
                        @endforeach
                    </div>
                @endif

                @if ($ciJobs->isNotEmpty())
                    <div class="mt-5 space-y-3">
                        <p class="text-xs font-black text-slate-300">Failed workflow jobs</p>
                        @foreach ($ciJobs as $job)
                            <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-black text-slate-100">{{ data_get($job, 'name', 'Job') }}</p>
                                    <span class="text-[10px] font-black text-rose-300">{{ data_get($job, 'conclusion', 'failure') }}</span>
                                </div>
                                @if (collect(data_get($job, 'steps', []))->isNotEmpty())
                                    <ul class="mt-3 space-y-2 text-xs text-slate-400">
                                        @foreach ((array) data_get($job, 'steps', []) as $step)
                                            <li class="flex gap-2">
                                                <span class="text-rose-300">×</span>
                                                <span>{{ data_get($step, 'name', 'Step') }} · {{ data_get($step, 'conclusion', 'failure') }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if (data_get($job, 'url'))
                                    <a href="{{ data_get($job, 'url') }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-[11px] font-bold text-cyan-300 hover:text-cyan-200">GitHubでjobを見る ↗</a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif

                @if ($ciStatuses->isNotEmpty())
                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                        @foreach ($ciStatuses as $status)
                            <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-black text-slate-200">{{ data_get($status, 'context', 'Status') }}</p>
                                    <span class="text-[10px] font-black text-rose-300">{{ data_get($status, 'state') }}</span>
                                </div>
                                @if (data_get($status, 'description'))
                                    <p class="mt-2 text-[11px] leading-5 text-slate-500">{{ data_get($status, 'description') }}</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            @endif
        </section>
    @endif

    @if ($showReview)
        <section class="page-card border-amber-300/15 p-5 sm:p-6" data-provider-triage-review>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">REVIEW DETAIL</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">レビュー指摘を現在Taskへ落とす</h2>
                </div>
                <div class="flex flex-wrap gap-2 text-[10px] text-slate-500">
                    <span class="rounded-full border border-white/8 px-2 py-1">Reviews {{ $reviews->count() }}</span>
                    <span class="rounded-full border border-white/8 px-2 py-1">Inline {{ $inlineComments->count() }}</span>
                </div>
            </div>

            @if ($reviews->isEmpty() && $inlineComments->isEmpty())
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-5">
                    <p class="text-sm font-black text-slate-300">Review本文・inline commentは見つかりませんでした。</p>
                    <p class="mt-2 text-xs leading-5 text-slate-600">
                        Review stateだけが存在する場合や、GitHub側で本文なしのReviewが送られた場合があります。
                    </p>
                </div>
            @else
                @if ($reviews->isNotEmpty())
                    <div class="mt-4 space-y-3">
                        @foreach ($reviews as $reviewItem)
                            <article class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.025] p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-black text-amber-100">
                                        {{ data_get($reviewItem, 'reviewer') ?: 'reviewer' }}
                                    </p>
                                    <span class="text-[10px] font-black {{ data_get($reviewItem, 'state') === 'CHANGES_REQUESTED' ? 'text-rose-300' : 'text-slate-500' }}">
                                        {{ data_get($reviewItem, 'state', 'COMMENTED') }}
                                    </span>
                                </div>
                                @if (data_get($reviewItem, 'body'))
                                    <p class="mt-3 whitespace-pre-wrap text-xs leading-5 text-slate-400">{{ data_get($reviewItem, 'body') }}</p>
                                @else
                                    <p class="mt-3 text-xs text-slate-600">本文なし</p>
                                @endif
                                @if (data_get($reviewItem, 'url'))
                                    <a href="{{ data_get($reviewItem, 'url') }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-[11px] font-bold text-cyan-300 hover:text-cyan-200">GitHubでReviewを見る ↗</a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif

                @if ($inlineComments->isNotEmpty())
                    <div class="mt-5 space-y-3">
                        <p class="text-xs font-black text-slate-300">Inline comments</p>
                        @foreach ($inlineComments as $comment)
                            <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="break-all text-xs font-black text-slate-200">
                                        {{ data_get($comment, 'path') ?: 'path unknown' }}
                                        @if (data_get($comment, 'line'))
                                            :{{ (int) data_get($comment, 'line') }}
                                        @endif
                                    </p>
                                    <span class="text-[10px] text-slate-600">{{ data_get($comment, 'reviewer') ?: 'reviewer' }}</span>
                                </div>
                                <p class="mt-3 whitespace-pre-wrap text-xs leading-5 text-slate-400">{{ data_get($comment, 'body') }}</p>
                                @if (data_get($comment, 'url'))
                                    <a href="{{ data_get($comment, 'url') }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex text-[11px] font-bold text-cyan-300 hover:text-cyan-200">GitHubでcommentを見る ↗</a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            @endif
        </section>
    @endif

    <section class="page-card border-cyan-300/15 p-5 sm:p-6" data-provider-triage-next-steps>
        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">NEXT STEPS</p>
        <h2 class="mt-1 text-lg font-black text-slate-50">この詳細から次に進めること</h2>

        <ol class="mt-4 space-y-3">
            @foreach ($nextSteps as $step)
                <li class="flex gap-3 text-sm leading-6 text-slate-300">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-cyan-300/20 bg-cyan-300/[0.04] text-[10px] font-black text-cyan-300">{{ $loop->iteration }}</span>
                    <span>{{ $step }}</span>
                </li>
            @endforeach
        </ol>

        <div class="mt-5 rounded-2xl border border-violet-300/14 bg-violet-300/[0.025] p-4" data-provider-triage-agent-handoff>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-violet-300">CODING AGENT HANDOFF</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        Execution Orchestrationへ進む場合は、現在のImplementation Briefだけを再構成して引き継ぎます。
                        この画面で一時取得したReview本文・CI annotationはsessionにも保存しません。
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('plans.tasks.development_coding_agent_handoff.prepare', [$plan, $task]) }}"
                    class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center"
                >
                    @csrf
                    <input type="hidden" name="confirmed" value="1">
                    <button type="submit" class="btn-secondary min-h-10 px-3 text-xs">
                        Implementation BriefからAgent Contextを準備
                    </button>
                </form>
            </div>
        </div>

        <details class="mt-5 rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-provider-triage-copy>
            <summary class="cursor-pointer list-none text-xs font-black text-slate-300">
                このTriageを実装ツールへ渡す
                <span class="ml-2 text-[10px] font-normal text-slate-600">明示コピー時のみ</span>
            </summary>

            <textarea
                id="development-provider-triage-copy"
                readonly
                rows="18"
                class="form-control mt-4 w-full resize-y font-mono text-[11px] leading-5"
            >{{ data_get($triage, 'copy_text') }}</textarea>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-[10px] leading-4 text-slate-600">
                    コピー後の外部ツール利用はユーザー操作です。Canoviaが自動でAgentを起動したりGitHubへ変更を加えることはありません。
                </p>
                <button type="button" class="btn-secondary min-h-9 px-3 text-xs" data-provider-triage-copy-button>
                    Triageをコピー
                </button>
            </div>
        </details>
    </section>

    @if ($warnings->isNotEmpty())
        <section class="page-card border-amber-300/15 p-5 sm:p-6" data-provider-triage-warnings>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">LIMITATIONS</p>
            <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-500">
                @foreach ($warnings as $warning)
                    <li>• {{ $warning }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <details class="page-card p-5 sm:p-6" data-provider-triage-guardrails>
        <summary class="cursor-pointer list-none text-sm font-black text-slate-300">Provider Triageの境界</summary>
        <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-600">
            @foreach ($guardrails as $guardrail)
                <li>• {{ $guardrail }}</li>
            @endforeach
        </ul>
    </details>
</div>

<script>
    (() => {
        const button = document.querySelector('[data-provider-triage-copy-button]');
        const target = document.getElementById('development-provider-triage-copy');

        if (!button || !target) return;

        button.addEventListener('click', async () => {
            const value = target.value || '';
            let copied = false;

            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(value);
                    copied = true;
                } catch (_) {
                    copied = false;
                }
            }

            if (!copied) {
                target.focus();
                target.select();
                copied = document.execCommand('copy');
            }

            if (copied) {
                const original = button.textContent;
                button.textContent = 'コピー済み';
                window.setTimeout(() => {
                    button.textContent = original;
                }, 1400);
            }
        });
    })();
</script>
@endsection
