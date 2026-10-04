@extends('layouts.app')

@section('title', 'Execution Provider Validation | Canovia')

@section('content')
<div class="mx-auto max-w-3xl space-y-5" data-execution-validation-provider>
    <section class="page-card border-sky-300/15 p-5 sm:p-6">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-sky-300">EXTERNAL PROVIDER VALIDATION</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50">外部Practice Providerへの引き継ぎを検証中</h1>
        <p class="mt-3 text-sm leading-6 text-slate-400">
            V55.8では、この非本番Surfaceから外部Provider結果のreturn pathを検証します。
            実際の外部API・OAuthはまだ使わず、正規化済み結果をExecutionActivityとしてCanoviaへ戻します。
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
            このValidationでは、外部サービスの代わりにここから結果を返します。
            scoreなどのallowlist済み情報だけがStudy Evidenceへ変換され、Provider固有metadataはIntelligenceへ渡りません。
            結果を返してもTask進捗・完了状態は自動変更しません。
        </div>

        <form
            method="POST"
            action="{{ route('execution.validation.study_practice.result', [$plan, $task]) }}"
            class="mt-5 rounded-3xl border border-sky-300/15 bg-slate-950/30 p-5"
            data-execution-activity-result-form
        >
            @csrf
            <input type="hidden" name="activity_key" value="{{ $activityKey }}">

            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-sky-300">SIMULATED PROVIDER RESULT</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">Practice結果をCanoviaへ返す</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    V55.8ではProvider callbackの正規化後をシミュレーションします。
                </p>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-xs font-bold text-slate-400">Score (%)</span>
                    <input
                        type="number"
                        name="score_percent"
                        min="0"
                        max="100"
                        required
                        value="{{ old('score_percent', 80) }}"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-slate-100"
                    >
                </label>

                <label class="block">
                    <span class="text-xs font-bold text-slate-400">学習時間（分・任意）</span>
                    <input
                        type="number"
                        name="duration_minutes"
                        min="0"
                        max="720"
                        value="{{ old('duration_minutes') }}"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-slate-100"
                    >
                </label>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-xs font-bold text-slate-400">Strengths（任意）</span>
                    <textarea
                        name="strengths"
                        rows="3"
                        placeholder="CIDR, TCP/IP"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-sm text-slate-100"
                    >{{ old('strengths') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-xs font-bold text-slate-400">Weaknesses（任意）</span>
                    <textarea
                        name="weaknesses"
                        rows="3"
                        placeholder="DNS, サブネット計算"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-sm text-slate-100"
                    >{{ old('weaknesses') }}</textarea>
                </label>
            </div>

            <button type="submit" class="btn-primary mt-5 min-h-11 px-4">
                External結果を反映
            </button>
        </form>

        <div class="mt-5 flex flex-wrap gap-2">
            <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}" class="btn-primary min-h-11 px-4">Study Workspaceへ戻る</a>
            <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="btn-secondary min-h-11 px-4">Canovia Practiceで開く</a>
        </div>
    </section>
</div>
@endsection
