@extends('layouts.app')

@section('title', 'Canovia Future | Canovia')

@section('content')
    <div class="mx-auto max-w-5xl space-y-8">
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

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($publicRoadmapSections as $section)
                    <section class="page-card space-y-4 p-5 sm:p-6" aria-labelledby="public-roadmap-{{ $section['key'] }}">
                        <header class="space-y-1">
                            <h3 id="public-roadmap-{{ $section['key'] }}" class="text-lg font-black text-slate-50">{{ $section['title'] }}</h3>
                            <p class="text-xs leading-5 text-slate-400">{{ $section['description'] }}</p>
                        </header>
                        @forelse ($section['items'] as $entry)
                            <article class="rounded-xl border border-slate-700/70 bg-slate-900/60 p-4">
                                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-300">
                                    <span class="rounded-full bg-slate-800 px-2.5 py-1 font-bold">{{ $entry['label'] }}</span>
                                    <span>{{ $entry['area'] }}</span>
                                </div>
                                <h4 class="mt-3 font-bold text-slate-50">{{ $entry['title'] }}</h4>
                                <p class="mt-2 text-sm leading-6 text-slate-400">{{ $entry['description'] }}</p>
                                <p class="mt-3 text-xs text-slate-500">
                                    最終更新: {{ $entry['updated_on'] }}
                                    @isset($entry['release_verified_on'])
                                        · 公開確認日: {{ $entry['release_verified_on'] }}
                                    @endisset
                                </p>
                            </article>
                        @empty
                            <p class="rounded-xl border border-dashed border-slate-700 p-4 text-sm leading-6 text-slate-400">
                                @if ($section['key'] === 'released')
                                    公開と利用条件を確認できた機能を、ここに掲載します。
                                @else
                                    現在、この段階で公開している情報はありません。
                                @endif
                            </p>
                        @endforelse
                    </section>
                @endforeach
            </div>
            <p class="text-sm text-slate-400">
                気になる機能があれば、下の<a class="font-bold text-sky-300 underline underline-offset-4" href="#support-candidates">無料Support</a>やフィードバックから意見を伝えられます。
                Supportは実装時期・機能提供・有料特典の約束ではありません。
            </p>
        </section>

        <section id="support-candidates" class="space-y-3" aria-labelledby="support-candidates-heading">
            <h2 id="support-candidates-heading" class="text-xl font-black text-slate-50">今後ほしい機能への無料Support</h2>
            <p class="text-sm leading-6 text-slate-400">開発ロードマップとは別に、機能への期待を投票できます。以前のSupportもそのまま有効です。</p>
        </section>

        <section class="grid gap-4 md:grid-cols-2">
            @forelse ($features as $item)
                @php
                    /** @var \App\Models\RoadmapFeature $feature */
                    $feature = $item['model'];
                @endphp
                <article class="page-card flex h-full flex-col p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex rounded-full border border-slate-700 bg-slate-900/80 px-2.5 py-1 text-[10px] font-black tracking-[0.12em] text-slate-300">
                                {{ $feature->status->label() }}
                            </span>
                            <h2 class="mt-3 text-lg font-black text-slate-50">{{ $feature->title }}</h2>
                        </div>
                        @if ($feature->status->value === 'released')
                            <span class="text-xs font-black text-emerald-300">RELEASED</span>
                        @endif
                    </div>

                    @if ($feature->description)
                        <p class="mt-3 flex-1 text-sm leading-6 text-slate-400">{{ $feature->description }}</p>
                    @endif

                    <div class="mt-5">
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <strong class="text-slate-100">{{ number_format($item['votes']) }} / {{ number_format($feature->threshold) }} Support</strong>
                            <span class="text-slate-500">{{ $item['progress'] }}%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-800" aria-label="Support progress">
                            <div class="h-full rounded-full bg-sky-400" style="width: {{ $item['progress'] }}%"></div>
                        </div>
                    </div>

                    <div class="mt-5">
                        @if (! $feature->voting_enabled)
                            <button type="button" class="btn-secondary w-full justify-center" disabled>Support受付を停止中</button>
                        @elseif ($item['supported'])
                            <form method="POST" action="{{ route('feedback.future.unsupport', $feature) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary w-full justify-center">Support済み・取り消す</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('feedback.future.support', $feature) }}">
                                @csrf
                                <button type="submit" class="btn-primary w-full justify-center">この機能をSupport</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="page-card p-6 md:col-span-2">
                    <p class="text-sm text-slate-400">現在Supportを受け付けている候補はありません。</p>
                </div>
            @endforelse
        </section>

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
