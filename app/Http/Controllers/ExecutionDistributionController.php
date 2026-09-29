<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\Task;
use App\Services\ExecutionDistributionService;
use App\Services\ExecutionPacketService;
use App\Services\FeatureAccessService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExecutionDistributionController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        ExecutionDistributionService $distribution,
        ExecutionPacketService $packets,
        FeatureAccessService $access,
    ) {
        $ownership->authorizeEdit($request, $plan);

        $tasks = $plan->tasks()
            ->with('prerequisites')
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $suggestedTaskIds = collect(explode(',', (string) $request->query('suggested_task_ids', '')))
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->intersect($tasks->pluck('id')->map(fn ($id) => (int) $id))
            ->take(ExecutionDistributionService::MAX_TARGETS)
            ->values();

        $bundle = $request->session()->get(ExecutionDistributionService::sessionKey($plan));
        if (is_array($bundle)) {
            $bundle = $distribution->refreshStaleness($request, $plan, $bundle);
        } else {
            $bundle = null;
        }

        return view('execution_orchestration.distribute', [
            'plan' => $plan,
            'tasks' => $tasks,
            'bundle' => $bundle,
            'actorTypes' => ExecutionPacketService::ACTOR_TYPES,
            'nativeAiConfigured' => $packets->nativeConfigured(),
            'nativeAiEntitled' => $access->canUse($request->user(), FeatureKey::AutomaticAiExecution),
            'maxTargets' => ExecutionDistributionService::MAX_TARGETS,
            'suggestedTaskIds' => $suggestedTaskIds->all(),
        ]);
    }

    public function prepare(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        ExecutionDistributionService $distribution,
    ) {
        $ownership->authorizeEdit($request, $plan);

        $validated = $request->validate([
            'generation_mode' => ['required', Rule::in(['native', 'external'])],
            'selected_task_ids' => [
                'required',
                'array',
                'min:1',
                'max:'.ExecutionDistributionService::MAX_TARGETS,
            ],
            'selected_task_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'actor_label' => ['nullable', 'array'],
            'actor_label.*' => ['nullable', 'string', 'max:80'],
            'actor_type' => ['nullable', 'array'],
            'actor_type.*' => ['nullable', Rule::in(array_keys(ExecutionPacketService::ACTOR_TYPES))],
            'available_minutes' => ['nullable', 'array'],
            'available_minutes.*' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $selectedIds = collect($validated['selected_task_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $tasks = $plan->tasks()
            ->whereIn('id', $selectedIds->all())
            ->whereNotIn('status', ['done', 'cancelled'])
            ->get()
            ->sortBy(fn (Task $task) => $selectedIds->search((int) $task->id))
            ->values();

        if ($tasks->count() !== $selectedIds->count()) {
            throw ValidationException::withMessages([
                'selected_task_ids' => '現在このPlanで実行対象にできるTaskだけを選んでください。',
            ]);
        }

        foreach ($tasks as $task) {
            $ownership->authorizeTask($request, $task);
        }

        $labels = is_array($validated['actor_label'] ?? null) ? $validated['actor_label'] : [];
        $types = is_array($validated['actor_type'] ?? null) ? $validated['actor_type'] : [];
        $minutes = is_array($validated['available_minutes'] ?? null) ? $validated['available_minutes'] : [];

        $targetConfig = [];
        foreach ($selectedIds as $taskId) {
            $targetConfig[$taskId] = [
                'actor_label' => $labels[$taskId] ?? null,
                'actor_type' => $types[$taskId] ?? 'human_ai',
                'available_minutes' => $minutes[$taskId] ?? null,
            ];
        }

        $bundle = $distribution->prepare(
            $request,
            $plan,
            $tasks,
            $targetConfig,
            (string) $validated['generation_mode'],
        );

        $request->session()->put(ExecutionDistributionService::sessionKey($plan), $bundle);

        $fallbacks = collect($bundle['targets'] ?? [])
            ->filter(fn ($target) => is_array($target) && filled($target['native_error'] ?? null))
            ->count();

        return redirect()
            ->route('plans.execution_distribution.show', $plan)
            ->with(
                $fallbacks > 0 ? 'status' : 'success',
                $fallbacks > 0
                    ? $fallbacks.'件はNative生成できなかったため、外部AI用Promptへ切り替えました。'
                    : '選択した担当ごとに、同じPlan Contextから実行指示を準備しました。',
            );
    }

    public function reset(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $request->session()->forget(ExecutionDistributionService::sessionKey($plan));

        return redirect()
            ->route('plans.execution_distribution.show', $plan)
            ->with('status', '分配Bundleをリセットしました。Task / Planは変更していません。');
    }
}
