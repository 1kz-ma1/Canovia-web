<?php

namespace App\Services;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MapProjectionService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly UserBehaviorService $behavior,
        private readonly UserStateService $states,
        private readonly DashboardGuidanceService $guidance,
    ) {}

    /**
     * Read-only projection of existing Canovia state.
     *
     * The Map never persists node positions or creates a second source of truth.
     * Primary Action selection is delegated to the same objective guidance used by Home.
     *
     * @return array{
     *     nodes: Collection<int,array<string,mixed>>,
     *     edges: Collection<int,array<string,mixed>>,
     *     primary_node_id:?string,
     *     has_primary_action:bool
     * }
     */
    public function build(Request $request): array
    {
        $actorToken = $this->core->actorToken($request);
        $plans = $this->core->plans($request, ['tasks', 'plan_resources']);

        if ($plans->isNotEmpty()) {
            (new EloquentCollection($plans->all()))->loadMissing('goalContext');
        }

        $editablePlans = $plans
            ->filter(fn (Plan $plan) => $this->core->canEdit($request, $plan))
            ->values();

        $guidanceDeck = collect();

        if ($editablePlans->isNotEmpty()) {
            $baseline = $this->behavior->baseline($actorToken);
            $state = $this->states->calculate($actorToken, $baseline, $editablePlans);

            $guidanceDeck = $this->guidance->build(
                $plans,
                $state,
                $actorToken,
                $editablePlans->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $request->user(),
            );
        }

        $primaryGuidance = $guidanceDeck->first();
        $plan = data_get($primaryGuidance, 'plan')
            ?? $editablePlans->first()
            ?? $plans->first();
        $currentTask = data_get($primaryGuidance, 'task');
        $primaryTool = data_get($primaryGuidance, 'recommended_tool');

        if ($currentTask instanceof Task) {
            (new EloquentCollection([$currentTask]))->loadMissing([
                'evidences' => fn ($query) => $query->limit(1),
            ]);
        }

        $nextTask = $plan instanceof Plan && $currentTask instanceof Task
            ? $this->nextTask($plan, $currentTask)
            : null;

        [$pendingInboxCount, $latestInbox] = $this->pendingInbox($request, $actorToken);

        $nodes = collect();
        $edges = collect();

        if ($plan instanceof Plan) {
            $goal = $plan->goalContext;
            if ($goal && filled($goal->desired_state)) {
                $nodes->push($this->node(
                    id: 'goal:'.$goal->id,
                    type: 'goal',
                    entityId: (int) $goal->id,
                    eyebrow: 'GOAL',
                    label: Str::limit((string) $goal->desired_state, 72),
                    subtitle: 'このPlanが目指している状態',
                    importance: 0.62,
                    state: 'future',
                    positionRole: 'future-goal',
                    sizeWeight: 0.72,
                    x: 50,
                    y: 9,
                    action: route('goal_discovery.show', $goal),
                    classicSurface: $this->surface(
                        'Goal Context',
                        (string) $goal->desired_state,
                        'このPlanが目指している状態と、そこへ向かう前提をClassic画面で確認します。',
                        [
                            $this->action('目標Contextを開く', route('goal_discovery.show', $goal), true),
                            $this->action('Planを開く', route('plans.show', $plan)),
                        ],
                    ),
                ));
            }

            $nodes->push($this->node(
                id: 'plan:'.$plan->id,
                type: 'plan',
                entityId: (int) $plan->id,
                eyebrow: 'PLAN',
                label: (string) $plan->title,
                subtitle: filled($plan->deadline)
                    ? '期限 '.$plan->deadline->format('Y/m/d')
                    : ((string) ($plan->category ?: 'Plan')),
                importance: 0.74,
                state: 'future',
                positionRole: 'future-plan',
                sizeWeight: 0.84,
                x: 32,
                y: 25,
                action: route('plans.show', $plan),
                classicSurface: $this->surface(
                    'Plan',
                    (string) $plan->title,
                    filled($plan->description)
                        ? Str::limit((string) $plan->description, 180)
                        : 'このPlanの詳細・Task・ToolsをClassic画面で確認します。',
                    [
                        $this->action('Planを開く', route('plans.show', $plan), true),
                        $this->action('Roadmapで見る', route('roadmap.index', ['plan_id' => $plan->id])),
                    ],
                    array_values(array_filter([
                        $plan->category ?: null,
                        filled($plan->deadline) ? '期限 '.$plan->deadline->format('Y/m/d') : null,
                    ])),
                ),
            ));

            if ($goal && filled($goal->desired_state)) {
                $edges->push($this->edge('goal:'.$goal->id, 'plan:'.$plan->id, 'contains', 0.68));
            }
        }

        $primaryNodeId = null;

        if ($plan instanceof Plan && $currentTask instanceof Task) {
            $primaryNodeId = 'task:'.$currentTask->id;
            $toolId = is_array($primaryTool) ? (string) ($primaryTool['id'] ?? '') : '';
            $primaryToolUrl = $toolId !== ''
                ? $this->toolUrl($toolId, $plan, $currentTask)
                : null;
            $taskActions = [];

            if ($primaryToolUrl) {
                $taskActions[] = $this->action(
                    (string) ($primaryTool['name'] ?? '実行方法').'で進める',
                    $primaryToolUrl,
                    true,
                );
            } else {
                $taskActions[] = $this->action('Planを開いて進める', route('plans.show', $plan), true);
            }

            $taskActions[] = $this->action('Taskを編集', route('tasks.edit', $currentTask));
            $taskActions[] = $this->action('Plan全体を見る', route('plans.show', $plan));

            $nodes->push($this->node(
                id: $primaryNodeId,
                type: 'task',
                entityId: (int) $currentTask->id,
                eyebrow: 'NOW · PRIMARY ACTION',
                label: (string) $currentTask->title,
                subtitle: filled($currentTask->next_action_note)
                    ? Str::limit((string) $currentTask->next_action_note, 90)
                    : Str::limit((string) ($currentTask->description ?: 'このTaskを進める'), 90),
                importance: 1.0,
                state: 'primary',
                positionRole: 'now',
                sizeWeight: 1.0,
                x: 50,
                y: 50,
                action: route('plans.show', $plan),
                classicSurface: $this->surface(
                    'Current Task',
                    (string) $currentTask->title,
                    filled($currentTask->next_action_note)
                        ? (string) $currentTask->next_action_note
                        : ((string) ($currentTask->description ?: 'このTaskを進めます。')),
                    $taskActions,
                    [
                        '進捗 '.max(0, min(100, (int) $currentTask->progress_percent)).'%',
                        $currentTask->status === 'doing' ? '進行中' : '未着手',
                        (string) $plan->title,
                    ],
                ),
            ));
            $edges->push($this->edge('plan:'.$plan->id, $primaryNodeId, 'current_action', 1.0));

            if ($nextTask instanceof Task) {
                $nodes->push($this->node(
                    id: 'task:'.$nextTask->id,
                    type: 'task',
                    entityId: (int) $nextTask->id,
                    eyebrow: 'NEXT TASK',
                    label: (string) $nextTask->title,
                    subtitle: '現在Actionの次に見えている候補',
                    importance: 0.68,
                    state: 'future',
                    positionRole: 'future-next',
                    sizeWeight: 0.76,
                    x: 68,
                    y: 25,
                    action: route('plans.show', $plan),
                    classicSurface: $this->surface(
                        'Next Task',
                        (string) $nextTask->title,
                        filled($nextTask->description)
                            ? Str::limit((string) $nextTask->description, 180)
                            : '現在Actionの次に取り組む候補です。',
                        [
                            $this->action('Planで確認', route('plans.show', $plan), true),
                            $this->action('Taskを編集', route('tasks.edit', $nextTask)),
                        ],
                        [
                            '進捗 '.max(0, min(100, (int) $nextTask->progress_percent)).'%',
                            (string) $plan->title,
                        ],
                    ),
                ));
                $edges->push($this->edge($primaryNodeId, 'task:'.$nextTask->id, 'next', 0.72));
            }

            if ($toolId !== '') {
                $nodes->push($this->node(
                    id: 'tool:'.$toolId,
                    type: 'tool',
                    entityId: null,
                    eyebrow: 'ACTION / TOOL',
                    label: (string) ($primaryTool['name'] ?? 'Tool'),
                    subtitle: Str::limit((string) ($primaryTool['description'] ?? 'このTaskを進めるための手段'), 92),
                    importance: 0.78,
                    state: 'action',
                    positionRole: 'action-tool',
                    sizeWeight: 0.82,
                    x: 82,
                    y: 51,
                    action: $primaryToolUrl,
                    classicSurface: $this->surface(
                        'Execution Tool',
                        (string) ($primaryTool['name'] ?? 'Tool'),
                        (string) ($primaryTool['description'] ?? 'このTaskを進めるための実行手段です。'),
                        [
                            $this->action('このToolを開く', $primaryToolUrl, true),
                            $this->action('TaskのPlanへ戻る', route('plans.show', $plan)),
                        ],
                        array_values(array_filter([
                            filled($primaryTool['badge'] ?? null) ? (string) $primaryTool['badge'] : null,
                            (string) $currentTask->title,
                        ])),
                    ),
                ));
                $edges->push($this->edge($primaryNodeId, 'tool:'.$toolId, 'executed_with', 0.86));
            }

            $evidence = $currentTask->relationLoaded('evidences')
                ? $currentTask->evidences->first()
                : null;

            if ($evidence) {
                $nodes->push($this->node(
                    id: 'evidence:'.$evidence->id,
                    type: 'evidence',
                    entityId: (int) $evidence->id,
                    eyebrow: 'PAST / EVIDENCE',
                    label: $evidence->typeLabel(),
                    subtitle: Str::limit($evidence->summary(), 96),
                    importance: 0.56,
                    state: 'past',
                    positionRole: 'past-evidence',
                    sizeWeight: 0.7,
                    x: 50,
                    y: 79,
                    action: route('timeline.index'),
                    classicSurface: $this->surface(
                        'Evidence',
                        $evidence->typeLabel(),
                        $evidence->summary(),
                        [
                            $this->action('Timelineで確認', route('timeline.index'), true),
                            $this->action('TaskのPlanを開く', route('plans.show', $plan)),
                        ],
                        array_values(array_filter([
                            $evidence->sourceLabel(),
                            $evidence->occurred_at?->format('Y/m/d H:i'),
                        ])),
                    ),
                ));
                $edges->push($this->edge($primaryNodeId, 'evidence:'.$evidence->id, 'produced_evidence', 0.62));
            }
        }

        if ($latestInbox) {
            $nodes->push($this->node(
                id: 'inbox:pending',
                type: 'inbox',
                entityId: (int) $latestInbox->id,
                eyebrow: 'INPUT / INBOX',
                label: $pendingInboxCount > 1
                    ? '未整理の入力 '.$pendingInboxCount.'件'
                    : $latestInbox->displayTitle(),
                subtitle: $pendingInboxCount > 1
                    ? Str::limit($latestInbox->displayTitle().' ほか', 78)
                    : '整理先を決める前の入力',
                importance: 0.5,
                state: 'input',
                positionRole: 'input-inbox',
                sizeWeight: 0.68,
                x: 18,
                y: 52,
                action: route('inbox.index'),
                classicSurface: $this->surface(
                    'Inbox',
                    $pendingInboxCount > 1 ? '未整理の入力 '.$pendingInboxCount.'件' : $latestInbox->displayTitle(),
                    $pendingInboxCount > 1
                        ? '最新: '.$latestInbox->displayTitle()
                        : 'まだ整理先が決まっていない入力です。',
                    [
                        $this->action('Inboxで整理', route('inbox.index'), true),
                    ],
                    [$latestInbox->sourceLabel()],
                ),
            ));

            if ($plan instanceof Plan && $latestInbox->plan_id && (int) $latestInbox->plan_id === (int) $plan->id) {
                $edges->push($this->edge('inbox:pending', 'plan:'.$plan->id, 'input_for', 0.45, secondary: true));
            }
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
            'primary_node_id' => $primaryNodeId,
            'has_primary_action' => $primaryNodeId !== null,
        ];
    }

    private function nextTask(Plan $plan, Task $currentTask): ?Task
    {
        $currentOrder = (int) ($currentTask->sort_order ?? PHP_INT_MAX);

        return $plan->tasks
            ->filter(fn (Task $task) => (int) $task->id !== (int) $currentTask->id
                && ! in_array($task->status, ['done', 'cancelled'], true)
                && (int) $task->progress_percent < 100)
            ->sort(function (Task $left, Task $right) use ($currentTask, $currentOrder) {
                $dependency = ((int) $left->depends_on_task_id === (int) $currentTask->id ? 0 : 1)
                    <=> ((int) $right->depends_on_task_id === (int) $currentTask->id ? 0 : 1);
                if ($dependency !== 0) {
                    return $dependency;
                }

                $afterCurrent = ((int) ($left->sort_order ?? PHP_INT_MAX) > $currentOrder ? 0 : 1)
                    <=> ((int) ($right->sort_order ?? PHP_INT_MAX) > $currentOrder ? 0 : 1);
                if ($afterCurrent !== 0) {
                    return $afterCurrent;
                }

                $priority = max(1, min(5, (int) $left->priority))
                    <=> max(1, min(5, (int) $right->priority));
                if ($priority !== 0) {
                    return $priority;
                }

                $sortOrder = (int) ($left->sort_order ?? PHP_INT_MAX)
                    <=> (int) ($right->sort_order ?? PHP_INT_MAX);

                return $sortOrder !== 0
                    ? $sortOrder
                    : (int) $left->id <=> (int) $right->id;
            })
            ->first();
    }

    /**
     * @return array{0:int,1:?InboxItem}
     */
    private function pendingInbox(Request $request, string $actorToken): array
    {
        $userId = $request->user()?->id;

        $query = InboxItem::query()
            ->where(function (Builder $query) use ($userId, $actorToken) {
                if ($userId) {
                    $query->where('user_id', $userId)
                        ->orWhere('actor_token', $actorToken);
                    return;
                }

                $query->whereNull('user_id')->where('actor_token', $actorToken);
            })
            ->whereIn('status', ['new', 'review']);

        return [
            (clone $query)->count(),
            $query->latest('id')->first(),
        ];
    }

    /**
     * @param array<string,mixed> $classicSurface
     * @return array<string,mixed>
     */
    private function node(
        string $id,
        string $type,
        ?int $entityId,
        string $eyebrow,
        string $label,
        string $subtitle,
        float $importance,
        string $state,
        string $positionRole,
        float $sizeWeight,
        float $x,
        float $y,
        ?string $action,
        array $classicSurface = [],
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'entity_id' => $entityId,
            'eyebrow' => $eyebrow,
            'label' => $label,
            'subtitle' => $subtitle,
            'importance' => $importance,
            'state' => $state,
            'position_role' => $positionRole,
            'size_weight' => $sizeWeight,
            'position' => ['x' => $x, 'y' => $y],
            'available_action' => $action,
            'classic_surface' => $classicSurface,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(
        string $source,
        string $target,
        string $relation,
        float $strength,
        bool $secondary = false,
    ): array {
        return [
            'source' => $source,
            'target' => $target,
            'relation' => $relation,
            'strength' => $strength,
            'secondary' => $secondary,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string> $meta
     * @return array<string,mixed>
     */
    private function surface(
        string $kind,
        string $title,
        string $summary,
        array $actions,
        array $meta = [],
    ): array {
        return [
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'actions' => $actions,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function action(string $label, string $url, bool $primary = false): array
    {
        return [
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
        ];
    }

    private function toolUrl(string $toolId, Plan $plan, Task $task): string
    {
        return match ($toolId) {
            'study_activity' => route('plans.tasks.study_activity.show', [$plan, $task]),
            'ai_practice' => route('plans.tasks.study_practice.show', [$plan, $task]),
            'career_workspace' => route('plans.career.index', $plan),
            'artifacts' => route('plans.artifacts.index', $plan),
            'resources' => route('plans.resources.index', $plan),
            'guided_execution' => route('plans.tasks.guided_execution.show', [$plan, $task]),
            default => route('plans.show', $plan).'#canovia-tools',
        };
    }
}
