@extends('layouts.app')

@section('title', '学習 Workspace | Canovia')

@section('content')
@php
    $composition = $studyWorkspaceComposition ?? ['surfaces' => []];
    $surfaceOptions = collect($studySurfaceOptions ?? []);
    $selectedSurface = (string) ($studySurface ?? 'work');
    $selectedSurfaceDefinition = is_array($studySurfaceDefinition ?? null)
        ? $studySurfaceDefinition
        : $surfaceOptions->firstWhere('key', $selectedSurface);
    $surfaceGroups = $surfaceOptions
        ->sortBy([
            ['category_order', 'asc'],
            ['view_order', 'asc'],
        ])
        ->groupBy('category_key');
@endphp

<div
    class="mx-auto max-w-7xl space-y-4"
    data-study-workspace
    data-study-surface="{{ $selectedSurface }}"
>
    <section class="page-card overflow-hidden p-0" data-study-workspace-shell>
        <div class="flex flex-col gap-3 px-4 py-3 sm:px-5 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0">
                <a
                    href="{{ route('workspace.study.top') }}"
                    class="specialized-workspace-top-link"
                    data-study-top-link
                >
                    <span aria-hidden="true">←</span>
                    <span>学習トップへ</span>
                </a>

                <div class="mt-3 flex min-w-0 items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-300/18 bg-amber-300/[0.06] text-amber-300">
                        <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current" aria-hidden="true">
                            <path d="M5 5.5h9.5a2 2 0 0 1 2 2V19H7a2 2 0 0 1-2-2V5.5Zm11.5 2H19v9.5a2 2 0 0 1-2 2h-.5" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-300">STUDY</p>
                            @if ($selectedSurfaceDefinition)
                                <span
                                    class="rounded-full border border-white/8 bg-slate-950/35 px-2 py-0.5 text-[9px] font-black text-slate-500"
                                    data-study-current-category="{{ data_get($selectedSurfaceDefinition, 'category_key') }}"
                                >
                                    {{ data_get($selectedSurfaceDefinition, 'category_label') }}
                                </span>
                            @endif
                        </div>

                        <p class="truncate text-sm font-black text-slate-100">
                            {{ $plan?->title ?? '学習Workspace' }}
                        </p>

                        @if ($plan && $selectedSurfaceDefinition)
                            <p class="mt-0.5 truncate text-[10px] text-slate-600">
                                {{ data_get($selectedSurfaceDefinition, 'label') }}
                                ·
                                {{ data_get($selectedSurfaceDefinition, 'description') }}
                            </p>
                        @elseif (! $plan)
                            <p class="mt-0.5 text-[10px] text-slate-600">
                                学習トップからPlanを選ぶと、ここにPlan-scoped Study Workspaceが表示されます。
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($plan)
                <form
                    method="GET"
                    action="{{ route('workspace.study.index') }}"
                    class="specialized-workspace-view-navigation"
                    data-study-navigation-form
                >
                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">

                    <label class="specialized-workspace-view-control">
                        <span>VIEW</span>
                        <select
                            id="study-workspace-surface"
                            name="surface"
                            data-study-surface-select
                        >
                            @foreach ($surfaceGroups as $surfaceGroup)
                                @php
                                    $firstSurface = $surfaceGroup->first();
                                @endphp
                                <optgroup label="{{ data_get($firstSurface, 'category_label') }}">
                                    @foreach ($surfaceGroup as $surfaceItem)
                                        <option
                                            value="{{ data_get($surfaceItem, 'key') }}"
                                            @selected(data_get($surfaceItem, 'key') === $selectedSurface)
                                        >
                                            {{ data_get($surfaceItem, 'label') }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>

                    <noscript>
                        <button type="submit" class="btn-secondary min-h-9 px-3 text-xs">表示</button>
                    </noscript>
                </form>
            @endif
        </div>
    </section>

    @if (! $plan)
        @if (filled(data_get($firstUseContext ?? [], 'goal')))
            <section class="page-card p-4 sm:p-5" data-study-first-use-context>
                <p class="text-xs font-semibold text-slate-400">診断で登録した学習目標</p>
                <p class="mt-1 break-words text-sm font-semibold text-slate-100">{{ data_get($firstUseContext, 'goal') }}</p>
                <p class="mt-2 text-xs text-slate-400">この目標からPlanを作成できます。診断の回答をもう一度入力する必要はありません。</p>
                <a href="{{ route('personalization.result') }}" class="mt-3 inline-flex text-xs font-semibold text-cyan-300 underline underline-offset-4">診断からPlan候補を見る</a>
            </section>
        @endif
        <div data-study-workspace-no-plan>
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding ?? null,
            ])
        </div>
    @else
        @switch($selectedSurface)
            @case('preparation')
                @include('workspace.study.views.preparation')
                @break

            @case('analysis')
                @include('workspace.study.views.analysis')
                @break

            @case('history')
                @include('workspace.study.views.history')
                @break

            @default
                @include('workspace.study.views.work')
        @endswitch
    @endif
</div>

<script>
    (() => {
        const form = document.querySelector('[data-study-navigation-form]');
        const select = form?.querySelector('[data-study-surface-select]');

        if (!form || !select) return;

        select.addEventListener('change', () => {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        });
    })();
</script>
@endsection
