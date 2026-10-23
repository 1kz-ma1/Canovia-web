@extends('layouts.app')
@section('content')
<section class="page-card space-y-4 p-6">
    <h1 class="text-2xl font-bold">現在地を保存しました</h1>
    <p>入力した内容をもとにしたPlan候補です。必要なものだけ選び、保存前に編集できます。</p>
    @foreach($seeds as $seed)
        <form method="POST" action="{{ route('personalization.seed.accept', $seed['key']) }}" class="page-card space-y-3 p-4">
            @csrf<h2 class="font-bold">{{ $seed['title'] }}</h2><p>{{ $seed['description'] }}</p>
            <button type="submit" class="btn-primary">この候補を編集する</button>
        </form>
    @endforeach
    <a href="{{ route('plans.create.manual') }}" class="btn-secondary inline-flex">目標を自分で入力する</a>
</section>
@endsection
