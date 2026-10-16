<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\PlanOwnershipService;
use App\Services\StudyPracticeRetryService;
use App\Services\StudyPracticeReviewReferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudyPracticeRetryController extends Controller
{
    public function create(Request $request, Plan $plan, Task $task, int $attempt)
    {
        $source = app(StudyPracticeReviewController::class)->query($request, $plan, $task)->whereKey($attempt)->firstOrFail();
        app(PlanOwnershipService::class)->authorizeTask($request, $task);
        $cards = app(StudyPracticeRetryService::class)->cards($source);
        return response()->view('study_practice.retry', ['plan' => $plan, 'task' => $task,
            'source' => $source, 'cards' => $cards, 'retryRequestId' => (string) Str::uuid()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Plan $plan, Task $task, int $attempt)
    {
        $input = $request->validate(['retry_request_id' => ['required', 'uuid'], 'answers' => ['required', 'array']]);
        $retry = DB::transaction(function () use ($request, $plan, $task, $attempt, $input) {
            $livePlan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $liveTask = Task::where('plan_id', $livePlan->id)->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $source = app(StudyPracticeReviewController::class)->query($request, $livePlan, $liveTask)->whereKey($attempt)->firstOrFail();
            app(PlanOwnershipService::class)->authorizeTask($request, $liveTask);
            $cards = app(StudyPracticeRetryService::class)->cards($source);
            abort_if($cards === [], 409, '安全に再回答できる誤答・一部正解の記録がありません。');
            $payload = app(StudyPracticeRetryService::class)->result($source, $cards, $input['answers']);
            $questions = array_column($cards, 'question');
            $ordinals = array_column($cards, 'ordinal', 'id');
            $fingerprint = app(StudyPracticeReviewReferenceService::class)->questionHash(['source' => $source->id, 'questions' => $questions, 'ordinals' => $ordinals, 'answers' => $payload['answers']]);
            $actor = $request->user() ? null : app(BehaviorIdentityService::class)->resolve($request);
            $retry = StudyPracticeSession::query()->createOrFirst(['prepare_request_id' => $input['retry_request_id']], [
                'session_token' => (string) Str::uuid(), 'plan_id' => $plan->id, 'task_id' => $task->id,
                'user_id' => $request->user()?->id, 'actor_token' => $actor,
                'status' => StudyPracticeSession::STATUS_COMPLETED, 'exercise_title' => '同問への再回答',
                'strategy' => 'review_retry', 'strategy_version' => 'v1', 'selector_type' => 'saved_attempt', 'selector_version' => 'v1',
                'question_provider' => 'review_retry', 'question_provider_mode' => 'direct',
                'assessment_provider' => 'saved_review_reference', 'assessment_provider_mode' => 'direct',
                'provider_payload' => ['source_attempt_id' => $source->id, 'source_ordinals' => $ordinals, 'response_hash' => $fingerprint,
                    'same_question' => true, 'reference_was_available' => true, 'mastery_evidence' => false],
                'questions_snapshot' => $questions, 'selected_questions' => [], 'assessment_payload' => $payload,
                'started_at' => now(), 'completed_at' => now(),
            ]);
            abort_unless($retry->question_provider === 'review_retry'
                && (int) $retry->plan_id === (int) $plan->id && (int) $retry->task_id === (int) $task->id
                && ($request->user() ? (int) $retry->user_id === (int) $request->user()->id
                    : $retry->user_id === null && hash_equals((string) $retry->actor_token, (string) $actor))
                && hash_equals((string) data_get($retry->provider_payload, 'response_hash', ''), $fingerprint), 409);
            return $retry;
        }, 3);
        return redirect()->route('plans.tasks.study_practice.retry.show', [$plan, $task, $attempt, $retry->id]);
    }

    public function show(Request $request, Plan $plan, Task $task, int $attempt, int $retry)
    {
        $source = app(StudyPracticeReviewController::class)->query($request, $plan, $task)->whereKey($attempt)->firstOrFail();
        $record = $this->query($request, $plan, $task, $attempt)->whereKey($retry)->firstOrFail();
        return response()->view('study_practice.retry-result', ['plan' => $plan, 'task' => $task, 'source' => $source,
            'retry' => $record, 'attemptReview' => app(StudyPracticeRetryService::class)->review($record)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function query(Request $request, Plan $plan, Task $task, int $attempt)
    {
        $query = StudyPracticeSession::query()->where('plan_id', $plan->id)->where('task_id', $task->id)
            ->where('question_provider', 'review_retry')->where('status', StudyPracticeSession::STATUS_COMPLETED)
            ->where('provider_payload->source_attempt_id', $attempt);
        return $request->user() ? $query->where('user_id', $request->user()->id)
            : $query->whereNull('user_id')->where('actor_token', app(BehaviorIdentityService::class)->resolve($request));
    }
}
