@extends('layouts.app')
@section('content')
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
