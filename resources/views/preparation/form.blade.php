@extends('layouts.app')
@section('content')
@unless($plan)<x-preparation-journey :current="4" />@endunless
<section class="page-card space-y-4 p-6">
<h1 class="text-2xl font-bold">{{ $plan ? '準備Planを編集する' : '目標と計画を保存する' }}</h1>
<p>まず目標と現在地を残しましょう。保存したPlanは同じアカウントで開き直せます。</p>
<form method="POST" action="{{ $plan ? route('plans.update', $plan) : route('plans.store') }}" class="space-y-5" data-preparation-plan-form>
    @csrf
    @if($plan)
        @method('PUT')
        <input type="hidden" name="preparation_revision" value="{{ $revision }}">
    @else
        <input type="hidden" name="create_request_id" value="{{ old('create_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
    @endif
    @unless($plan)
    <section class="space-y-3" data-preparation-templates hidden aria-label="任意の入力例">
        <h2 class="font-bold">始め方のヒント（任意）</h2>
        <p class="text-sm">これは静的な例文です。診断による提案や提供機能の約束ではありません。空のフォームにだけ入力し、保存前に自由に編集できます。</p>
        <div class="preparation-template-list">
            <button type="button" data-plan-example data-title="気になることを一つ調べる" data-description="気になるテーマを一つ選び、分かったことと次に知りたいことをメモする。" data-category="学習・探究">知りたいことを調べる</button>
            <button type="button" data-plan-example data-title="小さな作品を一つ形にする" data-description="作ってみたいものを決め、最初に試すことを一つ書き出す。" data-category="趣味・創作・表現">作ってみたいものを考える</button>
            <button type="button" data-plan-example data-title="これからの働き方を考える" data-description="気になる役割や働き方を挙げ、自分が大切にしたいことを整理する。" data-category="就職・キャリア">これからの選択肢を探す</button>
        </div>
        <p class="text-sm text-slate-300">入力済みの内容は上書きしません。例文を使わず、そのまま下の欄から書き始められます。</p>
        <p role="status" aria-live="polite"></p>
    </section>
    @endunless
    <label class="block">目標<input class="form-control mt-2 w-full" name="title" required maxlength="255" value="{{ old('title', $plan?->title ?? ($prefill['title'] ?? '')) }}"></label>
    <label class="block">現在地・計画のメモ<textarea class="form-control mt-2 w-full" name="description" rows="5">{{ old('description', $plan?->description ?? ($prefill['description'] ?? '')) }}</textarea></label>
    <label class="block">分野<input class="form-control mt-2 w-full" name="category" maxlength="100" value="{{ old('category', $plan?->category ?? ($prefill['category'] ?? '')) }}"></label>
    <label class="block">目標日（任意）<input class="form-control mt-2" type="date" name="deadline" value="{{ old('deadline', $plan?->deadline?->format('Y-m-d') ?? ($prefill['deadline'] ?? '')) }}"></label>
    @if($errors->has('preparation_revision'))
        <a class="btn-secondary inline-flex" href="{{ route('plans.edit', $plan) }}" data-preparation-conflict>最新の内容を開く</a>
    @endif
    <button type="submit" class="btn-primary" @disabled($errors->has('preparation_revision'))>{{ $plan ? '変更を保存する' : 'Planを保存する' }}</button>
</form>
</section>
@endsection
