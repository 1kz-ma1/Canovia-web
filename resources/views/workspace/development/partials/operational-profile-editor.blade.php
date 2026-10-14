@php
    $operationalProfile = is_array($developmentOperationalProfile ?? null)
        ? $developmentOperationalProfile : [];
    $profileStageOptions = [
        'unknown' => '未回答',
        'prototype' => '試作・ローカル開発',
        'preview' => '限定公開・検証',
        'public_launch' => '初回一般公開の準備',
        'live' => '一般公開中',
    ];
    $profileRuntimeOptions = [
        'unknown' => '未回答',
        'web' => 'Web / PWA',
        'ios' => 'iOS',
        'android' => 'Android',
        'hybrid' => 'Web + モバイル（ハイブリッド）',
        'backend' => 'API / サーバー中心',
        'other' => 'その他・複数',
    ];
    $profileDataOptions = [
        'unknown' => '未回答',
        'none' => '永続データは保存しない',
        'local_device' => '端末内に保存',
        'server_db' => 'サーバーDBに保存',
        'cloud_files' => 'クラウドファイルに保存',
        'mixed' => '複数・その他',
    ];
@endphp

<details class="rounded-xl border border-white/8 bg-slate-950/20 px-3 py-2.5" data-development-operational-profile-editor>
    <summary class="cursor-pointer text-xs font-semibold text-slate-400">
        開発環境と保存方式を登録する（任意）
    </summary>
    <p class="mt-3 text-[11px] leading-5 text-slate-500">
        選んだ項目だけをこのPlanに保存し、提案を調整します。未回答のままでも利用できます。
        自己申告であり、実際の公開環境やバックアップの検証にはなりません。接続先・秘密鍵・認証情報は入力しないでください。
    </p>
    <form
        method="POST"
        action="{{ route('workspace.development.operational_profile.update', $plan) }}"
        class="mt-3 space-y-3"
        data-development-operational-profile-form
    >
        @csrf
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block text-[11px] font-semibold text-slate-300">
                公開段階
                <select name="stage" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950 p-2 text-xs text-slate-100" data-development-profile-stage>
                    @foreach ($profileStageOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('stage', data_get($operationalProfile, 'stage', 'unknown')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-[11px] font-semibold text-slate-300">
                実行環境
                <select name="runtime" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950 p-2 text-xs text-slate-100" data-development-profile-runtime>
                    @foreach ($profileRuntimeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('runtime', data_get($operationalProfile, 'runtime', 'unknown')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-[11px] font-semibold text-slate-300">
                データ保存方式
                <select name="data_store" class="mt-1 w-full rounded-xl border border-white/10 bg-slate-950 p-2 text-xs text-slate-100" data-development-profile-data-store>
                    @foreach ($profileDataOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('data_store', data_get($operationalProfile, 'data_store', 'unknown')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @if ($errors->has('stage') || $errors->has('runtime') || $errors->has('data_store'))
            <p class="text-xs text-rose-300" role="alert">選択内容を確認してください。</p>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-[10px] text-slate-500">必要に応じて後から変更できます。</span>
            <button class="btn-secondary min-h-10 px-4 text-xs" type="submit" data-development-profile-save>
                このPlanの情報を保存
            </button>
        </div>
    </form>
</details>
