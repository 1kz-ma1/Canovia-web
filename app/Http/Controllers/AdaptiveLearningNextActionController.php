<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Task;
use App\Services\AdaptiveLearningHistoryService;
use App\Services\BehaviorIdentityService;
use App\Services\PlanActivityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * V6.11: opt-in only. A candidate based on verified Bank answers is
 * not progress, mastery, TaskEvidence or an autonomous Task mutation.
 */
final class AdaptiveLearningNextActionController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        AdaptiveLearningHistoryService $history,
        PlanActivityService $activity,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $validated = $request->validate([
            'source_signature' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'],
            'expected_next_action_hash' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'],
        ]);

        DB::transaction(function () use ($request, $plan, $task, $ownership, $profiles, $identity, $history, $activity, $validated): void {
            // A Study Context Watch read holds this same parent Plan lock.
            // Do not lock/update Task first and race its source projection.
            $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            abort_unless($profiles->forPlan($livePlan)->key === 'study', 404);
            $current = Task::query()->where('plan_id', $livePlan->id)
                ->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $ownership->authorizeTask($request, $current);

            // Re-evaluate the specific actor's current recorded answers at
            // confirmation. Never trust candidate text sent by the browser.
            $preview = $history->forPlanTask(
                $request, $livePlan, $current, $identity->resolve($request)
            )['next_action_preview'] ?? null;

            abort_unless(is_array($preview), 409, '復習候補が変わりました。学習履歴を開き直してください。');
            abort_unless(hash_equals(
                (string) $preview['source_signature'],
                (string) $validated['source_signature'],
            ), 409, '新しい回答や除外が反映されました。候補を再確認してください。');

            $recommended = (string) $preview['label'];
            if ((string) ($current->next_action_note ?? '') === $recommended) {
                return; // repeated owner confirmation, no second write/log
            }

            abort_unless(hash_equals(
                hash('sha256', (string) ($current->next_action_note ?? '')),
                (string) $validated['expected_next_action_hash'],
            ), 409, 'Taskの次Actionが変更されています。再確認してください。');

            $current->update(['next_action_note' => $recommended]);
            $activity->record($livePlan, $request->user(), 'task_updated', 'task', (int) $current->id, [
                'source' => 'owner_confirmed_learning_review_v611',
                'learning_evidence_signature' => (string) $preview['source_signature'],
            ]);
        }, 3);

        return redirect()
            ->route('plans.tasks.learning.history', [$plan, $task])
            ->with('status', '確認した復習候補を、このTaskの次Actionに反映しました。進捗は変更していません。');
    }
}
