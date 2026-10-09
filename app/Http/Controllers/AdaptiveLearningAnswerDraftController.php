<?php

namespace App\Http\Controllers;

use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Task;
use App\Services\AdaptiveLearningAnswerDraftService;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Save only the current ungraded A/B response into the current server session.
 * Never accepts a question, grading rule, actor, or answer state from the client.
 */
final class AdaptiveLearningAnswerDraftController extends Controller
{
    public function store(
        Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveLearningAnswerDraftService $drafts,
    ): JsonResponse {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $query = LearningRun::query()->whereKey($learningRun->id)
            ->where('plan_id', $plan->id)->where('task_id', $task->id);
        if ($request->user()) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->whereNull('user_id')->where('actor_token', $identity->resolve($request));
        }
        $run = $query->firstOrFail();
        abort_unless(in_array($run->mode, [
            LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE,
        ], true), 404);
        abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);

        $input = $request->validate([
            'learning_run_item_id' => ['required', 'integer'],
            'choice' => ['nullable', 'string', 'max:255'],
            'choices' => ['sometimes', 'array', 'max:8'],
            'choices.*' => ['string', 'max:20'],
            'number' => ['nullable', 'string', 'max:64'],
            'reasoning' => ['nullable', 'string', 'max:1000'],
        ]);
        $item = $run->items()->where('ordinal', $run->current_ordinal)
            ->whereKey($input['learning_run_item_id'])->firstOrFail();
        abort_unless(! $item->answer()->exists(), 409);

        $snapshot = $item->question_snapshot ?? [];
        $type = (string) data_get($snapshot, 'response_field.type', '');
        $ruleType = (string) data_get($item->grading_rule_snapshot, 'type', '');
        $supported = [
            'single_choice' => 'exact_choice',
            'multiple_choice' => 'exact_multiple',
            'number' => 'numeric_tolerance',
        ];
        abort_unless(($supported[$type] ?? null) === $ruleType, 409);

        $fields = [];
        if ($type === 'number') {
            // Partial input (e.g. "-" or "1.") is valid in a draft, not a grade.
            $fields['number'] = $input['number'] ?? '';
        } else {
            $allowed = collect((array) data_get($snapshot, 'response_field.choices', []))
                ->pluck('id')->map('strval')->all();
            if ($type === 'single_choice') {
                $choice = $input['choice'] ?? '';
                if ($choice !== '' && ! in_array($choice, $allowed, true)) {
                    throw ValidationException::withMessages(['choice' => '有効な選択肢を指定してください。']);
                }
                $fields['choice'] = $choice;
            } else {
                $choices = $input['choices'] ?? [];
                if (! array_is_list($choices) || count($choices) !== count(array_unique($choices))
                    || ! collect($choices)->every(fn ($choice) => in_array($choice, $allowed, true))) {
                    throw ValidationException::withMessages(['choices' => '有効な選択肢を重複なく指定してください。']);
                }
                sort($choices, SORT_STRING);
                $fields['choices'] = $choices;
            }
        }

        $reasoning = $input['reasoning'] ?? '';
        if ($reasoning !== '' && $run->mode !== LearningRun::MODE_UNDERSTANDING) {
            throw ValidationException::withMessages([
                'reasoning' => '考え方メモは理解モードのみ保存できます。',
            ]);
        }
        if ($run->mode === LearningRun::MODE_UNDERSTANDING) {
            // Forward-compatible with the pending independent reasoning PR.
            $fields['reasoning'] = $reasoning;
        }

        $drafts->save($request, $run, $item, $fields);

        return response()->json(['saved' => true], 200)
            ->header('Cache-Control', 'no-store');
    }
}
