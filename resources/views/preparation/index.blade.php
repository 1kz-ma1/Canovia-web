@extends('layouts.app')
@section('content')
<section class="page-card space-y-4 p-6">
    <p class="text-sm text-cyan-300">Canovia 先行リリースに向けた準備</p>
    <h1 class="text-2xl font-bold">今の目標を、これからの一歩につなげる。</h1>
    <p>診断で現在地を整理し、目標と計画を保存できます。演習・作業実行は準備中です。正式版の提供時期や機能は確約されません。</p>
    @auth
        <a class="btn-primary inline-flex" href="{{ route('preparation.interests.show') }}">30問で関心を見つける</a>
        @if(app(\App\Services\ReleaseLevelService::class)->allowsMinimum(1))
            <a class="btn-primary inline-flex" href="{{ route('personalization.show') }}">現在地を整理する</a>
        @endif
        <a class="btn-secondary inline-flex" href="{{ route('plans.create.manual') }}">目標を自分で入力する</a>
    @else
        <p>保存して後日続けるには、アカウントを作成するかログインしてください。</p>
        <a class="btn-primary inline-flex" href="{{ route('auth.register.form') }}">アカウントを作成する</a>
    @endauth
</section>
@auth
<section class="space-y-3"><h2 class="text-xl font-bold">保存したPlan</h2>
    @forelse($plans as $plan)
        <a class="page-card block p-5" href="{{ route('plans.show', $plan) }}">{{ $plan->title }}</a>
    @empty
        <p>まだPlanはありません。診断をせず、目標から保存することもできます。</p>
    @endforelse
</section>
@endauth
@endsection
