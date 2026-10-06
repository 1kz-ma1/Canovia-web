@extends('layouts.app')

@section('title', '学習トップ | Canovia')

@section('content')
<div class="mx-auto max-w-6xl space-y-4" data-study-top>
    <section class="page-card overflow-hidden p-0">
        <div class="flex flex-col gap-4 px-5 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-300">STUDY TOP</p>
                <h1 class="mt-2 text-2xl font-black text-slate-50">学習Planを選ぶ・準備する。</h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    日々の学習はPlan Workspaceへ。ここではPlan一覧、進捗、範囲・教材・成績の入口をまとめます。
                </p>
            </div>

            <a
                href="{{ route('plans.create.manual', ['workspace_mode' => 'study']) }}"
                class="btn-primary min-h-10 shrink-0 px-4"
            >
                学習Planを作る
            </a>
        </div>
    </section>

    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">STUDY PLANS</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">Planと進捗</h2>
            </div>
            <span class="badge badge-slate">{{ $studyPlanSummaries->count() }}件</span>
        </div>

        <div class="mt-4">
            @include('workspace.partials.specialized-top-plan-list', [
                'mode' => 'study',
                'summaries' => $studyPlanSummaries,
            ])
        </div>
    </section>
</div>
@endsection
