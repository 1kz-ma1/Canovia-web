@extends('layouts.app')

@section('title', 'Release Gate | Canovia')

@section('content')
    @php
        $statusLabels = [
            'ready' => ['label' => 'READY', 'class' => 'badge-green'],
            'manual_review' => ['label' => 'MANUAL REVIEW', 'class' => 'badge-slate'],
            'failed' => ['label' => 'REVIEW FAILED', 'class' => 'badge-slate'],
            'blocked' => ['label' => 'BLOCKED', 'class' => 'badge-slate'],
        ];
        $decision = $statusLabels[$recommendedDecision] ?? $statusLabels['blocked'];
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
                <div class="grid grid-cols-2 gap-2 text-xs lg:grid-cols-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-slate-500">Public</p>
                        <p class="mt-1 font-black text-slate-100">L{{ $publicLevel->value }} {{ $publicLevel->label() }}</p>
                    </div>
                    <div class="rounded-xl border border-cyan-300/15 bg-cyan-300/[0.04] p-3">
                        <p class="text-cyan-300">Early Access target</p>
                        <p class="mt-1 font-black text-slate-100">L{{ $recommendedTarget->value }} {{ $recommendedTarget->label() }}</p>
                    </div>
                    <div class="col-span-2 rounded-xl border border-slate-800 bg-slate-950/35 p-3 lg:col-span-1">
                        <p class="text-slate-500">Release decision</p>
                        <p class="mt-1"><span class="badge {{ $decision['class'] }}">{{ $decision['label'] }}</span></p>
                    </div>
                </div>
            </div>
        </header>

        <section class="grid gap-4 xl:grid-cols-5">
            @foreach ($assessments as $assessment)
                @php
                    $level = $assessment['level'];
                    $status = $statusLabels[$assessment['decision'] ?? $assessment['status']] ?? $statusLabels['blocked'];
                    $isTarget = $level === $recommendedTarget;
                    $review = $assessment['review'] ?? null;
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
                            <p class="mt-1 font-black text-slate-100">
                                @if ($review)
                                    {{ $review['passed'] }}/{{ $review['total'] }}
                                @else
                                    {{ $assessment['manual_checks']->count() }}
                                @endif
                            </p>
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
                    @elseif (($assessment['decision'] ?? null) === 'ready')
                        <p class="mt-4 text-xs leading-5 text-emerald-200">
                            自動チェックと手動レビューが完了しています。
                        </p>
                    @elseif (($assessment['decision'] ?? null) === 'failed')
                        <p class="mt-4 text-xs leading-5 text-rose-200">
                            Failedの手動レビューがあります。
                        </p>
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
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-black text-slate-200">Manual Release Review</h3>
                            <p class="mt-1 text-[10px] leading-4 text-slate-500">
                                実機・運用・UXを確認して保存します。全項目PassedでもPublic Levelは自動変更しません。
                            </p>
                        </div>
                        <div class="grid grid-cols-3 gap-1 text-center text-[10px]">
                            <div class="rounded-lg border border-emerald-300/10 bg-emerald-300/[0.04] px-2 py-1.5">
                                <span class="block text-slate-500">Passed</span>
                                <strong class="text-emerald-200">{{ $recommendedReview['passed'] }}</strong>
                            </div>
                            <div class="rounded-lg border border-rose-300/10 bg-rose-300/[0.04] px-2 py-1.5">
                                <span class="block text-slate-500">Failed</span>
                                <strong class="text-rose-200">{{ $recommendedReview['failed'] }}</strong>
                            </div>
                            <div class="rounded-lg border border-slate-800 bg-slate-950/35 px-2 py-1.5">
                                <span class="block text-slate-500">Pending</span>
                                <strong class="text-slate-200">{{ $recommendedReview['pending'] }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        @foreach ($recommendedReview['items'] as $check)
                            @php
                                $checkStatus = $check['status'];
                                $statusClass = match ($checkStatus) {
                                    'passed' => 'border-emerald-300/15 bg-emerald-300/[0.035]',
                                    'failed' => 'border-rose-300/15 bg-rose-300/[0.035]',
                                    default => 'border-amber-300/10 bg-amber-300/[0.025]',
                                };
                                $statusLabel = match ($checkStatus) {
                                    'passed' => 'PASSED',
                                    'failed' => 'FAILED',
                                    default => 'PENDING',
                                };
                                $statusText = match ($checkStatus) {
                                    'passed' => 'text-emerald-200',
                                    'failed' => 'text-rose-200',
                                    default => 'text-amber-200',
                                };
                            @endphp

                            <article class="rounded-xl border p-3 {{ $statusClass }}" data-release-review-item="{{ $check['source_level']->value }}:{{ $check['check_key'] }}">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-black {{ $statusText }}">
                                            L{{ $check['source_level']->value }} {{ $check['source_level']->label() }} · {{ $statusLabel }}
                                        </p>
                                        <p class="mt-1 text-xs font-bold leading-5 text-slate-200">{{ $check['label'] }}</p>
                                        <p class="mt-1 font-mono text-[9px] text-slate-600">{{ $check['check_key'] }}</p>
                                    </div>

                                    @if ($check['reviewed_at'])
                                        <p class="text-right text-[9px] leading-4 text-slate-600">
                                            {{ $check['reviewed_by_name'] ?: 'Admin' }}<br>
                                            {{ $check['reviewed_at']->format('Y/m/d H:i') }}
                                        </p>
                                    @endif
                                </div>

                                <form method="POST" action="{{ route('admin.release_gate.review.update') }}" class="mt-3">
                                    @csrf
                                    <input type="hidden" name="release_level" value="{{ $check['source_level']->value }}">
                                    <input type="hidden" name="check_key" value="{{ $check['check_key'] }}">

                                    <label class="block">
                                        <span class="sr-only">Review note</span>
                                        <textarea
                                            name="note"
                                            rows="2"
                                            maxlength="2000"
                                            class="form-control text-xs"
                                            placeholder="確認環境・気づいた点・再確認条件など"
                                        >{{ $check['note'] }}</textarea>
                                    </label>

                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="submit" name="status" value="passed" class="btn-secondary px-3 py-2 text-xs">Passed</button>
                                        <button type="submit" name="status" value="failed" class="btn-secondary px-3 py-2 text-xs">Failed</button>
                                    </div>
                                </form>

                                @if ($checkStatus !== 'pending')
                                    <form method="POST" action="{{ route('admin.release_gate.review.reset') }}" class="mt-2">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="release_level" value="{{ $check['source_level']->value }}">
                                        <input type="hidden" name="check_key" value="{{ $check['check_key'] }}">
                                        <button type="submit" class="text-[10px] font-bold text-slate-500 hover:text-slate-300">Pendingへ戻す</button>
                                    </form>
                                @endif
                            </article>
                        @endforeach
                    </div>

                    @if ($recommendedDecision === 'ready')
                        <div class="mt-4 rounded-xl border border-emerald-300/20 bg-emerald-300/[0.05] p-4">
                            <p class="text-xs font-black text-emerald-200">READY FOR RELEASE</p>
                            <p class="mt-1 text-[10px] leading-5 text-slate-400">
                                自動チェックと手動レビューが完了しています。Public Release Levelの変更は別操作として明示的に行います。
                            </p>
                        </div>
                    @elseif ($recommendedDecision === 'failed')
                        <div class="mt-4 rounded-xl border border-rose-300/20 bg-rose-300/[0.05] p-4">
                            <p class="text-xs font-black text-rose-200">REVIEW FAILED</p>
                            <p class="mt-1 text-[10px] leading-5 text-slate-400">
                                Failed項目があります。修正・再確認後にPassedへ更新してください。
                            </p>
                        </div>
                    @endif
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
            Release Reviewの結果は保存されますが、この画面からPublic Levelを変更したり自動昇格させたりはしません。
            <code class="text-slate-300">READY FOR RELEASE</code> は「公開操作を検討できる状態」を意味します。
        </section>
    </div>
@endsection
