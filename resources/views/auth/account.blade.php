@extends('layouts.app')
@section('title', 'アカウント | Canovia')
@section('content')
<div class="mx-auto max-w-lg space-y-5">
    <section class="page-card p-6 sm:p-8">
        <p class="text-sm font-semibold text-emerald-400">Protected</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">{{ $user->name }}</h1>
        <p class="mt-1 text-sm text-slate-400">{{ $user->email }}</p>
        <div class="mt-6 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 text-sm leading-6 text-emerald-100">
            計画はアカウントに紐づいています。Cookie削除・別端末・PWA再インストール後も、ログインすれば復元できます。
        </div>

        @if ($showPersonalizationBootstrap)
        <a
            href="{{ route('personalization.show', ['source' => 'account']) }}"
            class="mt-5 block rounded-2xl border border-violet-300/15 bg-violet-300/[0.035] p-4 transition hover:border-violet-300/30 hover:bg-violet-300/[0.065]"
            data-personalization-account-entry
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">PERSONALIZATION</p>
                    <p class="mt-1 text-sm font-black text-slate-100">Canoviaをあなた向けに調整する</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        {{ $personalizationContext?->completed_at ? '今の状況に合わせて回答を見直せます。' : '1分ほどの診断から、最初に必要なPlanと機能を絞ります。' }}
                    </p>
                </div>
                <span class="text-lg text-violet-300" aria-hidden="true">→</span>
            </div>
        </a>
        @if ($showPersonalizationBootstrap && $personalizationContext)
            <a
                href="{{ route('personalization.updates.index') }}"
                class="mt-3 block rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.03] p-4 transition hover:border-cyan-300/30 hover:bg-cyan-300/[0.06]"
                data-living-profile-account-entry
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">LIVING PROFILE</p>
                        <p class="mt-1 text-sm font-black text-slate-100">Canoviaが気づいた変化</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">
                            @if ((int) data_get($livingProfileSummary ?? [], 'pending_count', 0) > 0)
                                {{ (int) data_get($livingProfileSummary, 'pending_count') }}件、確認してほしいContext更新候補があります。
                            @else
                                実際の利用状況から現在地を再評価できます。初回診断を固定プロフィールにはしません。
                            @endif
                        </p>
                    </div>
                    @if ((int) data_get($livingProfileSummary ?? [], 'pending_count', 0) > 0)
                        <span class="rounded-full border border-amber-300/20 bg-amber-300/[0.06] px-2.5 py-1 text-[10px] font-black text-amber-200">
                            {{ (int) data_get($livingProfileSummary, 'pending_count') }}
                        </span>
                    @else
                        <span class="text-lg text-cyan-300" aria-hidden="true">→</span>
                    @endif
                </div>
            </a>
        @endif
        @endif

        @if ($showProductPreview)
            <a
                href="{{ route('product.preview.index') }}"
                class="mt-5 block rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.04] p-4 transition hover:border-cyan-300/30 hover:bg-cyan-300/[0.07]"
                data-product-preview-entry
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">EARLY ACCESS</p>
                        <p class="mt-1 text-sm font-black text-slate-100">プラン・機能プレビュー</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Free / Premium / Pro / Dev ProでCanoviaの動きがどう変わるか確認できます。</p>
                    </div>
                    <span class="text-lg text-cyan-300" aria-hidden="true">→</span>
                </div>
            </a>
        @endif

        <div class="mt-5 flex flex-wrap gap-4 text-sm">
            <a href="{{ route('legal.privacy') }}" class="font-semibold text-sky-300 hover:text-sky-200">プライバシーポリシー</a>
            <a href="{{ route('legal.support') }}" class="font-semibold text-sky-300 hover:text-sky-200">サポート</a>
        </div>

        <form method="POST" action="{{ route('auth.logout') }}" class="mt-6" data-clear-offline-state>
            @csrf
            <button type="submit" class="btn-secondary w-full">ログアウト</button>
        </form>
    </section>

    <section class="page-card min-w-0 p-6 sm:p-8" data-chatgpt-sharing-account>
        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">CHATGPT · NOT CONNECTED</p>
        <h2 class="mt-2 text-lg font-bold text-slate-50">ChatGPT共有の準備設定</h2>
        <p class="mt-2 text-xs leading-5 text-slate-400">
            これらは接続の準備情報です。ChatGPTへのアクセス許可やデータ送信は行われていません。
            Planのカテゴリや所有者が変更されたあとも、自分が保存した設定をここから取り消せます。
        </p>

        @forelse ($chatgptPreparedPreferences as $preference)
            <div class="mt-3 min-w-0 rounded-xl border border-white/10 bg-slate-950/20 p-3" data-chatgpt-preference-account-row>
                @php
                    $stillOwnsPlan = $preference->plan
                        && (int) $preference->plan->user_id === (int) $user->id;
                @endphp
                <p class="break-words text-sm font-bold text-slate-100">
                    {{ $stillOwnsPlan ? $preference->plan->title : '以前の開発Plan（現在の内容は表示しません）' }}
                </p>
                <p class="mt-1 text-xs leading-5 text-slate-400">
                    希望範囲：{{ $preference->scope === 'tasks' ? '概要＋進行中Task' : '概要のみ' }}
                    ・期限：{{ $preference->expires_at?->format('Y-m-d H:i') }}
                </p>
                <p class="mt-1 text-[11px] text-amber-200">
                    {{ $preference->isPrepared() ? '準備保存済み（未接続）' : '期限切れ（未接続）' }}
                </p>
                <form method="POST"
                    action="{{ route('workspace.development.sharing_preference.destroy', ['plan' => $preference->plan_id]) }}"
                    class="mt-3">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="return_to" value="account">
                    <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">この準備設定を取り消す</button>
                </form>
            </div>
        @empty
            <p class="mt-3 text-xs text-slate-500">現在、ChatGPT向けに保存された準備設定はありません。</p>
        @endforelse

        @if ($chatgptPreparedPreferences->hasPages())
            <div class="mt-4">{{ $chatgptPreparedPreferences->links() }}</div>
        @endif
    </section>

    @include('auth.partials.mcp-account-link')
    @include('auth.partials.mcp-delegated-access-revocation')

    <section class="page-card border border-rose-400/20 p-6 sm:p-8">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-rose-300">DELETE ACCOUNT</p>
        <h2 class="mt-2 text-xl font-bold text-slate-50">アカウントを削除</h2>
        <p class="mt-3 text-sm leading-7 text-slate-400">
            本人所有のPlan、Task、学習履歴、AI履歴、Inbox、アップロードファイルなどを削除します。この操作は取り消せません。
            他のユーザーが所有する共同Planそのものは削除しません。
        </p>

        <form method="POST" action="{{ route('auth.account.destroy') }}" class="mt-5 space-y-4" data-clear-offline-state>
            @csrf
            @method('DELETE')

            <label class="block">
                <span class="text-sm font-semibold text-slate-300">現在のパスワード</span>
                <input type="password" name="password" autocomplete="current-password" required class="form-control mt-2">
                @error('password')<span class="mt-1 block text-xs text-rose-300">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-300">確認のためメールアドレスを再入力</span>
                <input type="email" name="confirmation_email" value="{{ old('confirmation_email') }}" autocomplete="email" required class="form-control mt-2">
                @error('confirmation_email')<span class="mt-1 block text-xs text-rose-300">{{ $message }}</span>@enderror
            </label>

            <button type="submit" class="w-full rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm font-bold text-rose-100 hover:bg-rose-500/20">
                アカウントと本人所有データを削除
            </button>
        </form>
    </section>
</div>
@endsection
