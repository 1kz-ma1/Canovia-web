@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'GitHub Workflow | Canovia')

@section('content')
@php
    $workflowStates = $workflow_states;
@endphp

<div class="mx-auto max-w-[96rem] space-y-5" data-github-workflow-hub>
    <header class="rounded-3xl border border-violet-300/15 bg-slate-950/55 p-5 shadow-2xl shadow-slate-950/25 backdrop-blur md:p-7">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-300">CANOVIA / DEVELOPER WORKFLOW</p>
                <h1 class="mt-2 text-2xl font-black text-slate-50 md:text-3xl">GitHubを「次に何をするか」で管理</h1>
                <p class="mt-3 text-sm leading-7 text-slate-400">
                    Branch・PR・Issue・Review・CIをそのまま並べるのではなく、Canovia上では
                    「今やる / レビュー待ち / 修正必要 / マージ待ち / 完了」に整理します。
                </p>
                <p class="mt-2 text-xs leading-6 text-slate-500">
                    現在はGitHub API未接続です。ここで選ぶ状態はCanovia上の作業判断であり、
                    GitHub側のopen / merged / approved / CI結果を自動判定したものではありません。
                </p>
            </div>

            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:min-w-[30rem]">
                <div class="rounded-2xl border border-slate-800 bg-slate-900/65 p-3 text-center">
                    <strong class="block text-lg text-slate-100">{{ $summary['repositories'] }}</strong>
                    <span class="text-[10px] text-slate-500">REPO</span>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/65 p-3 text-center">
                    <strong class="block text-lg text-slate-100">{{ $summary['now'] }}</strong>
                    <span class="text-[10px] text-slate-500">今やる</span>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/65 p-3 text-center">
                    <strong class="block text-lg text-slate-100">{{ $summary['review'] }}</strong>
                    <span class="text-[10px] text-slate-500">レビュー</span>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/65 p-3 text-center">
                    <strong class="block text-lg text-slate-100">{{ $summary['changes'] }}</strong>
                    <span class="text-[10px] text-slate-500">修正</span>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/65 p-3 text-center">
                    <strong class="block text-lg text-slate-100">{{ $summary['merge'] }}</strong>
                    <span class="text-[10px] text-slate-500">マージ</span>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/65 p-3 text-center">
                    <strong class="block text-lg text-slate-100">{{ $summary['done'] }}</strong>
                    <span class="text-[10px] text-slate-500">完了</span>
                </div>
            </div>
        </div>
    </header>

    @if (session('success'))
        <div class="assistant-notice assistant-notice-info">{{ session('success') }}</div>
    @endif

    <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,0.58fr)]">
        <div class="rounded-3xl border border-slate-800 bg-slate-950/45 p-4 md:p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-black text-slate-100">表示するPlan</p>
                    <p class="mt-1 text-[11px] text-slate-500">GitHub項目だけを絞り込みます。</p>
                </div>
                @if ($selected_plan)
                    <a href="{{ route('github_workflow.index') }}" class="btn-secondary px-3 py-2 text-xs">全Planへ戻す</a>
                @endif
            </div>

            <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
                <a
                    href="{{ route('github_workflow.index') }}"
                    class="{{ $selected_plan ? 'btn-secondary' : 'btn-primary' }} shrink-0 px-3 py-2 text-xs"
                >すべて</a>
                @foreach ($plans as $plan)
                    <a
                        href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}"
                        class="{{ $selected_plan?->id === $plan->id ? 'btn-primary' : 'btn-secondary' }} shrink-0 px-3 py-2 text-xs"
                    >{{ $plan->displayIcon() }} {{ $plan->title }}</a>
                @endforeach
            </div>
        </div>

        <details class="rounded-3xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4 md:p-5" @if ($items->isEmpty()) open @endif>
            <summary class="cursor-pointer list-none text-sm font-black text-cyan-100">＋ GitHub URLを追加</summary>

            @if ($editable_plans->isEmpty())
                <p class="mt-3 text-xs leading-6 text-slate-500">編集できるPlanがないため、GitHub項目は追加できません。</p>
            @else
                <form method="POST" action="{{ route('github_workflow.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">Plan</span>
                        <select name="plan_id" class="form-control mt-2 w-full" required>
                            @foreach ($editable_plans as $plan)
                                <option
                                    value="{{ $plan->id }}"
                                    @selected((string) old('plan_id', $selected_plan?->id) === (string) $plan->id)
                                >{{ $plan->title }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">最初の状態 <span class="text-slate-600">PR / Issue等</span></span>
                        <select name="workflow_state" class="form-control mt-2 w-full" required>
                            @foreach ($workflowStates as $key => $label)
                                <option value="{{ $key }}" @selected(old('workflow_state', 'now') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">GitHub URL</span>
                        <input
                            type="url"
                            name="url"
                            value="{{ old('url') }}"
                            class="form-control mt-2 w-full"
                            placeholder="https://github.com/owner/repo/pull/123"
                            required
                        >
                        @error('url')<span class="mt-1 block text-xs text-rose-300">{{ $message }}</span>@enderror
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">表示名（任意）</span>
                        <input
                            type="text"
                            name="title"
                            value="{{ old('title') }}"
                            class="form-control mt-2 w-full"
                            maxlength="255"
                            placeholder="空欄ならURLから PR #123 などを自動生成"
                        >
                    </label>

                    @error('plan_id')<p class="text-xs text-rose-300 sm:col-span-2">{{ $message }}</p>@enderror
                    @error('workflow_state')<p class="text-xs text-rose-300 sm:col-span-2">{{ $message }}</p>@enderror

                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary w-full justify-center">Canoviaへ追加</button>
                        <p class="mt-2 text-[11px] leading-5 text-slate-500">
                            Repository URLは作業レーンに置かず「全体像」として表示します。PR / Issue / Branch等だけが状態を持ちます。Task紐付け・担当者・メモは追加後の「詳細」から設定できます。
                        </p>
                    </div>
                </form>
            @endif
        </details>
    </section>

    @if ($repository_overviews->isNotEmpty())
        <section data-github-repository-overviews>
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">REPOSITORY OVERVIEW</p>
                    <h2 class="mt-1 text-xl font-black text-slate-100">Repositoryを起点に、全体像を見る</h2>
                    <p class="mt-2 text-xs leading-6 text-slate-500">
                        Repository自体を「今やる」に置かず、関連するPR / Issue / BranchとCanovia上の状態を1つのまとまりで見ます。
                    </p>
                </div>
                <span class="text-xs text-slate-600">{{ $repository_overviews->count() }} repositories</span>
            </div>

            <div class="grid gap-4 {{ $repository_overviews->count() > 1 ? '2xl:grid-cols-2' : '' }}">
                @foreach ($repository_overviews as $overview)
                    @include('github_workflow.partials.repository_overview', [
                        'overview' => $overview,
                    ])
                @endforeach
            </div>
        </section>
    @endif

    @if ($unclassified->isNotEmpty())
        <section class="rounded-3xl border border-amber-300/15 bg-amber-300/[0.035] p-4 md:p-5" data-github-workflow-unclassified>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-300">FIRST SORT</p>
                    <h2 class="mt-1 text-lg font-black text-slate-100">未整理 {{ $unclassified->count() }}件</h2>
                </div>
                <span class="text-xs text-slate-500">既存GitHub Artifact</span>
            </div>
            <p class="mt-2 text-xs leading-6 text-slate-500">
                URLだけでは状態を推測しません。最初にCanovia上の扱いを選ぶと、下の5レーンへ移動します。
            </p>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($unclassified as $item)
                    @include('github_workflow.partials.card', [
                        'item' => $item,
                        'workflowStates' => $workflowStates,
                        'selectedPlan' => $selected_plan,
                    ])
                @endforeach
            </div>
        </section>
    @endif

    <section>
        <div class="mb-3 flex items-end justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">SIMPLE WORKFLOW</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">PR / Issue / Branchは、次の判断で見る</h2>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-5" data-github-workflow-board>
            @foreach ($lanes as $lane)
                <section
                    class="min-w-0 rounded-3xl border border-slate-800 bg-slate-950/40 p-3"
                    data-github-workflow-lane="{{ $lane['key'] }}"
                >
                    <div class="mb-3 flex items-center justify-between gap-2 px-1">
                        <h3 class="text-sm font-black text-slate-100">{{ $lane['label'] }}</h3>
                        <span class="rounded-full border border-slate-700 px-2 py-1 text-[10px] font-black text-slate-400">{{ $lane['items']->count() }}</span>
                    </div>

                    <div class="space-y-3">
                        @forelse ($lane['items'] as $item)
                            @include('github_workflow.partials.card', [
                                'item' => $item,
                                'workflowStates' => $workflowStates,
                                'selectedPlan' => $selected_plan,
                            ])
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-800 p-4 text-center">
                                <p class="text-[11px] text-slate-600">今はありません</p>
                            </div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </section>

    @if ($items->isEmpty())
        <section class="rounded-3xl border border-dashed border-slate-700 bg-slate-950/30 p-8 text-center">
            <p class="text-3xl" aria-hidden="true">⌘</p>
            <h2 class="mt-3 text-lg font-black text-slate-100">まだGitHub項目がありません</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">上のフォームへRepository / PR / IssueのURLを貼るだけで始められます。</p>
        </section>
    @endif
</div>
@endsection
