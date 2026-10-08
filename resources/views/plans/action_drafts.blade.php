@extends('layouts.app')
@section('title', '行動から育つ計画案 | Canovia')
@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <a href="{{ route('plans.show', $plan) }}" class="text-sm font-semibold text-sky-600">← {{ $plan->title }} に戻る</a>
    <section class="page-card p-5 sm:p-7">
        <p class="text-xs font-bold tracking-widest text-sky-600">ACTION → PROPOSAL</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-900">行動から育つ計画案</h1>
        <p class="mt-3 text-sm leading-7 text-slate-600">実際の行動と結果から次の一歩を考えます。提案は本人が修正・承認するまでTaskや進捗に反映されません。ログと自己申告は区別して記録します。</p>
    </section>
    @if (session('success')) <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div> @endif
    @if ($errors->any()) <div class="text-sm text-red-700" role="alert">{{ $errors->first() }}</div> @endif
    <section class="page-card p-5 sm:p-7">
        <h2 class="text-lg font-bold text-slate-900">やったことを記録する</h2>
        <form method="POST" action="{{ route('plans.action_drafts.store', $plan) }}" class="mt-4 space-y-4" data-plan-action-draft-create>
            @csrf <input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
            @if ($workLogs->isNotEmpty())
                <label class="block text-sm font-semibold text-slate-700" for="source_work_log_id">実績ログから作る（任意）</label>
                <select id="source_work_log_id" name="source_work_log_id" class="form-control w-full">
                    <option value="">新しく記録する</option>
                    @foreach ($workLogs as $log)
                        <option value="{{ $log->id }}" @selected(old('source_work_log_id') == $log->id)>{{ $log->worked_on?->format('Y/m/d') }} · {{ $log->task_title_snapshot ?: '作業' }} · {{ \Illuminate\Support\Str::limit($log->outcome ?: $log->memo, 45) }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-500">ログを選んだ場合、下の手入力ではなくその実績を使います。</p>
            @endif
            <label class="block text-sm font-semibold text-slate-700" for="completed_action">実際に行ったこと</label>
            <input id="completed_action" name="completed_action" class="form-control w-full" maxlength="255" value="{{ old('completed_action') }}" placeholder="例：問題集の第1章を解いた">
            <label class="block text-sm font-semibold text-slate-700" for="observed_outcome">結果・発見・詰まった点</label>
            <textarea id="observed_outcome" name="observed_outcome" class="form-control w-full" maxlength="2000" rows="3" placeholder="例：計算問題で時間が足りなかった">{{ old('observed_outcome') }}</textarea>
            <button type="submit" class="btn-primary">記録から行動案を作る</button>
        </form>
    </section>
    @if ($workLogs->count() >= 2)
        <section class="page-card p-5 sm:p-7" data-multi-evidence-compose>
            <h2 class="text-lg font-bold text-slate-900">複数の実績から比較案を作る</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">2〜5件を選んで、その結果を比較するための行動案を作れます。内容の正しさや習熟を自動判定するものではありません。</p>
            <form method="POST" action="{{ route('plans.action_drafts.compose', $plan) }}" class="mt-4 space-y-3">
                @csrf <input type="hidden" name="request_id" value="{{ old('request_id', $multiRequestId) }}">
                @foreach ($workLogs as $log)
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm">
                        <input type="checkbox" name="work_log_ids[]" value="{{ $log->id }}" @checked(in_array($log->id, old('work_log_ids', [])))>
                        <span><strong class="text-slate-800">{{ $log->task_title_snapshot ?: '作業記録' }}</strong><br><span class="text-slate-600">{{ \Illuminate\Support\Str::limit($log->outcome ?: $log->memo, 110) }}</span></span>
                    </label>
                @endforeach
                <button class="btn-primary" type="submit">選んだ実績を比較する</button>
            </form>
        </section>
    @endif
    <section class="page-card p-5 sm:p-7">
        <h2 class="text-lg font-bold text-slate-900">提案一覧</h2>
        <div class="mt-4 space-y-4">
            @forelse ($drafts as $draft)
                <article class="rounded-xl border border-slate-200 p-4" data-plan-action-draft="{{ $draft->id }}">
                    <p class="text-xs text-slate-500">{{ match ($draft->source_kind) { 'work_log' => '保存済みの実績ログ', 'multi_work_log' => '複数の実績ログ', 'mixed' => '自己申告と実績ログ', default => '自己申告の行動' } }} · {{ $draft->created_at?->format('Y/m/d') }} · {{ ['proposed'=>'提案中','accepted'=>'承認済み','dismissed'=>'却下済み'][$draft->status] ?? '未確定' }}</p>
                    <p class="mt-2 text-sm font-semibold text-slate-800">{{ $draft->completed_action }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $draft->observed_outcome }}</p>
                    @php
                        $sources = $draft->evidence_snapshots ?: [[
                            'kind' => $draft->source_kind === 'work_log' ? 'work_log' : 'self_report',
                            'work_log_id' => $draft->source_work_log_id,
                            'action' => $draft->completed_action,
                            'outcome' => $draft->observed_outcome,
                        ]];
                        $includedLogIds = collect($sources)->where('kind', 'work_log')->pluck('work_log_id')->filter()->map(fn ($id) => (int) $id)->all();
                        $availableLogs = $workLogs->filter(fn ($log) => ! in_array((int) $log->id, $includedLogIds, true));
                    @endphp
                    <details class="mt-3 rounded-lg border border-slate-200 p-3" data-draft-evidence-list>
                        <summary class="cursor-pointer text-xs font-bold text-slate-700">根拠 {{ count($sources) }}件 · 第{{ $draft->revision_no }}版（出所・原文）</summary>
                        <ol class="mt-3 space-y-3">
                            @foreach ($sources as $source)
                                <li class="text-sm text-slate-700">
                                    <span class="text-xs text-slate-500">{{ ($source['kind'] ?? '') === 'work_log' ? '実績ログ #'.($source['work_log_id'] ?? '-') : '自己申告' }}</span>
                                    <p class="font-semibold">{{ $source['action'] ?? '' }}</p>
                                    <p class="whitespace-pre-line">{{ $source['outcome'] ?? '' }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </details>
                    @if ($draft->revisions->isNotEmpty())
                        <details class="mt-3 rounded-lg border border-slate-200 p-3">
                            <summary class="cursor-pointer text-xs font-bold text-slate-700">再生成の差分履歴 {{ $draft->revisions->count() }}件</summary>
                            @foreach ($draft->revisions as $revision)
                                <div class="mt-3 border-t border-slate-100 pt-3 text-xs leading-6 text-slate-700">
                                    <p>第{{ $revision->from_revision }}版 → 第{{ $revision->to_revision }}版</p>
                                    <p>変更前：{{ $revision->before_action }}</p>
                                    <p>変更後：{{ $revision->after_action }}</p>
                                    <p>追加した実績：{{ count($revision->added_evidence_snapshots ?? []) }}件</p>
                                </div>
                            @endforeach
                        </details>
                    @endif
                    @if ($draft->status === 'proposed')
                        <form method="POST" action="{{ route('plans.action_drafts.update', [$plan, $draft]) }}" class="mt-3 space-y-2">
                            @csrf @method('PATCH')
                            <label class="block text-xs font-bold text-slate-700" for="next-{{ $draft->id }}">次の行動案（編集可）</label>
                            <input id="next-{{ $draft->id }}" name="suggested_next_action" class="form-control w-full" maxlength="255" required value="{{ $draft->suggested_next_action }}">
                            <button type="submit" class="btn-secondary">案を修正する</button>
                        </form>
                        @if (count($sources) < 5 && $availableLogs->isNotEmpty())
                            <details class="mt-3 rounded-lg border border-sky-200 p-3" data-draft-refresh>
                                <summary class="cursor-pointer text-xs font-bold text-sky-700">新しい実績を加えて案を再検討する</summary>
                                <p class="mt-2 text-xs leading-5 text-slate-600">結果はまず差分プレビューで確認できます。確定しなければ現在の案は保持されます。手動修正した文言も適用時には置き換わります。</p>
                                <form method="POST" action="{{ route('plans.action_drafts.refresh.preview', [$plan, $draft]) }}" class="mt-3 space-y-2">
                                    @csrf
                                    @foreach ($availableLogs as $log)
                                        <label class="flex items-start gap-2 text-sm text-slate-700">
                                            <input type="checkbox" name="additional_work_log_ids[]" value="{{ $log->id }}">
                                            <span>{{ $log->task_title_snapshot ?: '作業' }} — {{ \Illuminate\Support\Str::limit($log->outcome ?: $log->memo, 70) }}</span>
                                        </label>
                                    @endforeach
                                    <button type="submit" class="btn-secondary">変更前後をプレビュー</button>
                                </form>
                            </details>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('plans.action_drafts.accept', [$plan, $draft]) }}">@csrf <button type="submit" class="btn-primary">承認してTaskに追加</button></form>
                            <form method="POST" action="{{ route('plans.action_drafts.dismiss', [$plan, $draft]) }}">@csrf <button type="submit" class="btn-secondary">却下する</button></form>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-slate-700">次の行動案：{{ $draft->suggested_next_action }}</p>
                    @endif
                </article>
            @empty
                <p class="text-sm text-slate-500">まだ提案はありません。小さな行動から始められます。</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
