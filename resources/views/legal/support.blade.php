@extends('layouts.app')

@section('title', 'サポート | Canovia')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <section class="page-card p-6 sm:p-8">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-cyan-300">SUPPORT</p>
        <h1 class="mt-2 text-3xl font-black text-slate-50">Canoviaサポート</h1>
        <p class="mt-3 text-sm leading-7 text-slate-400">不具合、使い方、データ・プライバシー、改善要望はこちらから連絡できます。</p>

        <div class="mt-6 rounded-2xl border border-sky-400/15 bg-sky-400/[0.04] p-4 text-sm leading-6 text-slate-300">
            <p><strong class="text-slate-100">運営:</strong> {{ $operatorName }}</p>
            @if ($supportEmail !== '')
                <p class="mt-2"><strong class="text-slate-100">Email:</strong> <a href="mailto:{{ $supportEmail }}" class="font-semibold text-sky-300 hover:text-sky-200">{{ $supportEmail }}</a></p>
            @else
                <p class="mt-2 text-amber-200">Soft Launch前にサポートメール設定を完了する必要があります。</p>
            @endif
        </div>

        <form method="POST" action="{{ route('feedback.store') }}" class="mt-7 space-y-4">
            @csrf
            <input type="hidden" name="page" value="/support">

            <label class="block">
                <span class="text-sm font-semibold text-slate-300">問い合わせ種別</span>
                <select name="type" class="form-control mt-2" required>
                    <option value="bug">不具合</option>
                    <option value="usability">使い方・使いにくさ</option>
                    <option value="request">要望・その他</option>
                    <option value="positive">よかった点</option>
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-300">内容</span>
                <textarea name="message" rows="7" maxlength="4000" required class="form-control mt-2" placeholder="状況や困っていることを入力してください"></textarea>
            </label>

            <p class="text-xs leading-5 text-slate-500">パスワード、APIキー、本人確認書類などの秘密情報は入力しないでください。</p>
            <button type="submit" class="btn-primary w-full sm:w-auto">サポートへ送信</button>
        </form>

        <div class="mt-7 flex flex-wrap gap-4 text-sm">
            <a href="{{ route('legal.privacy') }}" class="font-semibold text-sky-300 hover:text-sky-200">プライバシーポリシー</a>
            @auth
                <a href="{{ route('auth.account') }}" class="font-semibold text-sky-300 hover:text-sky-200">アカウント設定</a>
            @endauth
        </div>
    </section>
</div>
@endsection
