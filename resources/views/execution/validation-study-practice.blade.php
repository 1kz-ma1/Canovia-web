@extends('layouts.app')

@section('title', 'Execution Provider Validation | Canovia')

@section('content')
<div class="mx-auto max-w-3xl space-y-5" data-execution-validation-provider>
    <section class="page-card border-sky-300/15 p-5 sm:p-6">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-sky-300">EXTERNAL PROVIDER VALIDATION</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50">外部Practice Providerへの引き継ぎを検証中</h1>
        <p class="mt-3 text-sm leading-6 text-slate-400">
            これはV55.7の非本番Validation Surfaceです。実際の外部API・OAuth・Activity送信はまだ行いません。
        </p>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border border-white/8 bg-slate-950/30 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">PROVIDER</p>
                <p class="mt-2 font-black text-slate-100">{{ $provider->name }}</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/30 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">TASK</p>
                <p class="mt-2 font-black text-slate-100">{{ $task->title }}</p>
            </div>
        </div>

        <div class="mt-5 rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-sm leading-6 text-slate-400">
            この画面への到達で、保存済みProvider preference → Execution Resolver → Launch handoff の経路だけを確認します。
            Activity Integrationは次段階で接続します。
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
            <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}" class="btn-primary min-h-11 px-4">Study Workspaceへ戻る</a>
            <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="btn-secondary min-h-11 px-4">Canovia Practiceで開く</a>
        </div>
    </section>
</div>
@endsection
