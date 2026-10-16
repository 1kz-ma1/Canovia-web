@extends('layouts.app')

@section('title', 'Canovia Future | Canovia')

@section('content')
    <div class="future-page mx-auto max-w-5xl space-y-8">
        <header class="space-y-4">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.18em] text-sky-300">CANOVIA FUTURE</p>
                <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-50 sm:text-4xl">次のCanoviaを、一緒に選ぶ</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-400 sm:text-base">
                    Canoviaが考えている次の候補へ、欲しいと思ったものをSupportできます。
                    Support数は需要を確かめるための材料で、到達したら必ず実装されるという意味ではありません。
                </p>
            </div>
            <div class="rounded-2xl border border-sky-400/20 bg-sky-400/5 p-4 text-sm leading-6 text-slate-300">
                <strong class="text-sky-200">500 Support</strong> は「Roadmap入り・正式検討を始める目安」です。
                最終的な優先順位は、Canovia全体の体験・安全性・実装コスト・他のFeedbackも合わせて判断します。
            </div>
        </header>

        <section id="canovia-roadmap" class="space-y-5" aria-labelledby="canovia-roadmap-heading">
            <header class="space-y-2">
                <p class="text-xs font-black uppercase tracking-[0.14em] text-sky-300">DEVELOPMENT ROADMAP</p>
                <h2 id="canovia-roadmap-heading" class="text-2xl font-black text-slate-50">Canoviaの開発ロードマップ</h2>
                <p class="max-w-3xl text-sm leading-6 text-slate-400">
                    開発中・検証中・公開済みの現在地をお知らせします。掲載情報は開発チームが手動で確認・更新しています。
                    GitHubでの実装完了やテスト成功だけでは公開済みになりません。
                </p>
            </header>

            @php
                $roadmapCards = collect($publicRoadmapSections)->flatMap(fn ($section) => $section['items'])->values();
                $publicMedia = config('canovia_future_media.public_roadmap', []);
            @endphp
            @if ($roadmapCards->isNotEmpty())
                <x-future-carousel id="public-future" label="開発ロードマップ" :count="$roadmapCards->count()">
                    @foreach ($roadmapCards as $entry)
                        <li class="future-carousel-card" data-active="{{ $loop->first ? 'true' : 'false' }}" data-public-roadmap-key="{{ $entry['key'] }}">
                            <x-future-card-media :media="$publicMedia[$entry['key']] ?? null" />
                            <article class="future-card-body">
                                <span class="future-card-state">{{ $entry['label'] }} · {{ $entry['area'] }}</span>
                                <h3>{{ $entry['title'] }}</h3>
                                <p class="future-card-copy future-card-summary">{{ $entry['description'] }}</p>
                                <p class="text-xs text-slate-400">最終更新: {{ $entry['updated_on'] }}
                                    @isset($entry['release_verified_on']) · 公開確認日: {{ $entry['release_verified_on'] }} @endisset
                                </p>
                                <div class="future-card-actions">
                                    <p class="mb-2 text-xs text-slate-400">開発近況 · 投票対象ではありません</p>
                                    <details class="rounded-xl border border-slate-600 p-3">
                                        <summary class="cursor-pointer font-bold text-sky-200">詳しく見る</summary>
                                        <p class="future-card-copy mt-3">{{ $entry['description'] }}</p>
                                    </details>
                                </div>
                            </article>
                        </li>
                    @endforeach
                </x-future-carousel>
            @else
                <p class="page-card p-5 text-slate-400">現在掲載している開発近況はありません。</p>
            @endif
            <details class="rounded-xl border border-slate-700 p-4 text-sm text-slate-400">
                <summary class="cursor-pointer">開発段階ごとの掲載状況</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($publicRoadmapSections as $section)
                        <div>
                            <h3 class="font-bold text-slate-200">{{ $section['title'] }} · {{ count($section['items']) }}件</h3>
                            <p class="mt-1 text-xs leading-5">{{ $section['description'] }}</p>
                            @if ($section['key'] === 'released' && empty($section['items']))
                                <p class="mt-1 text-xs">公開と利用条件を確認できた機能を、ここに掲載します。</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
            <p class="text-sm text-slate-400">
                気になる機能があれば、下の<a class="font-bold text-sky-300 underline underline-offset-4" href="#support-candidates">無料Support</a>やフィードバックから意見を伝えられます。
                Supportは実装時期・機能提供・有料特典の約束ではありません。
            </p>
        </section>

        <section id="support-candidates" class="space-y-3" aria-labelledby="support-candidates-heading">
            <h2 id="support-candidates-heading" class="text-xl font-black text-slate-50">今後ほしい機能への無料Support</h2>
            <p class="text-sm leading-6 text-slate-400">開発ロードマップとは別に、機能への期待を投票できます。以前のSupportもそのまま有効です。</p>
        </section>

        @if ($features->isNotEmpty())
            <x-future-carousel id="support-future" label="無料Support候補" :count="$features->count()">
                @foreach ($features as $item)
                    @php($feature = $item['model'])
                    <li class="future-carousel-card" data-active="{{ $loop->first ? 'true' : 'false' }}" data-support-feature="{{ $feature->feature_key }}">
                        <x-future-card-media />
                        <article class="future-card-body">
                            <span class="future-card-state">{{ $feature->status->label() }}</span>
                            <h3>{{ $feature->title }}</h3>
                            <p class="future-card-copy">{{ $feature->description }}</p>
                            <div>
                                <strong class="text-slate-100" data-support-count>{{ number_format($item['votes']) }} Support</strong>
                                <p class="mt-1 text-xs text-slate-400">検討の目安 {{ number_format($feature->threshold) }} Support · {{ $item['progress'] }}%</p>
                            </div>
                            <div class="future-card-actions">
                                @if (! $feature->voting_enabled)
                                    <p class="rounded-xl border border-slate-600 p-3 text-center text-sm text-slate-300">Support受付を停止中</p>
                                @elseif ($item['supported'])
                                    <form method="POST" action="{{ route('feedback.future.unsupport', $feature) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-secondary">Support済み・取り消す</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('feedback.future.support', $feature) }}">
                                        @csrf
                                        <button type="submit" class="btn-primary"><span aria-hidden="true">♡</span> この機能をSupport</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    </li>
                @endforeach
            </x-future-carousel>
        @else
            <p class="page-card p-6 text-sm text-slate-400">現在Supportを受け付けている候補はありません。</p>
        @endif

        <section class="page-card p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">OTHER FEEDBACK</p>
                    <h2 class="mt-2 text-xl font-black text-slate-50">その他のフィードバック</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">候補にない要望、使いづらさ、不具合、良かった点はこれまで通り自由に送れます。</p>
                </div>
                <button type="button" class="btn-secondary shrink-0 justify-center" data-feedback-open>自由記述で送る</button>
            </div>
        </section>
    </div>
@endsection
