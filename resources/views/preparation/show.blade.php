@extends('layouts.app')
@section('content')
<article class="page-card space-y-5 p-6" data-preparation-plan>
    <p class="text-sm text-cyan-300">保存した準備Plan</p>
    <h1 class="break-words text-2xl font-bold">{{ $plan->title }}</h1>
    @if($plan->description)<p class="whitespace-pre-wrap break-words">{{ $plan->description }}</p>@endif
    @if($plan->category)<p>分野：{{ $plan->category }}</p>@endif
    @if($plan->deadline)<p>目標日：{{ $plan->deadline->format('Y-m-d') }}</p>@endif
    <p>同じアカウントで再ログインすると、このPlanから準備を続けられます。演習・作業実行は今後の提供予定です。</p>
    <a class="btn-primary inline-flex" href="{{ route('plans.edit', $plan) }}">目標・計画を編集する</a>
</article>
@endsection
