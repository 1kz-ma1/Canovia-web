@extends('layouts.app')

@section('title', '開発トップ | Canovia')

@section('content')
<div class="mx-auto max-w-6xl space-y-4" data-development-top>
    <section class="page-card overflow-hidden p-0">
        <div class="flex flex-col gap-4 px-5 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">DEVELOPER TOP</p>
                <h1 class="mt-2 text-2xl font-black text-slate-50">開発Planを選ぶ・接続する。</h1>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    日々の開発はPlan Workspaceへ。ここではPlan一覧、進捗、Repository / GitHub接続状態をまとめます。
                </p>
            </div>

            <a
                href="{{ route('plans.create.manual', ['workspace_mode' => 'development']) }}"
                class="btn-primary min-h-10 shrink-0 px-4"
            >
                開発Planを作る
            </a>
        </div>
    </section>

    <section class="page-card p-5 sm:p-6" data-development-top-integration>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">GITHUB INTEGRATION</p>
                <h2 class="mt-1 text-sm font-black text-slate-100">
                    {{ data_get($developmentGithubIntegrationStatus, 'evidence.allowed') ? 'GitHub Evidenceを利用できます' : 'GitHub連携の利用状態' }}
                </h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                    {{ data_get($developmentGithubIntegrationStatus, 'evidence.message', '各PlanのRepository接続状態を確認できます。') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2 text-[10px]">
                @if (data_get($developmentGithubIntegrationStatus, 'runtime.interactive_connect_configured'))
                    <span class="rounded-full border border-emerald-300/15 bg-emerald-300/[0.03] px-2.5 py-1 text-emerald-300">App Ready</span>
                @else
                    <span class="rounded-full border border-amber-300/15 bg-amber-300/[0.03] px-2.5 py-1 text-amber-200">App Setup確認</span>
                @endif
            </div>
        </div>
    </section>

    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">DEVELOPMENT PLANS</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">Planと進捗</h2>
            </div>
            <span class="badge badge-slate">{{ $developmentPlanSummaries->count() }}件</span>
        </div>

        <div class="mt-4">
            @include('workspace.partials.specialized-top-plan-list', [
                'mode' => 'development',
                'summaries' => $developmentPlanSummaries,
                'developmentGithubIntegrationStatus' => $developmentGithubIntegrationStatus,
            ])
        </div>
    </section>
</div>
@endsection
