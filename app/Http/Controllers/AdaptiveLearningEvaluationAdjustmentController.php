<?php
namespace App\Http\Controllers;

use App\Models\LearningAnswerEvaluationAdjustment;
use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Services\AdaptiveLearningCandidateService;
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
        PlanCategoryProfileService $profiles, BehaviorIdentityService $identity,
        AdaptiveLearningCandidateService $candidates)
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

        DB::transaction(function () use ($answerEvent, $request, $actorToken, $run, $candidates) {
            // Maintain answer-route lock order: Run before AnswerEvent.
            $lockedRun = LearningRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $locked = LearningAnswerEvent::whereKey($answerEvent->id)
                ->lockForUpdate()->firstOrFail();
            // Append-only, idempotent: no mutation of answer/grade/history.
            $adjustment = LearningAnswerEvaluationAdjustment::firstOrCreate([
                'learning_answer_event_id' => $locked->id,
            ], [
                'user_id' => $request->user()?->id,
                'actor_token' => $actorToken,
                'reason' => 'accidental_tap',
                'effect' => 'exclude_from_recommendations',
                'created_at' => now(),
            ]);

            // Reflect a newly excluded answer immediately in the
            // replaceable candidate layer. Never touch locked questions.
            if ($adjustment->wasRecentlyCreated
                && $lockedRun->status === LearningRun::STATUS_ACTIVE
                && in_array($lockedRun->mode, [
                    LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE,
                ], true)) {
                $candidates->refresh($lockedRun);
            }
        });

        return redirect()->route(
            $run->mode === 'exam' ? 'plans.tasks.learning.exam.show' : 'plans.tasks.learning.show',
            [$plan,$task,$run],
        )->with('notice','誤タップとして記録しました。採点結果は残し、今後の学習推薦への寄与だけを除外します。');
    }
}
