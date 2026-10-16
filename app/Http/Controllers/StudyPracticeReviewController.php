<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\StudyPracticeAttemptReviewService;
use Illuminate\Http\Request;

final class StudyPracticeReviewController extends Controller
{
    public function index(Request $request, Plan $plan, Task $task)
    {
        return response()->view('study_practice.history', ['plan' => $plan, 'task' => $task,
            'attempts' => $this->query($request, $plan, $task)->latest('id')->paginate(20)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Plan $plan, Task $task, int $attempt)
    {
        $record = $this->query($request, $plan, $task)->whereKey($attempt)->firstOrFail();
        return response()->view('study_practice.review', ['plan' => $plan, 'task' => $task, 'attempt' => $record,
            'attemptReview' => app(StudyPracticeAttemptReviewService::class)->forAttempt($record),
            'retryResults' => app(StudyPracticeRetryController::class)->query($request, $plan, $task, $attempt)->latest('id')->paginate(10, ['*'], 'retry_page')])->header('Cache-Control', 'private, no-store');
    }

    public function query(Request $request, Plan $plan, Task $task)
    {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        app(PlanOwnershipService::class)->authorizeTaskView($request, $task);
        abort_unless(app(PlanCategoryProfileService::class)->forPlan($plan)->key === 'study', 404);
        $query = StudyPracticeAttempt::query()->where('plan_id', $plan->id)->where('task_id', $task->id);
        return $request->user() ? $query->where('user_id', $request->user()->id)
            : $query->whereNull('user_id')->where('actor_token', app(BehaviorIdentityService::class)->resolve($request));
    }
}
