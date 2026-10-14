@php
    $prelaunch = is_array($developmentPrelaunchPreparation ?? null)
        ? $developmentPrelaunchPreparation
        : null;
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
                    {{ data_get($prelaunch, 'title') }}
                </h3>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-400">
                    {{ data_get($prelaunch, 'detail') }}
                </p>
                <p class="mt-2 text-[11px] text-slate-500" data-development-prelaunch-basis>
                    提案のきっかけ：{{ data_get($prelaunch, 'basis') }}
                </p>
                <p class="mt-1 text-[11px] text-slate-500">
                    この画面では本番環境やバックアップを検証していません。準備不足の断定やリリース許可ではありません。
                    既存の7 Quality Gatesとは別の確認です。
                </p>
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
