@extends('layouts.app')
@section('content')
<section class="space-y-4">
    <h1 class="text-2xl font-bold">Canovia Future</h1>
    <p>開発や検証の現在地をお知らせします。掲載情報は一般公開や提供時期の保証ではありません。</p>
    @if($interestReturn ?? false)
        <p>関心を行動につなぐための開発近況です。全般の計画・記録づくりを中心に紹介しています。未提供の分野は推測せず、公開されている情報だけを掲載します。</p>
        <a class="underline" href="{{ route('preparation.interests.result') }}">関心のふりかえりへ戻る</a>
    @endif
    @php($cards = collect($publicRoadmapSections)->flatMap(fn ($section) => $section['items'])->values())
    @if($cards->isNotEmpty())
        <x-future-carousel id="preparation-future" label="開発ロードマップ" :count="$cards->count()">
            @foreach($cards as $entry)
                <li class="future-carousel-card">
                    <x-future-card-media :media="config('canovia_future_media.public_roadmap.'.$entry['key'])" />
                    <article class="future-card-body">
                        <span class="future-card-state">{{ $entry['label'] }} · {{ $entry['area'] }}</span>
                        <h2 class="text-xl font-bold">{{ $entry['title'] }}</h2>
                        <p class="future-card-copy">{{ $entry['description'] }}</p>
                        <p class="text-xs text-slate-400">最終更新：{{ $entry['updated_on'] }}</p>
                    </article>
                </li>
            @endforeach
        </x-future-carousel>
    @else
        <p>現在掲載している開発近況はありません。</p>
    @endif
    <p>ご意見やお問い合わせはサポートへお寄せください。</p>
</section>
@endsection
