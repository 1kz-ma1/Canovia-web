<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallItem;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\FeatureAccessService;
use App\Services\NativeAiGateway;
use App\Services\PlanOwnershipService;
use App\Services\PlanCategoryProfileService;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Services\StudyRecallCandidateOutcomeService;
use App\Services\StudyRecallProgressionService;
use App\Services\StudyRecallSchedulerService;
use App\Services\StudyTaskProgressionService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudyRecallController extends Controller
{
    public function __construct(
        private readonly PlanCategoryProfileService $categoryProfiles,
        private readonly StudyAdaptiveActionService $studyActions,
    ) {}
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
        StudyRecallProgressionService $recallProgression,
        StudyRecallCandidateOutcomeService $candidateOutcomes,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $items = StudyRecallItem::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('is_active', true)
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $dueItems = $items->filter(fn (StudyRecallItem $item) => $item->isDue())->values();
        $currentItem = $dueItems->first();

        $todayReviewCount = $task->studyRecallReviews()
            ->where('reviewed_at', '>=', today())
            ->count();
        $recallProgressionState = $recallProgression->evaluate(
            $plan,
            $task,
            $items,
        );

        return view('study_recall.show', [
            'plan' => $plan,
            'task' => $task,
            'items' => $items,
            'canEdit' => $ownership->canEdit($request, $plan),
            'recallProgression' => $recallProgressionState,
            'candidateOutcomes' => $candidateOutcomes->project(
                $plan,
                $task,
            ),
            'currentItem' => $currentItem,
            'stats' => [
                'total' => $items->count(),
                'due' => $dueItems->count(),
                'new' => $items->where('repetitions', 0)->count(),
                'mastered' => $items->filter(fn (StudyRecallItem $item) => $item->isMastered())->count(),
                'reviewed_today' => $todayReviewCount,
            ],
            'recentReviews' => $task->studyRecallReviews()
                ->with('item')
                ->latest('reviewed_at')
                ->take(8)
                ->get(),
            'recallCandidates' => StudyRecallCandidate::query()
                ->with('source')
                ->where('plan_id', $plan->id)
                ->where('task_id', $task->id)
                ->where('status', 'pending')
                ->orderByDesc('confidence')
                ->orderBy('id')
                ->take(100)
                ->get(),
            'recallSources' => StudyRecallSource::query()
                ->with('resource:id,title,provider,url')
                ->where('plan_id', $plan->id)
                ->where('task_id', $task->id)
                ->latest('id')
                ->take(8)
                ->get(),
            'recallResources' => PlanResource::query()
                ->with('tasks:id,title')
                ->where('plan_id', $plan->id)
                ->where('resource_type', 'file')
                ->where(function ($query) use ($task) {
                    $query
                        ->whereDoesntHave('tasks')
                        ->orWhereHas(
                            'tasks',
                            fn ($taskQuery) =>
                                $taskQuery->where(
                                    'tasks.id',
                                    $task->id,
                                ),
                        );
                })
                ->orderBy('id')
                ->get(),
            'canGenerateRecallCandidates' => $nativeAi->isConfigured()
                && $featureAccess->canUse(
                    $request->user(),
                    FeatureKey::AutomaticAiExecution,
                    ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
                ),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership, true);

        $validated = $request->validate([
            'cards_text' => ['required', 'string', 'max:30000'],
        ]);

        $lines = collect(preg_split('/\R/u', $validated['cards_text']) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values();

        if ($lines->count() > 100) {
            throw ValidationException::withMessages([
                'cards_text' => '一度に追加できるカードは100件までです。',
            ]);
        }

        $parsed = $lines->map(function (string $line, int $index) {
            $parts = preg_split('/\t|\s*\|\s*|\s*｜\s*/u', $line, 2);

            if (! is_array($parts) || count($parts) < 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw ValidationException::withMessages([
                    'cards_text' => ($index + 1).'行目を「表 | 裏」の形式で入力してください。',
                ]);
            }

            return [
                'prompt' => trim($parts[0]),
                'answer' => trim($parts[1]),
            ];
        });

        $created = 0;
        foreach ($parsed as $card) {
            $fingerprint = hash('sha256', $this->normalize($card['prompt']).'|'.$this->normalize($card['answer']));

            $item = StudyRecallItem::query()->firstOrCreate(
                [
                    'task_id' => (int) $task->id,
                    'fingerprint' => $fingerprint,
                ],
                [
                    'plan_id' => (int) $plan->id,
                    'prompt' => $card['prompt'],
                    'answer' => $card['answer'],
                    'tags' => [],
                    'repetitions' => 0,
                    'lapse_count' => 0,
                    'interval_days' => 0,
                    'ease_factor' => 2.50,
                    'due_at' => null,
                    'is_active' => true,
                ],
            );

            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return redirect()
            ->route('plans.tasks.study_recall.show', [$plan, $task])
            ->with('success', $created.'件のRecallカードを追加しました。');
    }

    public function review(
        Request $request,
        Plan $plan,
        Task $task,
        StudyRecallItem $item,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        StudyRecallSchedulerService $scheduler,
        TaskEvidenceService $evidence,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership, true);
        $this->ensureItemBelongsToTask($item, $plan, $task);

        $validated = $request->validate([
            'rating' => ['required', Rule::in(StudyRecallSchedulerService::RATINGS)],
            'review_request_id' => ['required', 'uuid'],
        ]);

        $actorToken = $identity->resolve($request);
        $result = $scheduler->review(
            $item,
            $validated['rating'],
            $validated['review_request_id'],
            $request->user()?->id,
            $actorToken,
        );

        $review = $result['review'];
        $updatedItem = $result['item'];

        $candidateLineages = StudyRecallCandidate::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('status', 'promoted')
            ->where('promoted_item_id', $updatedItem->id)
            ->orderBy('id')
            ->take(12)
            ->get([
                'id',
                'study_recall_source_id',
                'confidence',
            ]);

        $evidence->record(
            $task,
            EvidenceSource::Native,
            'study_recall_reviewed',
            [
                'study_recall_review_id' => (int) $review->id,
                'study_recall_item_id' => (int) $updatedItem->id,
                'study_recall_candidate_ids' => $candidateLineages
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'study_recall_source_ids' => $candidateLineages
                    ->pluck('study_recall_source_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'candidate_confidences' => $candidateLineages
                    ->pluck('confidence')
                    ->map(fn ($value) => max(
                        0,
                        min(100, (int) $value),
                    ))
                    ->values()
                    ->all(),
                'prompt' => Str::limit((string) $updatedItem->prompt, 160),
                'rating' => (string) $review->rating,
                'repetitions' => (int) $updatedItem->repetitions,
                'lapse_count' => (int) $updatedItem->lapse_count,
                'interval_days' => (int) $updatedItem->interval_days,
                'due_at' => $updatedItem->due_at?->toIso8601String(),
                'mastered' => $updatedItem->isMastered(),
            ],
            confidence: 0.75,
            externalKey: 'study-recall-review:'.$review->id,
            userId: $request->user()?->id,
            actorToken: $request->user() ? null : $actorToken,
            occurredAt: $review->reviewed_at,
        );

        $this->studyActions->tryRefresh(
            $plan,
            $review->reviewed_at ?? now(),
        );

        $labels = [
            'again' => 'もう一度',
            'hard' => '難しい',
            'good' => '思い出せた',
            'easy' => '余裕',
        ];

        return redirect()
            ->route('plans.tasks.study_recall.show', [$plan, $task])
            ->with('status', ($labels[$validated['rating']] ?? '評価').'として記録しました。');
    }

    public function complete(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        StudyRecallProgressionService $recallProgression,
        StudyTaskProgressionService $taskProgression,
        TaskEvidenceService $evidence,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership, true);

        $actorToken = $identity->resolve($request);
        $decision = null;
        $alreadyCompleted = false;

        DB::transaction(function () use (
            $request,
            $plan,
            $task,
            $actorToken,
            $recallProgression,
            $taskProgression,
            $evidence,
            &$decision,
            &$alreadyCompleted,
        ) {
            $lockedTask = Task::query()
                ->where('plan_id', $plan->id)
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTask->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'recall_progression' =>
                        'このTaskは中止されています。Task状態を見直してからRecall結果を反映してください。',
                ]);
            }

            if (
                (int) $lockedTask->progress_percent >= 100
                || $lockedTask->status === 'done'
            ) {
                $alreadyCompleted = true;
                $decision = $taskProgression->afterVerifiedCompletion(
                    $plan,
                    $lockedTask,
                    'Taskはすでに完了しています。',
                );

                return;
            }

            $state = $recallProgression->evaluate(
                $plan,
                $lockedTask,
                null,
                true,
            );

            if (! (bool) ($state['eligible'] ?? false)) {
                throw ValidationException::withMessages([
                    'recall_progression' => (string) (
                        $state['reason']
                        ?? 'Recallの定着条件を確認できませんでした。'
                    ),
                ]);
            }

            $metrics = (array) ($state['metrics'] ?? []);
            $reason = 'Recall定着確認: '
                .(int) ($metrics['mastered'] ?? 0)
                .'/'
                .(int) ($metrics['total'] ?? 0)
                .'件が定着条件を満たし、due 0件。';

            $decision = $taskProgression->afterVerifiedCompletion(
                $plan,
                $lockedTask,
                $reason,
            );

            $nextAction = ($decision['kind'] ?? null) === 'advance_task'
                ? '次のTask「'.data_get(
                    $decision,
                    'next_task.title',
                    '次のTask',
                ).'」へ進む'
                : 'このPlanの学習完了を確認する';

            $lockedTask->update([
                'progress_percent' => 100,
                'remaining_minutes' => 0,
                'progress_reason' => mb_substr($reason, 0, 4000),
                'next_action_note' => $nextAction,
                'status' => 'done',
            ]);

            $evidence->record(
                $lockedTask,
                EvidenceSource::Native,
                'study_recall_mastery_confirmed',
                [
                    'reviewed_count' => (int) (
                        $metrics['reviewed'] ?? 0
                    ),
                    'mastered_count' => (int) (
                        $metrics['mastered'] ?? 0
                    ),
                    'total_count' => (int) (
                        $metrics['total'] ?? 0
                    ),
                    'due_count' => (int) ($metrics['due'] ?? 0),
                    'mastery_percent' => (int) (
                        $metrics['mastery_percent'] ?? 0
                    ),
                    'completion_confirmed_by_user' => true,
                ],
                confidence: 0.8,
                externalKey:
                    'study-recall-mastery-confirmed:task:'
                    .$lockedTask->id,
                userId: $request->user()?->id,
                actorToken: $request->user() ? null : $actorToken,
                occurredAt: now(),
            );
        }, 3);

        $this->studyActions->tryRefresh($plan, now());

        if (
            ($decision['kind'] ?? null) === 'advance_task'
            && data_get($decision, 'next_task.id')
        ) {
            return redirect()
                ->route('plans.tasks.study_activity.show', [
                    $plan,
                    data_get($decision, 'next_task.id'),
                ])
                ->with(
                    'status',
                    $alreadyCompleted
                        ? 'Taskはすでに完了しています。次の学習Taskへ進みます。'
                        : 'Recallの定着を確認してTaskを完了しました。次の学習Taskへ進みます。',
                );
        }

        return redirect()
            ->route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'work',
            ])
            ->with(
                'status',
                $alreadyCompleted
                    ? 'Taskはすでに完了しています。'
                    : 'Recallの定着を確認してTaskを完了しました。学習Plan全体を確認します。',
            );
    }

    public function destroy(
        Request $request,
        Plan $plan,
        Task $task,
        StudyRecallItem $item,
        PlanOwnershipService $ownership,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership, true);
        $this->ensureItemBelongsToTask($item, $plan, $task);

        $item->delete();

        return redirect()
            ->route('plans.tasks.study_recall.show', [$plan, $task])
            ->with('success', 'Recallカードを削除しました。');
    }

    private function authorizeTask(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        bool $edit = false,
    ): void {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        abort_unless($this->categoryProfiles->forPlan($plan)->key === 'study', 404);

        if ($edit) {
            $ownership->authorizeTask($request, $task);
            return;
        }

        abort_unless($ownership->canView($request, $plan), 404);
    }

    private function ensureItemBelongsToTask(StudyRecallItem $item, Plan $plan, Task $task): void
    {
        abort_unless(
            (int) $item->plan_id === (int) $plan->id
            && (int) $item->task_id === (int) $task->id,
            404,
        );
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
