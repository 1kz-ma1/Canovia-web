@extends('layouts.app')
@section('content')
<section class="page-card space-y-4 p-6">
    <h1 class="text-2xl font-bold">アカウント</h1>
    <p>{{ $user->name }}</p>
    <p>目標と計画はこのアカウントに保存されます。外部サービスとの連携は先行版では提供しません。</p>
    <a class="btn-secondary inline-flex" href="{{ route('legal.support') }}">データの確認・削除について相談する</a>
</section>
@endsection
