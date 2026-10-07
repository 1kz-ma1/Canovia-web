<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\PlanOwnershipService;
use App\Services\EarlyAccessService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function store(
        Request $request,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        EarlyAccessService $earlyAccess,
        ReleaseLevelService $releaseLevels,
    ) {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['usability', 'bug', 'request', 'positive'])],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'message' => ['nullable', 'string', 'max:4000', 'required_without:rating'],
            'page' => ['nullable', 'string', 'max:500'],
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'task_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $plan = ! empty($validated['plan_id']) ? Plan::find((int) $validated['plan_id']) : null;
        $task = ! empty($validated['task_id']) ? Task::with('plan')->find((int) $validated['task_id']) : null;

        if ($task) {
            $taskPlan = $task->plan;
            if (! $taskPlan || (! $taskPlan->is_public && ! $ownership->canView($request, $taskPlan))) {
                $task = null;
            } elseif (! $plan) {
                $plan = $taskPlan;
            }
        }

        if ($plan && ! $plan->is_public && ! $ownership->canView($request, $plan)) {
            $plan = null;
        }

        Feedback::create([
            'user_id' => $request->user()?->id,
            'actor_token' => $identity->resolve($request),
            'plan_id' => $plan?->id,
            'task_id' => $task?->id,
            'type' => $validated['type'],
            'rating' => isset($validated['rating']) ? (int) $validated['rating'] : null,
            'message' => trim((string) ($validated['message'] ?? '')),
            'page' => $validated['page'] ?? $request->headers->get('referer'),
            'app_version' => (string) config('canovia.version', 'v14'),
            'context' => [
                'route' => optional($request->route())->getName(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'authenticated' => $request->user() !== null,
                'early_access' => $earlyAccess->activeFor(
                    $request->user(),
                    $request,
                ),
                'release_level' => $releaseLevels
                    ->levelFor($request->user(), $request)
                    ->value,
            ],
            'status' => 'new',
        ]);

        return back()->with('status', 'ありがとう。フィードバックを受け取りました。');
    }
}
