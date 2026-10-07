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
