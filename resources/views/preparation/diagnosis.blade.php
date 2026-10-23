@extends('layouts.app')
@section('content')
<section class="page-card space-y-4 p-6">
    <h1 class="text-2xl font-bold">現在地を整理する</h1>
    <p>入力はいつでも見直せます。診断をせず、目標から保存することもできます。</p>
    <form method="POST" action="{{ route('personalization.store') }}" class="space-y-5" data-preparation-diagnosis>
        @csrf
        <label class="block">準備したい分野
            <select name="domains[]" class="form-control mt-2">
                @foreach(['study' => '学習', 'development' => '開発', 'unsure' => 'まだ決めていない'] as $key => $label)
                    <option value="{{ $key }}" @selected(old('domains.0', data_get($context, 'domains.0')) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">学習の目標（任意）<input name="study_goal" class="form-control mt-2 w-full" maxlength="255" value="{{ old('study_goal', data_get($context, 'domain_context.study.goal')) }}"></label>
        <label class="block">学習の現在地
            <select name="study_stage" class="form-control mt-2">
                @foreach(['not_started' => 'これから始める', 'beginner' => '基礎から', 'started' => '進めている', 'reviewing' => '復習している'] as $key => $label)
                    <option value="{{ $key }}" @selected(old('study_stage', data_get($context, 'domain_context.study.stage')) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">開発の目標（任意）<input name="development_goal" class="form-control mt-2 w-full" maxlength="255" value="{{ old('development_goal', data_get($context, 'domain_context.development.goal')) }}"></label>
        <label class="block">週の目安
            <select name="weekly_capacity" class="form-control mt-2">
                @foreach(['unknown' => 'まだ分からない', 'under_2' => '2時間未満', '2_5' => '2〜5時間', '5_10' => '5〜10時間', '10_plus' => '10時間以上'] as $key => $label)
                    <option value="{{ $key }}" @selected(old('weekly_capacity', data_get($context, 'common_context.weekly_capacity')) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary">現在地を保存して候補を見る</button>
    </form>
    <a href="{{ route('plans.create.manual') }}" class="btn-secondary inline-flex">診断せず目標を入力する</a>
</section>
@endsection
