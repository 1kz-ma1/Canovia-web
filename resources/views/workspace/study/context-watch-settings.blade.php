@extends('layouts.app')

@section('title', '学習Contextの比較設定 | Canovia')

@section('content')
<div class="mx-auto max-w-3xl space-y-5" data-learning-context-watch-settings>
    <nav>
        <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}"
           class="text-sm font-semibold text-cyan-300 underline underline-offset-4">← 学習Workspaceへ戻る</a>
    </nav>

    <section class="page-card space-y-4 p-5 sm:p-6">
        <p class="text-xs font-bold uppercase tracking-widest text-cyan-300">CANOVIA INTELLIGENCE · PREVIEW</p>
        <h1 class="text-xl font-black text-slate-100">学習Contextの比較記録</h1>
        <p class="text-sm text-slate-300">{{ $plan->title }}</p>
        <p class="text-sm leading-6 text-slate-400">
            Canovia内にある学習Plan・Task・反映済みの演習集計から前回の状態を暗号化保存し、
            手動で確認したときの変化を比較します。回答や問題文、採点の詳しい説明は比較記録に保存しません。
        </p>

        <div class="rounded-xl border border-amber-300/20 bg-amber-300/5 p-4 text-sm leading-6 text-slate-300">
            現在は<strong class="text-amber-200">手動比較のみ</strong>です。バックグラウンドでの自動チェック、
            プッシュ・メール通知、Taskや計画の自動変更、外部AIへの共有は開始されません。
            既存のChatGPT連携許可とも別の設定です。
        </div>

        @if (session('success'))
            <p class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-3 text-sm text-emerald-200"
                role="status">{{ session('success') }}</p>
        @endif
        @if (session('error'))
            <p class="rounded-xl border border-rose-400/20 bg-rose-400/5 p-3 text-sm text-rose-200"
                role="alert">{{ session('error') }}</p>
        @endif
        @if ($errors->any())
            <p class="text-sm text-rose-300" role="alert">保存には説明事項への同意が必要です。</p>
        @endif

        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-400">現在の設定</dt>
                <dd class="font-semibold text-slate-100" data-learning-watch-status>
                    {{ $isActive ? 'ON（記録中）' : 'OFF' }}
                </dd>
            </div>
            @if ($isActive)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">利用開始の同意日時</dt>
                    <dd class="text-slate-200">{{ $consentedAt?->format('Y/m/d H:i') }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">現在の確認候補</dt>
                    <dd class="text-slate-200" data-learning-watch-candidate-count>{{ $candidateCount }}件</dd>
                </div>
            @endif
        </dl>

        @if (! $isActive && $enabledForNewConsent)
            <form method="POST" action="{{ route('workspace.study.context_watch.enable', ['plan' => $plan->id]) }}"
                  class="space-y-4" data-learning-watch-enable>
                @csrf
                <label class="flex items-start gap-3 text-sm text-slate-300">
                    <input type="checkbox" name="consent" value="1" required
                           class="mt-1 h-4 w-4 rounded border-slate-500">
                    <span>
                        このStudy PlanのTask名・進捗・得点集計・弱点タグをCanovia内部で暗号化保存すること、
                        過去の変化種別を最長30日を目安に保持することを理解して同意します。
                        いつでもOFFにして記録を削除できます。
                    </span>
                </label>
                <button type="submit" class="btn-primary min-h-11 px-5">
                    このPlanの比較記録をONにする
                </button>
            </form>
        @elseif ($isActive)
            @if ($canRefresh)
                <form method="POST" action="{{ route('workspace.study.context_watch.refresh', ['plan' => $plan->id]) }}"
                      data-learning-watch-manual-check>
                    @csrf
                    <button type="submit" class="btn-secondary min-h-11 px-5">
                        今の学習状態を比較する（手動）
                    </button>
                    <p class="mt-2 text-xs text-slate-500">同じ変化は重複登録されません。自動通知は行いません。</p>
                </form>
            @else
                <p class="text-sm text-amber-200" data-learning-watch-paused>
                    新規比較は停止中です。保存済みの情報は下の操作で削除できます。
                </p>
            @endif
            <form method="POST" action="{{ route('workspace.study.context_watch.disable', ['plan' => $plan->id]) }}"
                  data-learning-watch-disable class="border-t border-white/10 pt-4">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary min-h-11 px-5">
                    OFFにして保存したContextと履歴を削除する
                </button>
                <p class="mt-2 text-xs text-slate-500">この操作では学習Plan・Task・演習結果そのものは削除しません。</p>
            </form>
        @else
            <p class="text-sm text-slate-400" data-learning-watch-unavailable>
                このPlanでは現在、比較記録を開始できません。
            </p>
        @endif
    </section>
</div>
@endsection
