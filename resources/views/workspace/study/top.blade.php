@extends('layouts.app')

@section('title', '学習トップ | Canovia')

@section('content')
<div class="mx-auto max-w-6xl space-y-3" data-study-top>
    <section class="page-card px-4 py-4 sm:px-5" data-specialized-top-compact-header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-300">STUDY TOP</p>
                    <h1 class="text-lg font-black text-slate-50">学習Plan</h1>
                    <span class="text-[10px] text-slate-500">{{ $studyPlanSummaries->count() }}件</span>
                </div>
                <p class="mt-1 text-xs leading-5 text-slate-500">
                    Planを選ぶと、演習結果や現在の学習状況から次の行動を案内します。範囲の登録は必要な場合だけで構いません。
                </p>
            </div>

            <a
                href="{{ route('plans.create.manual', ['workspace_mode' => 'study']) }}"
                class="btn-primary min-h-9 shrink-0 px-3.5 text-xs"
            >
                学習Planを作る
            </a>
        </div>
    </section>

    <section class="page-card p-3 sm:p-4">
        @include('workspace.partials.specialized-top-plan-list', [
            'mode' => 'study',
            'summaries' => $studyPlanSummaries,
        ])
    </section>
</div>
@endsection
