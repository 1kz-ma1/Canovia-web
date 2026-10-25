@extends('layouts.app')
@section('title', '30問で関心を見つける | Canovia')
@section('content')
<section class="page-card space-y-4 p-6">
    <p class="text-sm text-cyan-300">自分に合った準備のために</p>
    <h1 class="text-2xl font-bold">30問で関心を見つける</h1>
    <p>日常の選び方から、今の関心を整理します。能力・達成度・性格を判定する検査ではなく、心理学的な妥当性は未検証です。</p>
    <p>回答は任意です。「わからない」や未回答は肯定・否定として採点しません。途中保存して後日続けることもできます。</p>
    <a class="inline-flex underline" href="{{ route('plans.create.manual') }}">診断をスキップして目標を入力する</a>
</section>
@if($unsupported)
    <section class="page-card p-6" role="alert">保存済みの記録の版を確認できません。記録を書き換えず保持しています。目標は診断なしでも作成できます。</section>
@else
    @php($answers = old('answers', $record['answers'] ?? []))
    <form method="POST" action="{{ route('preparation.interests.store') }}" class="space-y-5" data-interest-form>
        @csrf
        <input type="hidden" name="revision" value="{{ old('revision', $revision) }}">
        <input type="hidden" name="step" value="{{ old('step', $record['step'] ?? 1) }}">
        <p aria-live="polite" data-interest-progress class="text-cyan-300"></p>
        @foreach(array_chunk($bank['items'], 5, true) as $page => $items)
            <section class="page-card space-y-6 p-6" data-interest-page="{{ $page + 1 }}" aria-label="質問 {{ $page * 5 + 1 }}〜{{ $page * 5 + 5 }}">
                @foreach($items as $id => $item)
                    <fieldset class="min-w-0 space-y-3" data-interest-question="{{ $id }}">
                        <legend class="font-semibold">{{ substr($id, 1) }}. {{ $item['text'] }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                        @foreach(['0' => 'あてはまらない', '1' => 'あまりあてはまらない', '2' => 'ややあてはまる', '3' => 'あてはまる', 'unknown' => 'わからない'] as $value => $label)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-600 p-3 focus-within:ring-2 focus-within:ring-cyan-300">
                                <input type="radio" name="answers[{{ $id }}]" value="{{ $value }}" @checked(isset($answers[$id]) && (string) $answers[$id] === (string) $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </section>
        @endforeach
        <div class="flex flex-wrap gap-3" data-interest-paging hidden>
            <button type="button" class="btn-secondary" data-interest-prev>前の5問</button>
            <button type="button" class="btn-secondary" data-interest-next>次の5問</button>
        </div>
        <div class="page-card space-y-4 p-6">
            <label class="flex items-start gap-3"><input class="mt-1" type="checkbox" name="save_consent" value="1" required @checked(old('save_consent', isset($record['personal_storage_consent_at'])))><span>回答・結果・自分の修正を、このアカウントへ保存します。市場分析や外部AI送信への同意ではありません。記録は結果画面で削除できます。</span></label>
            <div class="flex flex-wrap gap-3">
                <button class="btn-secondary" type="submit" name="action" value="save">途中保存する</button>
                <button class="btn-primary" type="submit" name="action" value="result">今の回答で結果を見る</button>
            </div>
            <p role="status" aria-live="polite" data-interest-save-status></p>
            <a href="{{ route('preparation.interests.show') }}" data-interest-latest hidden class="underline">最新の記録を開く（この入力は移りません）</a>
        </div>
    </form>
    @if($record)<a class="underline" href="{{ route('preparation.interests.result') }}">保存済みの結果を確認する</a>@endif
@endif
@endsection
