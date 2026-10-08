@extends('layouts.app')

@section('title', '職種探索 | Canovia')

@section('content')
<div class="mx-auto max-w-4xl space-y-5" data-career-exploration>
    <header class="page-card p-5 sm:p-6">
        <a href="{{ route('workspace.career.index') }}" class="text-xs font-bold text-emerald-300">← Career Workspace</a>
        <p class="mt-4 text-[10px] font-black uppercase tracking-[.17em] text-emerald-300">CAREER EXPLORE</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50">職種を調べるための現在地を整理する</h1>
        <p class="mt-2 text-sm leading-6 text-slate-400">
            向いている職業や採用可能性を判定する診断ではありません。いま興味のあること・希望条件を整理して、まず比較する職種を選びます。
            分からない項目は「まだ分からない」で進められます。
        </p>
    </header>

    @if ($completed)
        <section class="page-card p-5 sm:p-6" data-career-exploration-result>
            <p class="text-xs font-black uppercase tracking-[.14em] text-emerald-300">EXPLORATION START</p>
            <h2 class="mt-2 text-xl font-black text-slate-50">まず比較したい仕事の方向性</h2>
            <p class="mt-2 text-xs leading-5 text-slate-400">回答内容をもとに選んだ探索例です。順位・適性スコアではありません。実際の仕事内容や経験から考えを変えて構いません。</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach ($directions as $direction)
                    <article class="rounded-2xl border border-white/10 bg-slate-950/30 p-4">
                        <h3 class="text-sm font-black text-slate-100">{{ $direction['title'] }}</h3>
                        <p class="mt-2 text-xs leading-5 text-slate-400">{{ $direction['reason'] }}</p>
                        <p class="mt-3 text-xs leading-5 text-emerald-300">{{ $direction['next_step'] }}</p>
                    </article>
                @endforeach
            </div>
            <div class="mt-5 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('career.explore.plan') }}">
                    @csrf
                    <button type="submit" class="btn-primary min-h-11 px-4" data-career-exploration-create-plan>この現在地からCareer Planを作る</button>
                </form>
                <a href="#career-exploration-form" class="btn-secondary min-h-11 px-4">回答を見直す</a>
            </div>
            <p class="mt-3 text-[11px] leading-5 text-slate-500">
                現在の回答はこのブラウザのセッションに保存しています。Plan作成前に就活データとして確定保存することはありません。
            </p>
        </section>
    @endif

    <section id="career-exploration-form" class="page-card p-5 sm:p-6" data-career-exploration-form>
        <h2 class="text-lg font-black text-slate-50">{{ $completed ? '回答を更新する' : 'Canoviaで自己分析する' }}</h2>
        @if (count($known))
            <p class="mt-2 text-xs leading-5 text-emerald-300" data-career-exploration-coverage>
                確認済みの外部診断情報 {{ count($known) }}項目は再質問しません。必要な項目だけ回答してください。
            </p>
        @else
            <p class="mt-2 text-xs leading-5 text-slate-400">数分の短い質問から始めます。後から別の診断結果や実際の経験に合わせて見直せます。</p>
        @endif
        <form method="POST" action="{{ route('career.explore.store') }}" class="mt-5 space-y-5">
            @csrf
            @foreach ($questions as $key => $question)
                <fieldset class="rounded-2xl border border-white/10 bg-slate-950/25 p-4">
                    <legend class="px-1 text-sm font-black text-slate-100">{{ $question['label'] }}</legend>
                    @error('answers.'.$key)
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach ($question['options'] as $value => $label)
                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-white/8 p-3 text-xs leading-5 text-slate-300">
                                <input
                                    type="radio"
                                    name="answers[{{ $key }}]"
                                    value="{{ $value }}"
                                    class="mt-1"
                                    @checked(old('answers.'.$key, $answers[$key] ?? null) === $value)
                                    required
                                >
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary min-h-11 px-4" data-career-exploration-submit>探索候補を確認する</button>
                <a href="{{ route('workspace.career.index') }}" class="btn-secondary min-h-11 px-4">Careerに戻る</a>
            </div>
        </form>
    </section>
</div>
@endsection
