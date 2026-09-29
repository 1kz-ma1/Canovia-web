<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ExecutionNavigationGraphService
{
    /**
     * Build the semantic L3 graph for the resolved execution scope.
     *
     * No guidance or visual-attention service is referenced here. The result only
     * describes which nodes exist in this scope and how they are related.
     *
     * @param array<string,mixed> $context
     * @return array{
     *     nodes: Collection<int,array<string,mixed>>,
     *     edges: Collection<int,array<string,mixed>>
     * }
     */
    public function build(array $context): array
    {
        $plan = $context['plan'] ?? null;
        $currentTask = $context['current_task'] ?? null;
        $primaryTool = $context['primary_tool'] ?? null;
        $nextTask = $context['next_task'] ?? null;
        $pendingInboxCount = (int) ($context['pending_inbox_count'] ?? 0);
        $latestInbox = $context['latest_inbox'] ?? null;

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
                    action: route('goal_discovery.show', $goal),
                    attentionRole: 'goal',
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
                action: route('plans.show', $plan),
                attentionRole: 'plan',
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
                $edges->push($this->edge(
                    'goal:'.$goal->id,
                    'plan:'.$plan->id,
                    'contains',
                    'goal-plan',
                ));
            }
        }

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

            $taskActions[] = $this->action(
                '今やることを生成',
                route('plans.tasks.execution_orchestration.show', [$plan, $currentTask]),
            );
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
                action: route('plans.show', $plan),
                attentionRole: 'primary-task',
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
            $edges->push($this->edge(
                'plan:'.$plan->id,
                $primaryNodeId,
                'current_action',
                'plan-primary',
            ));

            if ($nextTask instanceof Task) {
                $nextBlockerCount = collect($nextTask->dependencyIds())
                    ->filter(function (int $dependencyId) use ($plan) {
                        $dependency = $plan->tasks->firstWhere('id', $dependencyId);

                        return ! $dependency
                            || ($dependency->status !== 'done' && (int) $dependency->progress_percent < 100);
                    })
                    ->count();

                $nodes->push($this->node(
                    id: 'task:'.$nextTask->id,
                    type: 'task',
                    entityId: (int) $nextTask->id,
                    eyebrow: 'NEXT TASK',
                    label: (string) $nextTask->title,
                    subtitle: $nextBlockerCount > 0
                        ? $nextBlockerCount.'件の前提待ち · 先行可能な作業を確認できます'
                        : '現在Actionの次に見えている候補',
                    action: route('plans.show', $plan),
                    attentionRole: 'next-task',
                    classicSurface: $this->surface(
                        'Next Task',
                        (string) $nextTask->title,
                        filled($nextTask->description)
                            ? Str::limit((string) $nextTask->description, 180)
                            : '現在Actionの次に取り組む候補です。',
                        [
                            $this->action(
                                '今やることを生成',
                                route('plans.tasks.execution_orchestration.show', [$plan, $nextTask]),
                                $nextBlockerCount > 0,
                            ),
                            $this->action('Planで確認', route('plans.show', $plan), $nextBlockerCount === 0),
                            $this->action('Taskを編集', route('tasks.edit', $nextTask)),
                        ],
                        array_values(array_filter([
                            '進捗 '.max(0, min(100, (int) $nextTask->progress_percent)).'%',
                            $nextBlockerCount > 0 ? '前提待ち '.$nextBlockerCount.'件' : null,
                            (string) $plan->title,
                        ])),
                    ),
                ));
                $edges->push($this->edge(
                    $primaryNodeId,
                    'task:'.$nextTask->id,
                    'next',
                    'primary-next',
                ));
            }

            if ($toolId !== '') {
                $nodes->push($this->node(
                    id: 'tool:'.$toolId,
                    type: 'tool',
                    entityId: null,
                    eyebrow: 'ACTION / TOOL',
                    label: (string) ($primaryTool['name'] ?? 'Tool'),
                    subtitle: Str::limit((string) ($primaryTool['description'] ?? 'このTaskを進めるための手段'), 92),
                    action: $primaryToolUrl,
                    attentionRole: 'tool',
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
                $edges->push($this->edge(
                    $primaryNodeId,
                    'tool:'.$toolId,
                    'executed_with',
                    'primary-tool',
                ));
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
                    action: route('timeline.index'),
                    attentionRole: 'evidence',
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
                $edges->push($this->edge(
                    $primaryNodeId,
                    'evidence:'.$evidence->id,
                    'produced_evidence',
                    'primary-evidence',
                ));
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
                action: route('inbox.index'),
                attentionRole: 'inbox',
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
                $edges->push($this->edge(
                    'inbox:pending',
                    'plan:'.$plan->id,
                    'input_for',
                    'inbox-plan',
                    secondary: true,
                ));
            }
        }

        return [
            'nodes' => $nodes->values(),
            'edges' => $edges->values(),
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
        ?string $action,
        string $attentionRole,
        array $classicSurface = [],
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'entity_id' => $entityId,
            'eyebrow' => $eyebrow,
            'label' => $label,
            'subtitle' => $subtitle,
            'available_action' => $action,
            'classic_surface' => $classicSurface,
            'attention_role' => $attentionRole,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(
        string $source,
        string $target,
        string $relation,
        string $attentionRole,
        bool $secondary = false,
    ): array {
        return [
            'source' => $source,
            'target' => $target,
            'relation' => $relation,
            'secondary' => $secondary,
            'attention_role' => $attentionRole,
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
