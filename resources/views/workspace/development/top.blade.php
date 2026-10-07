@extends('layouts.app')

@section('title', '開発トップ | Canovia')

@section('content')
<div class="mx-auto max-w-6xl space-y-3" data-development-top>
    <section class="page-card px-4 py-4 sm:px-5" data-specialized-top-compact-header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">DEVELOPER TOP</p>
                    <h1 class="text-lg font-black text-slate-50">開発Plan</h1>
                    <span class="text-[10px] text-slate-500">{{ $developmentPlanSummaries->count() }}件</span>
                </div>
                <p class="mt-1 text-xs leading-5 text-slate-500">
                    Planを選ぶと、現在の開発状況と次の行動を確認できます。GitHub接続は必須ではありません。
                </p>
            </div>

            <a
                href="{{ route('plans.create.manual', ['workspace_mode' => 'development']) }}"
                class="btn-primary min-h-9 shrink-0 px-3.5 text-xs"
            >
                開発Planを作る
            </a>
        </div>
    </section>

    <section
        class="flex flex-col gap-2 rounded-2xl border border-white/8 bg-slate-950/20 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between"
        data-development-top-integration
        data-development-top-integration-compact
    >
        <div class="min-w-0 text-[10px] leading-4 text-slate-500">
            <span class="font-black uppercase tracking-[0.12em] text-slate-600">GitHub Integration</span>
            <span class="ml-2">
                {{ data_get($developmentGithubIntegrationStatus, 'evidence.message', '各PlanのRepository接続状態を確認できます。') }}
            </span>
        </div>

        @if (data_get($developmentGithubIntegrationStatus, 'runtime.interactive_connect_configured'))
            <span class="shrink-0 text-[10px] font-bold text-emerald-300">App Ready</span>
        @else
            <span class="shrink-0 text-[10px] font-bold text-amber-200">App Setup確認</span>
        @endif
    </section>

    <section class="page-card p-3 sm:p-4">
        @include('workspace.partials.specialized-top-plan-list', [
            'mode' => 'development',
            'summaries' => $developmentPlanSummaries,
            'developmentGithubIntegrationStatus' => $developmentGithubIntegrationStatus,
        ])
    </section>
</div>
@endsection
