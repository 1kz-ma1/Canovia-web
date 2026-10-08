@extends('layouts.app')

@section('title', '提案更新の差分確認 | Canovia')

@section('content')
<div class="mx-auto max-w-3xl space-y-6" data-draft-refresh-preview>
    <a href="{{ route('plans.action_drafts.index', $plan) }}" class="text-sm font-semibold text-sky-700">← 提案一覧に戻る（変更を破棄）</a>
    <section class="page-card p-5 sm:p-7">
        <p class="text-xs font-bold tracking-widest text-sky-700">REVIEW / NO WRITE</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">変更前と変更後を確認</h1>
        <p class="mt-3 text-sm text-slate-600">この画面を開いただけでは、提案もTaskも更新されません。本人が適用して初めて提案の新しい版を保存します。</p>
        <dl class="mt-5 space-y-4">
            <div class="rounded-xl border border-slate-200 p-4">
                <dt class="text-xs font-bold text-slate-500">変更前 · 第{{ $draft->revision_no }}版</dt>
                <dd class="mt-2 whitespace-pre-line text-sm text-slate-900">{{ $draft->suggested_next_action }}</dd>
            </div>
            <div class="rounded-xl border border-sky-200 p-4">
                <dt class="text-xs font-bold text-sky-700">変更後（未保存） · 第{{ $draft->revision_no + 1 }}版</dt>
                <dd class="mt-2 whitespace-pre-line text-sm text-slate-900">{{ $afterAction }}</dd>
            </div>
        </dl>
    </section>
    <section class="page-card p-5 sm:p-7">
        <h2 class="text-lg font-bold text-slate-900">今回追加する根拠（{{ count($added) }}件）</h2>
        <div class="mt-3 space-y-3">
            @foreach ($added as $source)
                <div class="rounded-lg border border-slate-200 p-3">
                    <p class="text-xs text-slate-500">{{ ($source['kind'] ?? '') === 'task_evidence' ? 'Evidence #'.($source['task_evidence_id'] ?? '-').' · '.($source['source_label'] ?? '記録') : '同じPlanの実績ログ #'.($source['work_log_id'] ?? '-') }}</p>
                    <p class="mt-1 text-sm font-bold">{{ $source['action'] }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $source['outcome'] }}</p>
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-xs leading-6 text-slate-500">比較対象は合計{{ count($combined) }}件です。既存の根拠は削除されません。これは決定論的な提案で、理解度や達成度の自動判定ではありません。</p>
        <form method="POST" action="{{ route('plans.action_drafts.refresh.apply', [$plan, $draft]) }}" class="mt-5">
            @csrf
            <input type="hidden" name="expected_revision" value="{{ $draft->revision_no }}">
            <input type="hidden" name="candidate_fingerprint" value="{{ $candidateFingerprint }}">
            <input type="hidden" name="source_fingerprint" value="{{ $sourceFingerprint }}">
            @foreach ($added as $source)
                @if (($source['kind'] ?? '') === 'task_evidence')
                    <input type="hidden" name="additional_task_evidence_ids[]" value="{{ $source['task_evidence_id'] }}">
                @else
                    <input type="hidden" name="additional_work_log_ids[]" value="{{ $source['work_log_id'] }}">
                @endif
            @endforeach
            <button type="submit" class="btn-primary">この差分を提案へ反映する</button>
        </form>
        <a href="{{ route('plans.action_drafts.index', $plan) }}" class="mt-3 inline-block text-sm font-semibold text-sky-700">反映せずに戻る</a>
    </section>
</div>
@endsection
