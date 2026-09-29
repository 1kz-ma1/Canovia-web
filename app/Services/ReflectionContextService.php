<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\WorkLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ReflectionContextService
{
    private const REFLECTION_EVIDENCE_TYPES = [
        'guided_execution_reflected',
        'interview_review_completed',
    ];

    public function __construct(
        private readonly PlanOwnershipService $ownership,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function resolve(Request $request): array
    {
        $plans = $this->ownership->ownedPlans($request, [
            'tasks' => fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('id'),
            'workLogs' => fn ($query) => $query
                ->with('task')
                ->latest('worked_on')
                ->latest('id'),
            'taskEvidences' => fn ($query) => $query
                ->with('task')
                ->latest('occurred_at')
                ->latest('id'),
        ]);

        $workLogs = $plans
            ->flatMap(fn (Plan $plan) => $plan->workLogs->map(
                fn (WorkLog $log) => $this->workLogItem($plan, $log)
            ))
            ->sortByDesc('sort_key')
            ->values();

        $evidences = $plans
            ->flatMap(fn (Plan $plan) => $plan->taskEvidences->map(
                fn (TaskEvidence $evidence) => $this->evidenceItem($plan, $evidence)
            ))
            ->sortByDesc('sort_key')
            ->values();

        $completedTasks = $plans
            ->flatMap(fn (Plan $plan) => $plan->tasks
                ->filter(fn (Task $task) => $task->status === 'done' || (int) $task->progress_percent >= 100)
                ->map(fn (Task $task) => $this->completedTaskItem($plan, $task)))
            ->sortByDesc('sort_key')
            ->values();

        $reflectionEvidence = $plans
            ->flatMap(fn (Plan $plan) => $plan->taskEvidences
                ->filter(fn (TaskEvidence $evidence) => in_array($evidence->type, self::REFLECTION_EVIDENCE_TYPES, true))
                ->map(fn (TaskEvidence $evidence) => $this->evidenceItem($plan, $evidence, reflection: true)))
            ->sortByDesc('sort_key')
            ->values();

        $recent = $workLogs
            ->concat($evidences)
            ->sortByDesc('sort_key')
            ->take(16)
            ->values();

        $contexts = collect([
            $this->context(
                key: 'recent',
                label: '最近の実績',
                eyebrow: 'RECENT',
                summary: 'WorkLogとEvidenceを時系列でまとめ、直近に何を積み上げたかを確認します。',
                count: $workLogs->count() + $evidences->count(),
                items: $recent,
            ),
            $this->context(
                key: 'evidence',
                label: 'Evidence',
                eyebrow: 'EVIDENCE',
                summary: 'Canoviaが確認できたTask Evidenceを、種類やPlanをまたいで振り返ります。',
                count: $evidences->count(),
                items: $evidences->take(24)->values(),
            ),
            $this->context(
                key: 'completed',
                label: '完了Task',
                eyebrow: 'COMPLETED',
                summary: '完了したTaskをPlan横断で見返し、積み上げた成果を確認します。',
                count: $completedTasks->count(),
                items: $completedTasks->take(24)->values(),
            ),
            $this->context(
                key: 'reflections',
                label: '振り返り記録',
                eyebrow: 'REFLECTIONS',
                summary: 'Guided Executionや面接後に明示的に残した振り返りEvidenceを確認します。',
                count: $reflectionEvidence->count(),
                items: $reflectionEvidence->take(24)->values(),
            ),
        ]);

        $requested = trim((string) $request->query('reflection_context', 'recent'));
        $selected = $contexts->firstWhere('key', $requested)
            ?? $contexts->first();

        return [
            'plans' => $plans,
            'contexts' => $contexts,
            'selected_context' => $selected,
            'timeline_url' => route('timeline.index'),
            'achievements_url' => route('achievements.index'),
        ];
    }

    /**
     * @param Collection<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function context(
        string $key,
        string $label,
        string $eyebrow,
        string $summary,
        int $count,
        Collection $items,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'eyebrow' => $eyebrow,
            'summary' => $summary,
            'count' => $count,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workLogItem(Plan $plan, WorkLog $log): array
    {
        $taskTitle = trim((string) ($log->task_title_snapshot ?: $log->task?->title));
        $date = $log->worked_on?->format('Y-m-d') ?? $log->created_at?->format('Y-m-d') ?? '日付不明';
        $summary = trim((string) ($log->outcome ?: $log->memo));

        if ($summary === '') {
            $minutes = max(0, (int) $log->actual_minutes);
            $delta = (int) $log->progress_delta_percent;
            $summary = $minutes > 0
                ? $minutes.'分取り組みました。'.($delta !== 0 ? ' 進捗 '.$this->signed($delta).'%.' : '')
                : '作業実績を記録しました。';
        }

        return [
            'id' => 'worklog:'.$log->id,
            'kind' => 'work_log',
            'label' => $taskTitle !== '' ? $taskTitle : (string) $plan->title,
            'subtitle' => $plan->title.' · '.$date,
            'summary' => $summary,
            'plan_id' => (int) $plan->id,
            'task_id' => $log->task_id ? (int) $log->task_id : null,
            'plan_title' => (string) $plan->title,
            'occurred_at' => $date,
            'sort_key' => $log->created_at?->timestamp
                ?? $log->worked_on?->startOfDay()->timestamp
                ?? 0,
            'url' => route('plans.show', $plan),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceItem(Plan $plan, TaskEvidence $evidence, bool $reflection = false): array
    {
        $taskTitle = trim((string) $evidence->task?->title);
        $date = $evidence->occurred_at?->format('Y-m-d H:i')
            ?? $evidence->created_at?->format('Y-m-d H:i')
            ?? '日付不明';

        return [
            'id' => ($reflection ? 'reflection:' : 'evidence:').$evidence->id,
            'kind' => $reflection ? 'reflection' : 'evidence',
            'label' => $taskTitle !== '' ? $taskTitle : $evidence->typeLabel(),
            'subtitle' => $plan->title.' · '.$evidence->typeLabel().' · '.$date,
            'summary' => $evidence->summary(),
            'plan_id' => (int) $plan->id,
            'task_id' => $evidence->task_id ? (int) $evidence->task_id : null,
            'plan_title' => (string) $plan->title,
            'occurred_at' => $date,
            'sort_key' => $evidence->occurred_at?->timestamp
                ?? $evidence->created_at?->timestamp
                ?? 0,
            'url' => route('plans.show', $plan),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function completedTaskItem(Plan $plan, Task $task): array
    {
        $date = $task->updated_at?->format('Y-m-d H:i') ?? '日付不明';
        $summary = trim((string) $task->description);

        return [
            'id' => 'task:'.$task->id,
            'kind' => 'completed_task',
            'label' => (string) $task->title,
            'subtitle' => $plan->title.' · 完了 · '.$date,
            'summary' => $summary !== '' ? $summary : 'このTaskは完了しています。',
            'plan_id' => (int) $plan->id,
            'task_id' => (int) $task->id,
            'plan_title' => (string) $plan->title,
            'occurred_at' => $date,
            'sort_key' => $task->updated_at?->timestamp ?? 0,
            'url' => route('plans.show', $plan),
        ];
    }

    private function signed(int $value): string
    {
        return $value > 0 ? '+'.$value : (string) $value;
    }
}
