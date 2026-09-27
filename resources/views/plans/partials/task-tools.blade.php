@php
    $tools = collect($tools ?? []);
    $compactTools = (bool) ($compactTools ?? false);
    $hasSpecializedPrimary = $tools->contains(
        fn ($tool) => ($tool['id'] ?? null) !== 'timer' && (bool) ($tool['recommended'] ?? false)
    );
    $tools = $hasSpecializedPrimary
        ? $tools->reject(fn ($tool) => ($tool['id'] ?? null) === 'timer')->values()
        : $tools;
@endphp

@if ($tools->isNotEmpty())
    <div class="{{ $compactTools ? 'mt-3' : 'mt-4' }}">
        @if ($compactTools)
            <p class="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">Canovia Tools</p>
        @endif
        <div class="{{ $compactTools ? 'flex flex-wrap gap-2' : 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4' }}">
            @foreach ($tools as $tool)
                @if ($tool['id'] === 'timer')
                    <form method="POST" action="{{ route('work_sessions.start') }}" class="{{ $compactTools ? '' : 'contents' }}" data-work-start-form>
                        @csrf
                        <input type="hidden" name="task_id" value="{{ $toolTask->id }}">
                        <input type="hidden" name="source" value="plan">
                        <button type="submit" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'group rounded-2xl border border-white/10 bg-white/[0.035] p-4 text-left transition hover:border-cyan-300/30 hover:bg-cyan-300/[0.05]' }}">
                            @if ($compactTools)
                                <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }}
                            @else
                                <span class="flex items-center justify-between gap-2"><strong class="text-sm text-slate-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge badge-slate">{{ $tool['badge'] }}</span></span>
                                <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $tool['description'] }}</span>
                            @endif
                        </button>
                    </form>
                @elseif ($tool['id'] === 'study_activity')
                    <a href="{{ route('plans.tasks.study_activity.show', [$toolPlan, $toolTask]) }}" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'group rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.045] p-4 transition hover:border-emerald-200/40' }}">
                        @if ($compactTools)
                            <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }} <span class="text-emerald-300">おすすめ</span>
                        @else
                            <span class="flex items-center justify-between gap-2"><strong class="text-sm text-emerald-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge badge-green">おすすめ</span></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-300">{{ $tool['description'] }}</span>
                        @endif
                    </a>
                @elseif ($tool['id'] === 'ai_practice')
                    <a href="{{ route('plans.tasks.study_practice.show', [$toolPlan, $toolTask]) }}" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'group rounded-2xl border border-cyan-300/20 bg-cyan-300/[0.055] p-4 transition hover:border-cyan-200/40 hover:bg-cyan-300/[0.09]' }}">
                        @if ($compactTools)
                            <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }} @if($tool['recommended'])<span class="text-cyan-300">おすすめ</span>@endif
                        @else
                            <span class="flex items-center justify-between gap-2"><strong class="text-sm text-cyan-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge badge-green">{{ $tool['recommended'] ? 'おすすめ' : $tool['badge'] }}</span></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-300">{{ $tool['description'] }}</span>
                        @endif
                    </a>
                @elseif ($tool['id'] === 'guided_execution')
                    <a href="{{ route('plans.tasks.guided_execution.show', [$toolPlan, $toolTask]) }}" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'rounded-2xl border border-amber-300/15 bg-amber-300/[0.035] p-4 transition hover:border-amber-300/30' }}">
                        @if ($compactTools)
                            <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }} @if($tool['recommended'])<span class="text-amber-300">おすすめ</span>@endif
                        @else
                            <span class="flex items-center justify-between gap-2"><strong class="text-sm text-slate-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge {{ $tool['recommended'] ? 'badge-green' : 'badge-slate' }}">{{ $tool['badge'] }}</span></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $tool['description'] }}</span>
                        @endif
                    </a>
                @elseif ($tool['id'] === 'career_workspace')
                    <a href="{{ route('plans.career.index', $toolPlan) }}" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'rounded-2xl border border-fuchsia-300/15 bg-fuchsia-300/[0.04] p-4 transition hover:border-fuchsia-300/30' }}">
                        @if ($compactTools)
                            <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }} @if($tool['recommended'])<span class="text-fuchsia-300">おすすめ</span>@endif
                        @else
                            <span class="flex items-center justify-between gap-2"><strong class="text-sm text-slate-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge badge-slate">{{ $tool['badge'] }}</span></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $tool['description'] }}</span>
                        @endif
                    </a>
                @elseif ($tool['id'] === 'resources')
                    <a href="{{ route('plans.resources.index', $toolPlan) }}" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'rounded-2xl border border-white/10 bg-white/[0.035] p-4 transition hover:border-cyan-300/30' }}">
                        @if ($compactTools)
                            <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }}
                        @else
                            <span class="flex items-center justify-between gap-2"><strong class="text-sm text-slate-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge badge-slate">{{ $tool['badge'] }}</span></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $tool['description'] }}</span>
                        @endif
                    </a>
                @elseif ($tool['id'] === 'artifacts')
                    <a href="{{ route('plans.artifacts.index', $toolPlan) }}" class="{{ $compactTools ? 'btn-secondary px-3 py-2 text-xs' : 'rounded-2xl border border-violet-300/15 bg-violet-300/[0.04] p-4 transition hover:border-violet-300/30' }}">
                        @if ($compactTools)
                            <span aria-hidden="true">{{ $tool['icon'] }}</span> {{ $tool['name'] }}
                        @else
                            <span class="flex items-center justify-between gap-2"><strong class="text-sm text-slate-100">{{ $tool['icon'] }} {{ $tool['name'] }}</strong><span class="badge badge-slate">{{ $tool['badge'] }}</span></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $tool['description'] }}</span>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    </div>
@endif
