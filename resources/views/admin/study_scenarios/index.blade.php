@extends('layouts.app')

@section('title', 'Study Scenario Lab | Canovia Admin')

@section('content')
    @php
        $activeCount = collect($scenarios)
            ->filter(fn ($scenario) => ! empty($scenario['fixture']))
            ->count();
    @endphp

    <div class="mx-auto max-w-6xl space-y-6" data-study-scenario-lab>
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 shadow-[0_20px_60px_rgba(2,6,23,.22)] sm:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">STUDY QA / ADMIN ONLY</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">Study Scenario Lab</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">
                        普段自分では再現しにくい学習Stateを実データで生成し、
                        通常のStudy Workspaceをそのまま検証します。
                        UI専用のFake Stateは使いません。
                    </p>
                </div>
                <div class="rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.04] px-4 py-3 text-xs leading-5 text-slate-400">
                    <p class="font-black text-cyan-200">CANOVIA_STUDY_SCENARIO_LAB_ENABLED=true</p>
                    <p class="mt-1">Super Admin + 明示フラグの両方が必要です。</p>
                </div>
            </div>
        </header>

        @if (session('success'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        <section class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.035] p-4 text-xs leading-6 text-slate-400">
            <strong class="text-amber-200">QA fixtureについて:</strong>
            ここで作るPlanは通常のPlan/Task/Study Evidenceとして保存されるため、HomeやPlan一覧にも表示されます。
            ただしLabは専用fixture行で追跡しており、再生成・削除ではそのfixtureに紐づくPlanだけを対象にします。
            タイトル一致で本物のPlanを削除することはありません。
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            @foreach ($scenarios as $scenario)
                @php
                    $fixture = $scenario['fixture'] ?? null;
                    $plan = $scenario['plan'] ?? null;
                @endphp

                <article
                    class="page-card border-cyan-300/10 p-5 sm:p-6"
                    data-study-scenario="{{ $scenario['key'] }}"
                    data-study-scenario-active="{{ $fixture ? '1' : '0' }}"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap gap-2">
                                <span class="badge badge-slate">{{ $scenario['learning_type'] }}</span>
                                @if ($fixture)
                                    <span class="badge badge-green">生成済み</span>
                                    <span class="badge badge-slate">v{{ $fixture->scenario_version }}</span>
                                @else
                                    <span class="badge badge-slate">未生成</span>
                                @endif
                            </div>
                            <h2 class="mt-3 text-xl font-black text-slate-50">{{ $scenario['label'] }}</h2>
                        </div>
                        <span class="text-2xl" aria-hidden="true">🧪</span>
                    </div>

                    <div class="mt-4 space-y-3">
                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">PRELOADED STATE</p>
                            <p class="mt-1 text-sm leading-6 text-slate-300">{{ $scenario['state_summary'] }}</p>
                        </div>

                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">EXPECTED</p>
                            <p class="mt-1 text-sm leading-6 text-slate-300">{{ $scenario['expected'] }}</p>
                        </div>
                    </div>

                    @if ($plan)
                        <div class="mt-5 rounded-2xl border border-emerald-300/10 bg-emerald-300/[0.025] p-4">
                            <p class="text-xs font-black text-slate-200">{{ $plan->title }}</p>
                            <p class="mt-1 text-[10px] text-slate-500">Plan #{{ $plan->id }} · fixture #{{ $fixture->id }}</p>
                        </div>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-2">
                        @if ($plan)
                            <a
                                href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}"
                                class="btn-primary"
                            >Workspaceを開く</a>

                            <form method="POST" action="{{ route('admin.study_scenarios.store', ['scenarioKey' => $scenario['key']]) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">再生成</button>
                            </form>

                            <form method="POST" action="{{ route('admin.study_scenarios.destroy', $fixture) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary">削除</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.study_scenarios.store', ['scenarioKey' => $scenario['key']]) }}">
                                @csrf
                                <button type="submit" class="btn-primary">作成して開く</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        @if ($activeCount > 0)
            <section class="page-card border-rose-300/10 p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.14em] text-rose-300">CLEANUP</p>
                        <h2 class="mt-1 text-lg font-black text-slate-50">生成済みScenarioをまとめて削除</h2>
                        <p class="mt-2 text-xs leading-5 text-slate-500">
                            現在のAdminユーザーがLabで生成した {{ $activeCount }} 件だけを削除します。
                        </p>
                    </div>

                    <form method="POST" action="{{ route('admin.study_scenarios.destroy_all') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary">Labデータを全削除</button>
                    </form>
                </div>
            </section>
        @endif
    </div>
@endsection
