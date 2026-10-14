@php
    $prelaunch = is_array($developmentPrelaunchPreparation ?? null)
        ? $developmentPrelaunchPreparation
        : null;
    $operational = is_array($developmentOperationalEvidence ?? null)
        ? $developmentOperationalEvidence
        : null;
    $priorityCheck = is_array(data_get($operational, 'primary'))
        ? data_get($operational, 'primary')
        : null;
    $statusLabels = [
        'unknown' => '未確認',
        'observed' => 'GitHub観測',
        'stale' => '対象が不一致',
        'attention' => '対応が必要',
        'review' => '照合が必要',
        'declared' => '本人申告',
        'not_applicable' => '暫定的に対象外',
        'operator_confirmed' => '所有者の実施申告',
    ];
@endphp

@if ($prelaunch)
    <aside
        class="page-card border-amber-300/15 p-4 sm:p-5"
        data-development-prelaunch-preparation
        data-development-prelaunch-status="{{ data_get($prelaunch, 'status', 'unknown') }}"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-amber-200">
                    公開・運用の準備 · 提案（未検証）
                </p>
                <h3 class="mt-2 text-sm font-black text-slate-100">
                    {{ data_get($priorityCheck, 'title', data_get($prelaunch, 'title')) }}
                </h3>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-400">
                    {{ data_get($prelaunch, 'detail') }}
                </p>
                @if ($priorityCheck)
                    <p class="mt-3 text-xs leading-5 text-amber-100" data-development-operational-primary>
                        優先確認：{{ data_get($priorityCheck, 'detail') }}
                    </p>
                    <p class="mt-1 text-[11px] text-slate-500">
                        状態：{{ $statusLabels[data_get($priorityCheck, 'status', 'unknown')] ?? '未確認' }}
                        · 根拠：{{ data_get($priorityCheck, 'source') }}
                    </p>
                @endif
                <p class="mt-2 text-[11px] text-slate-500" data-development-prelaunch-basis>
                    提案のきっかけ：{{ data_get($prelaunch, 'basis') }}
                </p>
                <p class="mt-1 text-[11px] text-slate-500">
                    この画面では本番環境やバックアップを検証していません。準備不足の断定やリリース許可ではありません。
                    既存の7 Quality Gatesとは別の確認です。
                </p>
                @if ($operational && count(data_get($operational, 'checks', [])) > 0)
                    <details class="mt-4" data-development-operational-evidence>
                        <summary class="cursor-pointer text-xs font-semibold text-cyan-200">
                            根拠と公開準備の確認項目を見る
                        </summary>
                        <div class="mt-3 space-y-2">
                            @foreach (data_get($operational, 'checks', []) as $check)
                                <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3"
                                    data-development-operational-check="{{ data_get($check, 'key') }}"
                                    data-development-operational-status="{{ data_get($check, 'status', 'unknown') }}">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs font-semibold text-slate-200">{{ data_get($check, 'title') }}</p>
                                        <span class="text-[10px] font-semibold text-slate-400">
                                            {{ $statusLabels[data_get($check, 'status', 'unknown')] ?? '未確認' }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-[11px] leading-5 text-slate-400">{{ data_get($check, 'detail') }}</p>
                                    <p class="mt-1 text-[10px] text-slate-500">
                                        根拠：{{ data_get($check, 'source') }}
                                        @if (data_get($check, 'sha'))
                                            · SHA {{ substr((string) data_get($check, 'sha'), 0, 10) }}
                                        @endif
                                        @if (data_get($check, 'observedAt'))
                                            · 観測日時 {{ data_get($check, 'observedAt') }}
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[10px] leading-4 text-slate-500">
                            GitHub観測は本番サーバー・DB・復元実績の独立検証ではありません。
                            保存データの有無によってバックアップの必要性は変わります。
                        </p>
                    </details>
                @endif
            </div>
            @if ($canManage ?? false)
                <a
                    href="{{ route('plans.ai_task_assistant.show', ['plan' => $plan, 'return_to_workspace' => 1]) }}"
                    class="btn-secondary min-h-11 shrink-0 px-4 text-xs"
                    data-development-prelaunch-plan-task
                >
                    準備タスクを計画する
                </a>
            @endif
        </div>
    </aside>
@else
    <div class="px-1 text-right" data-development-prelaunch-entry>
        <a
            href="{{ route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work', 'release_preflight' => 1]) }}"
            class="text-[11px] font-semibold text-slate-500 underline underline-offset-4 hover:text-slate-300"
        >
            公開前の準備を確認する
        </a>
    </div>
@endif

@if ($developmentOperationalProfileEditable ?? false)
    @include('workspace.development.partials.operational-profile-editor')
    @if ($prelaunch)
        @include('workspace.development.partials.operational-attestations')
    @endif
@endif
