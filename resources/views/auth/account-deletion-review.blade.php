@extends('layouts.app')
@section('title', 'アカウント削除の最終確認 | Canovia')
@section('content')
<div class="mx-auto max-w-xl space-y-5">
    <section class="page-card border border-rose-400/30 p-6 sm:p-8">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-rose-300">FINAL REVIEW</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">アカウント削除の最終確認</h1>
        <p class="mt-3 text-sm leading-7 text-slate-300">
            対象アカウント：<strong class="break-all">{{ $user->email }}</strong>
        </p>
        <p class="mt-3 text-sm font-semibold leading-7 text-rose-200">
            この操作は取り消せません。削除対象を確認し、続行する場合のみ最後のボタンを押してください。
        </p>

        <div class="mt-5 rounded-xl border border-rose-400/20 bg-rose-500/5 p-4">
            <h2 class="text-base font-bold text-slate-100">削除される本人所有データ</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-300">
                <li>アカウント情報・本人所有のPlanとTask</li>
                <li>学習履歴・AI履歴・実績・Inboxなどの本人所有データ</li>
                <li>本人所有のアップロードファイル（他のユーザーが参照するファイルは保護）</li>
            </ul>
            <p class="mt-3 text-xs leading-6 text-slate-400">
                他のユーザーが所有する共同Planそのものは削除されません。
            </p>
        </div>

        <p class="mt-4 text-xs leading-6 text-amber-200">
            パスワード確認から5分以内に最終確認してください。期限切れの場合は最初からやり直しになります。
        </p>

        <form method="POST" action="{{ route('auth.account.destroy') }}" class="mt-5 space-y-4">
            @csrf
            @method('DELETE')
            <label class="flex items-start gap-3 text-sm leading-6 text-slate-200">
                <input type="checkbox" name="confirm_deletion" value="yes" required class="mt-1.5">
                <span>削除するデータを確認しました。この操作が取り消せないことを理解しています。</span>
            </label>
            @error('confirm_deletion')<p class="text-xs text-rose-300">{{ $message }}</p>@enderror
            <button type="submit" class="w-full rounded-xl border border-rose-400/40 bg-rose-500/15 px-4 py-3 text-sm font-bold text-rose-100 hover:bg-rose-500/25">
                アカウントと本人所有データを完全に削除する
            </button>
        </form>
        <form method="POST" action="{{ route('auth.account.deletion.cancel') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-secondary w-full min-h-11">キャンセルしてアカウントに戻る</button>
        </form>
    </section>
</div>
@endsection
