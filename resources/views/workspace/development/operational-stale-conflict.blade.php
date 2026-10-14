@extends('layouts.app')

@section('title', '確認内容の更新が必要です | Canovia')

@section('content')
<div class="mx-auto max-w-lg" data-development-operational-stale-conflict>
    <section class="page-card p-6 sm:p-8" role="alert" aria-labelledby="operational-conflict-title">
        <p class="text-sm font-semibold text-amber-400">409 ・ 確認内容が変更されました</p>
        <h1 id="operational-conflict-title" class="mt-2 text-xl font-bold text-slate-50 sm:text-2xl">
            最新の対象環境を確認してください
        </h1>
        <p class="mt-3 text-sm leading-7 text-slate-300">
            この画面を開いた後に、対象サービスや保存方式が別の画面で変更された可能性があります。
            古い情報のまま申告しないよう、今回の実施記録は保存していません。
        </p>
        <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-400">
            <li>下のボタンで現在のDevelopment Workを開き直します。</li>
            <li>サービス・環境・保存先が、実際に確認した対象と一致するか確かめてください。</li>
            <li>一致していることを確認できた場合だけ、必要な実施記録をもう一度送信してください。</li>
        </ol>
        <p class="mt-4 text-xs leading-6 text-slate-500">
            このエラーはリリース判定ではありません。CanoviaがRender、バックアップや復元の結果を外部検証したことも意味しません。
        </p>
        <div class="mt-6">
            <a href="{{ $returnUrl }}"
               class="btn-primary inline-flex min-h-11 items-center justify-center px-5"
               data-development-operational-stale-reload>
                最新のDevelopment Workを開く
            </a>
        </div>
    </section>
</div>
@endsection
