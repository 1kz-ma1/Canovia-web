<?php

namespace App\Intelligence\Study;

use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Data\StudyIntelligenceResult;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Services\StateSnapshotStore;
use App\Models\Plan;
use App\Services\StudyExamDateService;
use App\Services\StudyOfficialExamReferenceService;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StudyPlanIntelligenceService
{
    public function __construct(
        private readonly TaskEvidenceAdapter $evidenceAdapter,
        private readonly StudyIntelligenceStateBuilder $stateBuilder,
        private readonly StudyExamReadinessEvaluator $readinessEvaluator,
        private readonly StateSnapshotStore $stateStore,
        private readonly StudyExamDateService $examDates,
        private readonly StudyOfficialExamReferenceService $officialExams,
    ) {}

    public function evaluate(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
        bool $persist = false,
    ): StudyIntelligenceResult {
        $scopeItems = $this->confirmedScopeItems($plan);
        $tasks = Task::query()
            ->where('plan_id', $plan->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'description',
                'next_action_note',
                'status',
                'progress_percent',
                'priority',
                'sort_order',
            ])
            ->map(fn (Task $task) => [
                'id' => (int) $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'next_action_note' => $task->next_action_note,
                'status' => $task->status,
                'progress_percent' => (int) $task->progress_percent,
                'priority' => (int) $task->priority,
                'sort_order' => (int) $task->sort_order,
            ])
            ->values()
            ->all();

        $evidence = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->whereIn('type', [
                'study_practice_assessed',
                'study_recall_reviewed',
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TaskEvidence $item) => $this->evidenceAdapter->adapt($item))
            ->values()
            ->all();

        $deadline = $this->examDates->resolve($plan);
        $officialReference = $this->officialExams->forPlan($plan);
        $practiceTaskId = collect($tasks)
            ->first(fn (array $task) =>
                ! in_array($task['status'], ['done', 'completed', 'cancelled'], true)
                && (int) $task['progress_percent'] < 100
                && preg_match('/試験範囲.{0,8}(?:確定|決め|設定)/u', (string) $task['title']) !== 1
                && preg_match('/演習|問題|復習|学習|科目A|科目B|過去問|理解度|診断|対策/u', (string) $task['title']) === 1,
            );
        $officialBaselineTaskId = is_array($practiceTaskId)
            ? (int) $practiceTaskId['id']
            : null;

        $state = $this->stateBuilder->build(
            IntelligenceDomain::Study,
            [
                'scope_type' => 'study_plan',
                'scope_id' => (int) $plan->id,
                'captured_at' => $capturedAt
                    ? DateTimeImmutable::createFromInterface($capturedAt)
                    : new DateTimeImmutable(),
                'confirmed_scope_items' => $scopeItems,
                'tasks' => $tasks,
                'exam_date' => $deadline['exam_date'],
                'exam_date_source' => $deadline['source'],
                'exam_date_conflict' => $deadline['conflict'],
                'official_exam_reference' => $officialReference,
                'official_exam_baseline_task_id' => $officialBaselineTaskId,
            ],
            $evidence,
        );

        $readiness = $this->readinessEvaluator->evaluate($state);

        $snapshot = null;
        if ($persist) {
            $snapshot = $this->stateStore->persist(
                $state,
                $plan->user_id ? (int) $plan->user_id : null,
                (int) $plan->id,
                [
                    'schema_version' => '1.0',
                    'builder' => StudyIntelligenceStateBuilder::class,
                    'builder_version' => '53.5',
                ],
            );
        }

        return new StudyIntelligenceResult(
            state: $state,
            readiness: $readiness,
            persistedSnapshot: $snapshot,
        );
    }

    public function persistSnapshot(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): StudyIntelligenceResult {
        return $this->evaluate($plan, $capturedAt, true);
    }

    public function tryPersistSnapshot(
        Plan $plan,
        ?DateTimeInterface $capturedAt = null,
    ): ?StudyIntelligenceResult {
        try {
            return $this->persistSnapshot($plan, $capturedAt);
        } catch (Throwable $exception) {
            Log::warning('Study Intelligence snapshot projection failed.', [
                'plan_id' => (int) $plan->id,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function confirmedScopeItems(Plan $plan): array
    {
        $items = StudyScopeItem::query()
            ->where('plan_id', $plan->id)
            ->whereHas('capture', fn ($query) => $query->where('status', 'confirmed'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $seen = [];

        return $items
            ->filter(function (StudyScopeItem $item) use (&$seen) {
                $key = hash('sha256', implode('|', [
                    $this->normalize((string) $item->subject),
                    $this->normalize((string) $item->unit),
                    $this->normalize((string) $item->range_text),
                    (string) ($item->page_start ?? ''),
                    (string) ($item->page_end ?? ''),
                ]));

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->map(fn (StudyScopeItem $item) => [
                'id' => (int) $item->id,
                'study_scope_capture_id' => (int) $item->study_scope_capture_id,
                'subject' => $item->subject,
                'unit' => $item->unit,
                'range_text' => $item->range_text,
                'page_start' => $item->page_start,
                'page_end' => $item->page_end,
            ])
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[\s　]+/u', ' ', $value) ?? $value;
    }
}
