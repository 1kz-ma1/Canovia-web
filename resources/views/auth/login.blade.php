@extends('layouts.app')

@section('title', 'ログイン | Canovia')

@section('content')
<div class="mx-auto max-w-md">
    <section class="page-card p-6 sm:p-8">
        <p class="text-sm font-semibold text-sky-400">Protect your context</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">Canoviaにログイン</h1>
        <p class="mt-3 text-sm leading-7 text-slate-400">別端末やCookie削除後でも、計画・実績・昨日からの続きへ戻れるようにします。</p>

        @if ($errors->any())
            <div role="alert" aria-live="polite" data-auth-login-error class="mt-5 rounded-xl border border-rose-400/30 bg-rose-950/25 px-4 py-3 text-sm leading-6 text-rose-200">
                {{ $errors->first('email') ?: $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('auth.login') }}" class="mt-6 space-y-4">
            @csrf
            <label class="block">
                <span class="text-sm font-semibold text-slate-300">メールアドレス</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required class="form-control mt-2">
            </label>
            <label class="block">
                <span class="text-sm font-semibold text-slate-300">パスワード</span>
                <input type="password" name="password" autocomplete="current-password" required class="form-control mt-2">
            </label>
            <label class="flex min-h-11 items-center gap-3 text-sm text-slate-300">
                <input type="hidden" name="remember" value="0">
                <input type="checkbox" name="remember" value="1" checked class="h-5 w-5 rounded border-slate-600 bg-slate-900">
                この端末でログイン状態を保持（おすすめ）
            </label>
            <button type="submit" class="btn-primary w-full">ログイン</button>
            <a href="{{ route('password.request') }}" class="block text-center text-sm font-semibold text-sky-300 hover:text-sky-200">パスワードを忘れた場合</a>
        </form>

        <p class="mt-6 text-center text-sm text-slate-400">まだアカウントがない場合は <a href="{{ route('auth.register.form') }}" class="font-semibold text-sky-300 hover:text-sky-200">データを保護する</a></p>
    </section>
</div>
@endsection
