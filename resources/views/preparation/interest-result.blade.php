@extends('layouts.app')
@section('title', '関心のふりかえり | Canovia')
@section('content')
<x-preparation-journey :current="2" />
<section class="preparation-intro space-y-4 p-6">
    <h1 class="text-2xl font-bold">関心のふりかえり</h1>
    <p>今の回答からみた相対的な傾向です。能力・達成度や適性の判定ではありません。結果に合わせる必要はなく、自分の言葉で修正できます。</p>
    <a href="{{ route('preparation.interests.show') }}" class="underline">回答を見直す</a>
</section>
@if($unsupported)
    <p role="alert">保存済み記録の版を確認できないため、推定せず保持しています。</p>
@elseif($record)
    @php($result = $record['result'])
    <section class="page-card space-y-4 p-6" aria-labelledby="interest-chart-title" data-interest-result>
        <h2 id="interest-chart-title" class="text-xl font-bold">関心のバランス</h2>
        <p class="preparation-chart-counts"><span>回答 {{ $result['answered_count'] }}問</span><span>わからない {{ $result['unknown_count'] }}問</span><span>未回答 {{ $result['unanswered_count'] }}問</span></p>
        <p class="text-sm text-slate-300">割合は、回答できた分野の間での関心のバランスです。低い値は苦手という意味ではありません。</p>
        @if($result['status'] === 'insufficient_evidence')<p>まだ回答が少ないため、割合を表示できません。各分野の基本3問のうち2問以上の回答が必要です。</p>
        @elseif($result['status'] === 'no_relative_signal')<p>今の回答からは、相対的な関心の割合を求められません。低い能力や関心の欠如を意味しません。</p>@endif
        <ul class="space-y-4">
        @foreach($result['categories'] as $key => $category)
            <li data-interest-category="{{ $key }}" class="preparation-chart-row">
                <span class="preparation-chart-label"><img src="{{ asset('images/preparation/'.strtolower($key).'.webp') }}" width="320" height="320" loading="lazy" decoding="async" alt=""><span>{{ $category['label'] }}</span></span>
                <span class="preparation-chart-value">{{ $category['share_percent'] === null ? '判断材料が足りません' : $category['share_percent'].'%' }}</span>
                @if($category['share_percent'] !== null)<div role="img" aria-label="{{ $category['label'] }} {{ $category['share_percent'] }}%" class="preparation-chart-bar"><div style="width: {{ (int) $category['share_percent'] }}%"></div></div>@endif
            </li>
        @endforeach
        </ul>
        <details><summary class="cursor-pointer">割合の出し方</summary><p class="mt-3">回答を0〜3点にし、設問ごとの分野・重みで平均します。基本設問への回答が十分な分野だけで合計を100%に配分します。「わからない」と未回答は分母から除きます。心理尺度ではなく、選択を考えるための目安です。</p></details>
    </section>
    <form method="POST" action="{{ route('preparation.interests.review') }}" class="page-card space-y-5 p-6">
        @csrf<input type="hidden" name="review_present" value="1"><input type="hidden" name="revision" value="{{ old('revision', $revision) }}">
        <fieldset class="space-y-3"><legend class="text-xl font-bold">自分で選ぶ、今の関心（任意）</legend><p>結果と違っていても大丈夫です。選んだ内容を別に保存し、グラフの点数は書き換えません。未選択のままでも、すべて解除しても保存できます。</p>
        <div class="preparation-choice-grid">
        @foreach($bank['categories'] as $key => $label)<x-preparation-interest-choice name="confirmed_categories" :category="$key" :label="$label" :art="true" :checked="in_array($key, (session()->hasOldInput('review_present') ? (array) old('confirmed_categories', []) : $record['confirmed_categories']), true)" />@endforeach
        </div>
        </fieldset>
        <fieldset class="space-y-3"><legend class="text-xl font-bold">Canoviaで準備したいこと（任意）</legend><p>関心とは別の希望です。機能提供やSupport投票を意味しません。</p>
        <details class="preparation-optional" @if(count($record['requested_support']) || session()->hasOldInput('review_present')) open @endif><summary>準備の希望を選ぶ・見直す（複数選択・解除できます）</summary>
        <div class="preparation-choice-grid mt-4">
        @foreach($bank['categories'] as $key => $label)<x-preparation-interest-choice name="requested_support" :category="$key" :label="$label" :checked="in_array($key, (session()->hasOldInput('review_present') ? (array) old('requested_support', []) : $record['requested_support']), true)" />@endforeach
        </div></details>
        </fieldset>
        <button class="btn-secondary" type="submit">自分の関心・希望を保存する</button>
    </form>
@endif
<section class="page-card space-y-4 p-6">
    <h2 class="text-xl font-bold">次は、自分で選べます</h2>
    <p>Canovia Futureで開発・検証中の体験を確認できます。掲載されていても利用可能とは限らず、すべての関心分野に機能があるわけではありません。</p>
    <ul class="space-y-3" aria-label="関心につながる公開ロードマップ">
    @foreach($suggestedRoadmap as $entry)
        <li class="rounded-lg border border-slate-700 p-4"><p class="text-sm text-cyan-300">{{ $entry['label'] }} · {{ $entry['area'] }}</p><h3 class="font-bold">{{ $entry['title'] }}</h3><p>{{ $entry['description'] }}</p></li>
    @endforeach
    </ul>
    <a class="btn-secondary inline-flex" href="{{ route('feedback.index', ['from' => 'interest-diagnostic']) }}">関連するCanovia Futureを見る</a>
    <p>目標を残したくなったら、自分で内容を入力して保存できます。診断からPlanを自動生成・保存しません。</p>
    <a class="btn-primary inline-flex" href="{{ route('plans.create.manual') }}">自分で目標・Planを作る</a>
    <a class="underline inline-flex" href="{{ route('home') }}">今は作らずホームへ戻る</a>
</section>
@if($record && !$unsupported)
<form method="POST" action="{{ route('preparation.interests.destroy') }}" class="page-card space-y-3 p-6">
    @csrf @method('DELETE')<input type="hidden" name="revision" value="{{ $revision }}">
    <label class="flex items-start gap-3"><input class="mt-1" type="checkbox" name="confirm_delete" value="1" required><span>この30問の回答・結果・修正だけを削除します。Planや他の履歴は残ります。</span></label>
    <button class="btn-secondary" type="submit">診断の記録を削除する</button>
</form>
@endif
@endsection
