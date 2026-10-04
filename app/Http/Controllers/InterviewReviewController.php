<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Models\CareerSelectionEvent;
use App\Models\InterviewReview;
use App\Models\InterviewReviewAnswer;
use App\Models\Plan;
use App\Services\BehaviorIdentityService;
use App\Services\InterviewReviewQuestionService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InterviewReviewController extends Controller
{
    public function __construct(
        private readonly CareerAdaptiveActionService $careerIntelligence,
    ) {}

    public function show(
        Request $request,
        Plan $plan,
        CareerSelectionEvent $event,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        InterviewReviewQuestionService $questions,
    ) {
        $ownership->authorizeView($request, $plan);
        $this->authorizeEvent($plan, $event, $profiles);

        $event->load(['application', 'task', 'interviewReview.answers']);
        $review = $event->interviewReview;
        $answers = $review
            ? $review->answers->pluck('answer', 'question_key')
            : collect();

        return view('career.interview-review', [
            'plan' => $plan,
            'event' => $event,
            'application' => $event->application,
            'review' => $review,
            'questions' => $questions->questions($event),
            'answers' => $answers,
            'canEdit' => $ownership->canEdit($request, $plan),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        CareerSelectionEvent $event,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        InterviewReviewQuestionService $questionService,
        TaskEvidenceService $evidenceService,
        BehaviorIdentityService $identity,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeEvent($plan, $event, $profiles);
        $event->load(['application', 'task', 'interviewReview']);

        $validated = $request->validate([
            'action' => ['required', 'in:save,complete'],
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'string', 'max:4000'],
        ]);

        $questions = collect($questionService->questions($event));
        $allowedKeys = $questions->pluck('key')->all();
        $submitted = collect($validated['answers'])
            ->only($allowedKeys)
            ->map(fn ($answer) => trim((string) $answer));

        if ($validated['action'] === 'complete' && $submitted->filter(fn ($answer) => $answer !== '')->isEmpty()) {
            throw ValidationException::withMessages([
                'answers' => '振り返りを1つ以上残してから完了してください。',
            ]);
        }

        $actorToken = $identity->resolve($request);
        $completed = $validated['action'] === 'complete'
            || $event->interviewReview?->status === InterviewReview::STATUS_COMPLETED;

        $review = DB::transaction(function () use (
            $request,
            $plan,
            $event,
            $questions,
            $submitted,
            $completed,
            $evidenceService,
            $actorToken,
        ) {
            $review = InterviewReview::query()->firstOrCreate(
                ['career_selection_event_id' => $event->id],
                [
                    'plan_id' => $plan->id,
                    'career_application_id' => $event->career_application_id,
                    'status' => InterviewReview::STATUS_DRAFT,
                    'context_snapshot' => [
                        'company_name' => $event->application->company_name,
                        'role_title' => $event->application->role_title,
                        'stage' => $event->stage,
                        'scheduled_at' => $event->scheduled_at?->toIso8601String(),
                    ],
                ],
            );

            foreach ($questions as $index => $question) {
                InterviewReviewAnswer::query()->updateOrCreate(
                    [
                        'interview_review_id' => $review->id,
                        'question_key' => $question['key'],
                    ],
                    [
                        'prompt' => $question['prompt'],
                        'answer' => $submitted->get($question['key']),
                        'source' => $question['source'],
                        'sort_order' => $index + 1,
                    ],
                );
            }

            $insights = [
                'best_moment' => $submitted->get('best_moment'),
                'asked_questions' => $submitted->get('asked_questions'),
                'difficult_moment' => $submitted->get('difficult_moment'),
                'next_focus' => $submitted->get('next_focus'),
                'company_impression' => $submitted->get('company_impression'),
            ];

            $review->update([
                'status' => $completed ? InterviewReview::STATUS_COMPLETED : InterviewReview::STATUS_DRAFT,
                'insights' => $insights,
                'completed_at' => $completed ? ($review->completed_at ?? now()) : null,
            ]);

            if ($completed) {
                if ($event->result) {
                    $event->update([
                        'status' => 'completed',
                        'completed_at' => $event->completed_at ?? now(),
                    ]);
                } else {
                    $event->update([
                        'status' => 'result_waiting',
                        'completed_at' => $event->completed_at ?? now(),
                    ]);

                    $event->application->update([
                        'status' => 'waiting',
                        'next_event_at' => null,
                    ]);
                }

                if ($event->task) {
                    $evidenceService->record(
                        $event->task,
                        EvidenceSource::Native,
                        'interview_review_completed',
                        [
                            'career_application_id' => (int) $event->career_application_id,
                            'career_selection_event_id' => (int) $event->id,
                            'interview_review_id' => (int) $review->id,
                            'company_name' => $event->application->company_name,
                            'role_title' => $event->application->role_title,
                            'stage' => $event->stage,
                            'best_moment' => $submitted->get('best_moment'),
                            'asked_questions' => $submitted->get('asked_questions'),
                            'difficult_moment' => $submitted->get('difficult_moment'),
                            'next_focus' => $submitted->get('next_focus'),
                        ],
                        confidence: 1.0,
                        externalKey: 'interview-review:'.$review->id.':completed',
                        userId: $request->user()?->id,
                        actorToken: $actorToken,
                        occurredAt: now(),
                    );
                }
            }

            return $review->fresh('answers');
        });

        if ($completed) {
            $this->careerIntelligence->tryRefresh($plan);
        }

        return redirect()
            ->route('plans.career.interview_reviews.show', [$plan, $event])
            ->with('success', $completed
                ? '振り返りを保存しました。次の面接へ活かせる状態になりました。'
                : '振り返りを途中保存しました。');
    }

    private function authorizeEvent(
        Plan $plan,
        CareerSelectionEvent $event,
        PlanCategoryProfileService $profiles,
    ): void {
        abort_unless($profiles->forPlan($plan)->key === 'career', 404);
        $event->loadMissing('application');
        abort_unless((int) $event->application?->plan_id === (int) $plan->id, 404);
        abort_unless($event->type === 'interview', 404);
        abort_if($event->status === 'cancelled', 404);
    }
}
