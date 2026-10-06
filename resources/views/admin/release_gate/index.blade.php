@extends('layouts.app')

@section('title', 'Release Gate | Canovia')

@section('content')
    @php
        $statusLabels = [
            'ready' => ['label' => 'READY', 'class' => 'badge-green'],
            'manual_review' => ['label' => 'MANUAL REVIEW', 'class' => 'badge-slate'],
            'blocked' => ['label' => 'BLOCKED', 'class' => 'badge-slate'],
        ];
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 sm:p-7">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">EARLY ACCESS RELEASE</p>
            <div class="mt-2 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h1 class="text-2xl font-black text-slate-50 sm:text-3xl">Release Gate</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                        Release Levelごとの構造契約を自動確認します。自動検証OKは公開承認ではなく、
                        手動確認へ進めるCandidateであることだけを意味します。
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-slate-500">Public</p>
                        <p class="mt-1 font-black text-slate-100">L{{ $publicLevel->value }} {{ $publicLevel->label() }}</p>
                    </div>
                    <div class="rounded-xl border border-cyan-300/15 bg-cyan-300/[0.04] p-3">
                        <p class="text-cyan-300">Early Access target</p>
                        <p class="mt-1 font-black text-slate-100">L{{ $recommendedTarget->value }} {{ $recommendedTarget->label() }}</p>
                    </div>
                </div>
            </div>
        </header>

        <section class="grid gap-4 xl:grid-cols-5">
            @foreach ($assessments as $assessment)
                @php
                    $level = $assessment['level'];
                    $status = $statusLabels[$assessment['status']] ?? $statusLabels['blocked'];
                    $isTarget = $level === $recommendedTarget;
                @endphp
                <article class="page-card p-5 {{ $isTarget ? 'border-cyan-300/30' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[.16em] {{ $isTarget ? 'text-cyan-300' : 'text-slate-500' }}">
                                LEVEL {{ $level->value }}
                            </p>
                            <h2 class="mt-1 text-base font-black text-slate-100">{{ $level->label() }}</h2>
                        </div>
                        <span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-slate-500">Auto checks</p>
                            <p class="mt-1 font-black text-slate-100">
                                {{ $assessment['automatic_check_count'] - $assessment['failed_check_count'] }}/{{ $assessment['automatic_check_count'] }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-slate-500">Manual</p>
                            <p class="mt-1 font-black text-slate-100">{{ $assessment['manual_checks']->count() }}</p>
                        </div>
                    </div>

                    @if ($assessment['failed_checks']->isNotEmpty())
                        <div class="mt-4 space-y-2">
                            @foreach ($assessment['failed_checks'] as $check)
                                <div class="rounded-xl border border-rose-400/15 bg-rose-400/[0.04] p-3">
                                    <p class="text-[10px] font-black text-rose-200">BLOCKER</p>
                                    <p class="mt-1 break-words text-xs font-bold text-slate-200">{{ $check['label'] }}</p>
                                    <p class="mt-1 text-[10px] leading-4 text-slate-500">{{ $check['detail'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($assessment['manual_checks']->isNotEmpty())
                        <p class="mt-4 text-xs leading-5 text-amber-100">
                            構造チェックは通過。公開前に手動確認が必要です。
                        </p>
                    @endif
                </article>
            @endforeach
        </section>

        <section class="page-card overflow-hidden">
            <div class="border-b border-slate-800 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">TARGET DETAIL</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">
                    L{{ $recommendedTarget->value }} {{ $recommendedTarget->label() }}
                </h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    Early Accessの初期候補。自動チェックと人間が確認すべき項目を分離します。
                </p>
            </div>

            <div class="grid gap-0 lg:grid-cols-2">
                <div class="p-5 sm:p-6">
                    <h3 class="text-sm font-black text-slate-200">Automated checks</h3>
                    <div class="mt-3 space-y-2">
                        @foreach ($recommendedAssessment['checks'] as $check)
                            <div class="flex items-start gap-3 rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                                <span class="{{ $check['passed'] ? 'text-emerald-300' : 'text-rose-300' }} font-black">
                                    {{ $check['passed'] ? '✓' : '×' }}
                                </span>
                                <div class="min-w-0">
                                    <p class="break-words text-xs font-bold text-slate-200">{{ $check['label'] }}</p>
                                    <p class="mt-1 text-[10px] leading-4 text-slate-500">{{ $check['detail'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="border-t border-slate-800 p-5 sm:p-6 lg:border-l lg:border-t-0">
                    <h3 class="text-sm font-black text-slate-200">Manual release checks</h3>
                    <p class="mt-1 text-[10px] leading-4 text-slate-500">
                        ここは自動承認しません。実機・運用・UXを確認したうえでProduct Ownerが判断します。
                    </p>
                    <div class="mt-3 space-y-2">
                        @foreach ($recommendedAssessment['manual_checks'] as $check)
                            <div class="rounded-xl border border-amber-300/10 bg-amber-300/[0.03] p-3">
                                <p class="text-[10px] font-black text-amber-200">
                                    L{{ $check['source_level'] }} {{ $check['level_label'] }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-slate-300">{{ $check['label'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section class="page-card overflow-hidden">
            <div class="border-b border-slate-800 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">FEATURE INVENTORY</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">Feature maturity map</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    Release Levelは公開成熟度、Free/Premiumは利用権です。Level 2はComing Soonの表示段階なので、有料Capabilityを解放しません。
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead class="bg-slate-950/55 text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Feature</th>
                            <th class="px-4 py-3">Minimum Level</th>
                            <th class="px-4 py-3">Entitlement</th>
                            <th class="px-4 py-3">Feature Flag</th>
                            <th class="px-4 py-3">Required by Gate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach ($featureInventory as $item)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-bold text-slate-200">{{ $item['label'] }}</p>
                                    <p class="mt-1 font-mono text-[10px] text-slate-600">{{ $item['feature']->value }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-300">
                                    L{{ $item['minimum_level']->value }} {{ $item['minimum_level']->label() }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="badge {{ $item['free'] ? 'badge-green' : 'badge-slate' }}">
                                        {{ $item['free'] ? 'Free' : 'Restricted' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    {{ $item['has_feature_flag'] ? 'Yes' : 'No dedicated flag' }}
                                </td>
                                <td class="px-4 py-3 text-slate-400">
                                    @if ($item['required_at']->isEmpty())
                                        Optional
                                    @else
                                        {{ $item['required_at']->map(fn ($level) => 'L'.$level)->join(', ') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4 text-xs leading-6 text-slate-500">
            この画面はread-onlyです。Release GateからPublic Levelを変更したり、自動昇格させたりはしません。
            現時点でL2は <code class="text-slate-300">product.preview.index</code> が未実装のため、構造上Blockedになります。
        </section>
    </div>
@endsection
