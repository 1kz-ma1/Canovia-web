@php
    $artifact = $item['artifact'];
    $state = $item['workflow_state'];
    $stateLabel = $item['workflow_state_label'];
@endphp

<article
    id="github-item-{{ $item['id'] }}"
    class="rounded-2xl border border-slate-700/70 bg-slate-950/55 p-4 shadow-lg shadow-slate-950/20"
    data-github-workflow-item
    data-github-workflow-state="{{ $state }}"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-violet-300/20 bg-violet-300/[0.07] px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-violet-200">
                    {{ $item['kind_label'] }}
                </span>
                @if ($item['repo_full_name'])
                    <span class="truncate text-[11px] font-semibold text-slate-500">{{ $item['repo_full_name'] }}</span>
                @endif
            </div>
            <h3 class="mt-2 break-words text-sm font-black leading-5 text-slate-100">{{ $item['title'] }}</h3>
            <p class="mt-1 text-xs font-semibold text-cyan-200">{{ $item['reference'] }}</p>
        </div>
        <span class="shrink-0 text-lg" aria-hidden="true">{{ $item['plan_icon'] }}</span>
    </div>

    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-400">
        <span class="rounded-full border border-slate-800 bg-slate-900/75 px-2 py-1">{{ $item['plan_title'] }}</span>
        @if ($item['assigned_user_name'])
            <span class="rounded-full border border-slate-800 bg-slate-900/75 px-2 py-1">担当 {{ $item['assigned_user_name'] }}</span>
        @endif
    </div>

    @if ($item['tasks']->isNotEmpty())
        <div class="mt-3 space-y-1">
            @foreach ($item['tasks']->take(2) as $task)
                <p class="truncate text-[11px] text-slate-400">Task · {{ $task['title'] }}</p>
            @endforeach
            @if ($item['tasks']->count() > 2)
                <p class="text-[10px] text-slate-600">+{{ $item['tasks']->count() - 2 }} Task</p>
            @endif
        </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <a
            href="{{ $item['url'] }}"
            target="_blank"
            rel="noopener noreferrer"
            class="btn-secondary px-3 py-2 text-xs"
        >GitHubで開く ↗</a>
        <a href="{{ $item['details_url'] }}" class="btn-secondary px-3 py-2 text-xs">詳細</a>
    </div>

    @if ($item['can_edit'])
        <form
            method="POST"
            action="{{ route('github_workflow.state.update', $artifact) }}"
            class="mt-3 flex items-end gap-2"
            data-github-workflow-state-form
        >
            @csrf
            @method('PATCH')
            <input type="hidden" name="return_plan_id" value="{{ $selectedPlan?->id ?? '' }}">
            <label class="min-w-0 flex-1">
                <span class="sr-only">Canovia上の状態</span>
                <select name="workflow_state" class="form-control w-full text-xs">
                    <option value="" @selected($state === 'unclassified')>未整理</option>
                    @foreach ($workflowStates as $key => $label)
                        <option value="{{ $key }}" @selected($state === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="btn-primary shrink-0 px-3 py-2 text-xs">移動</button>
        </form>
    @else
        <p class="mt-3 text-[11px] text-slate-500">閲覧のみ · 状態変更はEditor以上</p>
    @endif
</article>
