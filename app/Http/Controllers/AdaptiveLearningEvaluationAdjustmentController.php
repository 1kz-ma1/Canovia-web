<?php
namespace App\Http\Controllers;

use App\Models\LearningAnswerEvaluationAdjustment;
use App\Models\LearningAnswerEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdaptiveLearningEvaluationAdjustmentController extends Controller
{
    public function store(Request $request, Plan $plan, Task $task,
        LearningAnswerEvent $answerEvent, PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles, BehaviorIdentityService $identity)
    {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);
        $request->validate(['reason' => ['required','in:accidental_tap']]);

        $actorToken = $request->user() ? null : $identity->resolve($request);
        $run = $answerEvent->item?->run;
        abort_unless($run && (int) $run->plan_id === (int) $plan->id
            && (int) $run->task_id === (int) $task->id
            && ($request->user()
                ? (int) $run->user_id === (int) $request->user()->id
                : $run->user_id === null
                    && hash_equals((string) $run->actor_token, (string) $actorToken)), 404);

        DB::transaction(function () use ($answerEvent, $request, $actorToken) {
            $locked = LearningAnswerEvent::whereKey($answerEvent->id)
                ->lockForUpdate()->firstOrFail();
            // Append-only, idempotent: no mutation of answer/grade/history.
            LearningAnswerEvaluationAdjustment::firstOrCreate([
                'learning_answer_event_id' => $locked->id,
            ], [
                'user_id' => $request->user()?->id,
                'actor_token' => $actorToken,
                'reason' => 'accidental_tap',
                'effect' => 'exclude_from_recommendations',
                'created_at' => now(),
            ]);
        });

        return redirect()->route(
            $run->mode === 'exam' ? 'plans.tasks.learning.exam.show' : 'plans.tasks.learning.show',
            [$plan,$task,$run],
        )->with('notice','誤タップとして記録しました。採点結果は残し、今後の学習推薦への寄与だけを除外します。');
    }
}
