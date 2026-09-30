@extends('layouts.app')

@section('title', '共同計画の設定 | Canovia')

@section('content')
<section class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <p class="pk-v18-eyebrow">CANOVIA / TOGETHER</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">{{ ($canManage ?? false) ? '共同計画の設定' : '共同計画' }}</h1>
        <p class="mt-2 text-sm text-slate-400">{{ $plan->title }}</p>
    </div>
    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">計画へ戻る</a>
</section>

@if (session('status'))
    <div class="assistant-notice assistant-notice-info mb-6">{{ session('status') }}</div>
@endif

@if (! $plan->is_collaborative)
    <section class="page-card p-6 sm:p-8">
        <h2 class="text-xl font-bold text-slate-50">共同計画はオフです</h2>
        <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-300">有効にすると共有URLと参加コードを発行できます。参加者はデフォルトで閲覧者になり、オーナーだけが編集権限を付与できます。</p>
        @if ($canManage ?? false)
            <form method="POST" action="{{ route('plans.collaboration.enable', $plan) }}" class="mt-5">
                @csrf
                <button class="btn-primary" type="submit" data-guide-target="collaboration-primary">共同計画を有効にする</button>
            </form>
        @endif
    </section>
@else
    @php
        $shareUrl = route('collaboration.join.token', ['token' => $plan->collaboration_share_token]);
    @endphp
    <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-5">
            @if ($canManage ?? false)
            <article class="page-card p-6" data-guide-target="collaboration-primary">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-slate-50">招待する</h2>
                        <p class="mt-1 text-sm text-slate-400">URLでも参加コードでも同じ共同計画へ参加できます。</p>
                    </div>
                    <span class="badge badge-green">共同計画 ON</span>
                </div>

                <div class="mt-5 grid gap-4">
                    <div class="rounded-2xl border border-slate-700/80 bg-slate-950/45 p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-300">参加コード</p>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <strong class="text-xl tracking-[0.12em] text-slate-50">{{ $plan->collaboration_join_code }}</strong>
                            <button type="button" class="btn-secondary px-3 py-2 text-xs" data-copy-text="{{ $plan->collaboration_join_code }}">コピー</button>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-700/80 bg-slate-950/45 p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-300">共有URL</p>
                        <p class="mt-2 break-all text-sm text-slate-300">{{ $shareUrl }}</p>
                        <button type="button" class="btn-secondary mt-3 px-3 py-2 text-xs" data-copy-text="{{ $shareUrl }}">リンクをコピー</button>
                    </div>
                </div>

                <form method="POST" action="{{ route('plans.collaboration.regenerate', $plan) }}" class="mt-5" onsubmit="return confirm('現在の共有URLと参加コードを無効にして再発行しますか？');">
                    @csrf
                    <button type="submit" class="btn-secondary">招待情報を再発行</button>
                </form>
            </article>
            @else
            <article class="page-card p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-slate-50">共同計画に参加中</h2>
                        <p class="mt-1 text-sm text-slate-400">この計画の最新情報や参加メンバーをここで確認できます。</p>
                    </div>
                    <span class="badge badge-green">{{ ($collaborationRole ?? null) === 'editor' ? '編集者' : '閲覧者' }}</span>
                </div>
            </article>
            @endif

            @include('plans.partials.collaboration.artifacts-card')

            @include('plans.partials.collaboration.members-card')
        </div>

        <aside class="space-y-5">
            @include('plans.partials.collaboration.activity-card')

            <article class="page-card p-5">
                <h2 class="font-bold text-slate-50">権限ルール</h2>
                <dl class="mt-4 space-y-4 text-sm">
                    <div><dt class="font-bold text-amber-200">オーナー</dt><dd class="mt-1 leading-6 text-slate-400">共有設定、権限変更、計画設定、削除を含む全操作。</dd></div>
                    <div><dt class="font-bold text-sky-200">編集者</dt><dd class="mt-1 leading-6 text-slate-400">タスクの編集・作業開始・進捗更新など日常的な共同作業。</dd></div>
                    <div><dt class="font-bold text-violet-200">閲覧者</dt><dd class="mt-1 leading-6 text-slate-400">計画、ロードマップ、進捗の閲覧のみ。</dd></div>
                </dl>
            </article>

            @if ($canManage ?? false)
            <article class="page-card p-5">
                <h2 class="font-bold text-slate-50">共同計画を停止</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">参加リンクを無効化します。メンバー情報は保持するため、再開時に戻せます。</p>
                <form method="POST" action="{{ route('plans.collaboration.disable', $plan) }}" class="mt-4" onsubmit="return confirm('共同計画を停止しますか？');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-secondary">共同計画を停止</button>
                </form>
            </article>
            @endif
        </aside>
    </section>
@endif
@endsection
