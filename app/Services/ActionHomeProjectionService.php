<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanActivityLog;
use App\Models\User;
use Illuminate\Support\Collection;

final class ActionHomeProjectionService
{
    /**
     * Home answers "what needs my attention now?".
     *
     * It deliberately does not project Plan detail cards or a Plan browser.
     *
     * @param Collection<int,Plan> $plans
     * @return array{
     *   schema_version:int,
     *   signals:Collection<int,array<string,mixed>>,
     *   attention_count:int,
     *   collaboration_count:int,
     *   pending_update_count:int
     * }
     */
    public function build(Collection $plans, array $dashboard, ?User $actor): array
    {
        $signals = collect();
        $pendingUpdates = collect($dashboard['pending_plan_updates'] ?? []);
        $attentionPlans = collect($dashboard['attention_plans'] ?? []);

        foreach ($pendingUpdates as $session) {
            $plan = $session->plan;

            if (! $plan) {
                continue;
            }

            $signals->push([
                'kind' => 'plan_update',
                'severity' => 'attention',
                'eyebrow' => 'UPDATE REQUIRED',
                'title' => '作業結果を計画へ反映できます',
                'body' => ($session->task?->title ?? $plan->title)
                    .' · '.max(1, (int) ceil(((int) ($session->actual_seconds ?? 0)) / 60)).'分の実績',
                'plan' => $plan,
                'actor_name' => null,
                'occurred_at' => $session->ended_at,
                'action_url' => route('plans.review_assistant.show', [
                    'plan' => $plan,
                    'work_session_id' => $session->id,
                ]),
                'action_label' => '計画へ反映',
                'priority' => 0,
            ]);
        }

        foreach ($attentionPlans as $item) {
            $plan = data_get($item, 'plan');
            $progress = data_get($item, 'progress', []);
            $status = (string) data_get($progress, 'status', '');

            if (! $plan instanceof Plan || $status === '') {
                continue;
            }

            $signals->push([
                'kind' => 'plan_attention',
                'severity' => 'alert',
                'eyebrow' => 'PLAN STATUS',
                'title' => $plan->title.' · '.$status,
                'body' => $this->attentionBody($status, $progress),
                'plan' => $plan,
                'actor_name' => null,
                'occurred_at' => null,
                'action_url' => route('navigation.index', ['plan_id' => $plan->id]),
                'action_label' => '実行を見直す',
                'priority' => 1,
            ]);
        }

        $collaborationSignals = $plans
            ->filter(fn (Plan $plan) => (bool) $plan->is_collaborative)
            ->flatMap(function (Plan $plan) {
                return $plan->activityLogs
                    ->map(fn (PlanActivityLog $activity) => [
                        'plan' => $plan,
                        'activity' => $activity,
                    ]);
            })
            ->filter(function (array $item) use ($actor) {
                /** @var PlanActivityLog $activity */
                $activity = $item['activity'];

                if ($activity->created_at?->lt(now()->subDays(14))) {
                    return false;
                }

                return ! $actor
                    || $activity->user_id === null
                    || (int) $activity->user_id !== (int) $actor->id;
            })
            ->sortByDesc(fn (array $item) => sprintf(
                '%010d-%010d',
                $item['activity']->created_at?->timestamp ?? 0,
                (int) $item['activity']->id,
            ))
            ->take(4)
            ->map(function (array $item) {
                /** @var Plan $plan */
                $plan = $item['plan'];
                /** @var PlanActivityLog $activity */
                $activity = $item['activity'];
                $actorName = $activity->user?->name ?: '共同メンバー';

                return [
                    'kind' => 'collaboration',
                    'severity' => 'info',
                    'eyebrow' => 'COLLABORATION',
                    'title' => $actorName.'さんが'.$this->activityLabel($activity->action),
                    'body' => $plan->title.$this->activitySubject($activity),
                    'plan' => $plan,
                    'actor_name' => $actorName,
                    'occurred_at' => $activity->created_at,
                    'action_url' => route('plans.collaboration.settings', $plan),
                    'action_label' => '共同計画を確認',
                    'priority' => 2,
                ];
            });

        $signals = $signals
            ->concat($collaborationSignals)
            ->sort(function (array $left, array $right) {
                $priority = ((int) ($left['priority'] ?? 9)) <=> ((int) ($right['priority'] ?? 9));

                if ($priority !== 0) {
                    return $priority;
                }

                $leftAt = $left['occurred_at']?->timestamp ?? 0;
                $rightAt = $right['occurred_at']?->timestamp ?? 0;

                return $rightAt <=> $leftAt;
            })
            ->take(6)
            ->values()
            ->map(function (array $signal) {
                unset($signal['priority']);

                return $signal;
            });

        return [
            'schema_version' => 1,
            'signals' => $signals,
            'attention_count' => $attentionPlans->count(),
            'collaboration_count' => $collaborationSignals->count(),
            'pending_update_count' => $pendingUpdates->count(),
        ];
    }

    private function attentionBody(string $status, array $progress): string
    {
        $remainingDays = data_get($progress, 'remaining_days');
        $dailyMinutes = (int) data_get($progress, 'daily_required_minutes', 0);

        return match ($status) {
            '期限切れ' => '期限を過ぎています。残作業と期限をExecutionで見直せます。',
            '作業時間不足' => '現在の利用可能時間では残作業を収めにくい状態です。Executionで優先順位を確認できます。',
            '遅れ気味' => ($remainingDays !== null ? '残り'.$remainingDays.'日' : '期限まで')
                .($dailyMinutes > 0 ? ' · 目安'.$dailyMinutes.'分/日。' : '。')
                .'次に進めるTaskを確認できます。',
            default => '計画の状態が変化しています。次に進めるTaskを確認できます。',
        };
    }

    private function activityLabel(string $action): string
    {
        return match ($action) {
            'task_created' => 'タスクを追加しました',
            'task_updated' => 'タスクを更新しました',
            'task_deleted' => 'タスクを削除しました',
            'plan_updated', 'plan_ai_updated' => '計画を更新しました',
            'artifact_created' => '成果物を追加しました',
            'artifact_updated' => '成果物を更新しました',
            'resource_created' => '資料を追加しました',
            'resource_updated', 'resource_ai_assigned' => '資料を更新しました',
            'member_role_changed' => '共同計画の権限を更新しました',
            'invite_regenerated' => '招待情報を更新しました',
            default => '共同計画を更新しました',
        };
    }

    private function activitySubject(PlanActivityLog $activity): string
    {
        $metadata = is_array($activity->metadata) ? $activity->metadata : [];
        $subject = $metadata['task_title']
            ?? $metadata['artifact_title']
            ?? $metadata['resource_title']
            ?? null;

        return filled($subject) ? ' · '.$subject : '';
    }
}
