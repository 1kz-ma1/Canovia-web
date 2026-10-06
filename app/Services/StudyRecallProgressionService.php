<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyRecallItem;
use App\Models\Task;
use Illuminate\Support\Collection;

final class StudyRecallProgressionService
{
    public function __construct(
        private readonly StudyActivityPolicyService $activities,
    ) {}

    /**
     * @param Collection<int,StudyRecallItem>|null $items
     * @return array<string,mixed>
     */
    public function evaluate(
        Plan $plan,
        Task $task,
        ?Collection $items = null,
        bool $lockForUpdate = false,
    ): array {
        if ((int) $task->progress_percent >= 100 || $task->status === 'done') {
            return $this->state(
                'completed',
                false,
                'Taskはすでに完了しています。',
                $items ?? collect(),
                StudyActivityPolicyService::RECALL,
            );
        }

        if ($task->status === 'cancelled') {
            return $this->state(
                'cancelled',
                false,
                'このTaskは中止されています。',
                $items ?? collect(),
                StudyActivityPolicyService::RECALL,
            );
        }

        $activity = $this->activities->forPlanTask($plan, $task);
        $primaryKey = (string) data_get(
            $activity,
            'primary.key',
            StudyActivityPolicyService::QUESTION_PRACTICE,
        );

        if ($items === null) {
            $query = StudyRecallItem::query()
                ->where('plan_id', $plan->id)
                ->where('task_id', $task->id)
                ->where('is_active', true)
                ->orderBy('id');

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            $items = $query->get();
        } else {
            $items = $items
                ->filter(fn ($item) =>
                    $item instanceof StudyRecallItem
                    && (int) $item->plan_id === (int) $plan->id
                    && (int) $item->task_id === (int) $task->id
                    && (bool) $item->is_active
                )
                ->values();
        }

        if ($primaryKey !== StudyActivityPolicyService::RECALL) {
            return $this->state(
                'supplementary',
                false,
                'このTaskではRecallは補助学習です。Task完了はPrimary Activityの結果で確認します。',
                $items,
                $primaryKey,
            );
        }

        $total = $items->count();

        if ($total === 0) {
            return $this->state(
                'empty',
                false,
                'Recallカードがまだありません。Task範囲を表すカードを用意してから定着を確認します。',
                $items,
                $primaryKey,
            );
        }

        $reviewed = $items->filter(
            fn (StudyRecallItem $item) => $item->last_reviewed_at !== null,
        )->count();
        $mastered = $items->filter(
            fn (StudyRecallItem $item) => $item->isMastered(),
        )->count();
        $due = $items->filter(
            fn (StudyRecallItem $item) => $item->isDue(),
        )->count();

        if ($reviewed < $total) {
            return $this->state(
                'learning',
                false,
                '未学習カードが'.($total - $reviewed).'件あります。まずDeck全体を一度確認します。',
                $items,
                $primaryKey,
            );
        }

        if ($mastered < $total) {
            return $this->state(
                'retaining',
                false,
                '定着候補は'.$mastered.'/'.$total.'件です。全カードが定着条件を満たすまでRecallを続けます。',
                $items,
                $primaryKey,
            );
        }

        if ($due > 0) {
            return $this->state(
                'review_due',
                false,
                '定着候補でも今すぐ復習すべきカードが'.$due.'件あります。再確認後にTask完了を判断します。',
                $items,
                $primaryKey,
            );
        }

        return $this->state(
            'ready',
            true,
            '全カードが3回以上の想起と7日以上の間隔を満たし、現在dueのカードもありません。Task完了候補です。',
            $items,
            $primaryKey,
        );
    }

    /**
     * @param Collection<int,StudyRecallItem> $items
     * @return array<string,mixed>
     */
    private function state(
        string $kind,
        bool $eligible,
        string $reason,
        Collection $items,
        string $primaryActivity,
    ): array {
        $total = $items->count();
        $reviewed = $items->filter(
            fn ($item) =>
                $item instanceof StudyRecallItem
                && $item->last_reviewed_at !== null,
        )->count();
        $mastered = $items->filter(
            fn ($item) =>
                $item instanceof StudyRecallItem
                && $item->isMastered(),
        )->count();
        $due = $items->filter(
            fn ($item) =>
                $item instanceof StudyRecallItem
                && $item->isDue(),
        )->count();

        return [
            'kind' => $kind,
            'eligible' => $eligible,
            'reason' => $reason,
            'primary_activity' => $primaryActivity,
            'metrics' => [
                'total' => $total,
                'reviewed' => $reviewed,
                'mastered' => $mastered,
                'due' => $due,
                'mastery_percent' => $total > 0
                    ? (int) round(($mastered / $total) * 100)
                    : 0,
            ],
        ];
    }
}
