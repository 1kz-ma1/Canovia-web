<?php

namespace App\Http\Controllers;

use App\Models\LearningAnswerEvent;
use App\Models\LearningExamResponseDraft;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\AdaptiveLearningBankQueueService;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\QuestionBankGrader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Separate exam-only route: no mid-exam grading, no adaptive selection.
 * Profiles are verified versioned configuration, NOT guessed from Plan title.
 */
final class AdaptiveLearningExamController extends Controller
{
    private function authorizeStudy(Request $request, Plan $plan, Task $task,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles): void
    {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);
    }

    private function actorRuns(Request $request, Plan $plan, Task $task,
        BehaviorIdentityService $identity)
    {
        $query = LearningRun::query()->where('plan_id', $plan->id)
            ->where('task_id', $task->id)->where('mode', LearningRun::MODE_EXAM);
        if ($request->user()) return $query->where('user_id', $request->user()->id);
        return $query->whereNull('user_id')
            ->where('actor_token', $identity->resolve($request));
    }

    public function start(Request $request, Plan $plan, Task $task,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveExamProfileRegistry $registry,
        AdaptiveLearningBankQueueService $bank)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $validated = $request->validate([
            'start_request_id' => ['required', 'uuid'],
            'exam_profile_key' => ['required', 'string', 'max:120'],
            'question_pack_id' => ['required', 'integer'],
        ]);
        $profile = $registry->requireVerified($validated['exam_profile_key']);
        $pack = QuestionPack::where('status', 'published')
            ->findOrFail($validated['question_pack_id']);
        $meta = $pack->metadata ?? [];
        abort_unless(
            $pack->exam_code === $profile['exam_code']
            && $pack->subject === $profile['subject']
            && ($meta['exam_simulation_profile_key'] ?? '') === $profile['key']
            && ($meta['exam_simulation_profile_version'] ?? '') === (string) $profile['version'],
            409,
        );

        $questions = $pack->questions()->where('is_active', true)->get();
        if ($questions->count() !== (int) $profile['question_count']
            || ! $questions->every(fn ($q) => $bank->isSupported($q))) {
            throw ValidationException::withMessages([
                'question_pack_id' => 'この試験の問題数・採点可能形式に一致する検証済み問題セットがありません。',
            ]);
        }

        $userId = $request->user()?->id;
        $token = $userId ? null : $identity->resolve($request);

        $run = DB::transaction(function () use ($validated, $pack, $profile, $questions,
            $userId, $token, $plan, $task) {
            $existing = LearningRun::where('start_request_id', $validated['start_request_id'])
                ->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->mode === LearningRun::MODE_EXAM
                    && (int) $existing->plan_id === (int) $plan->id
                    && (int) $existing->task_id === (int) $task->id
                    && (int) $existing->question_pack_id === (int) $pack->id
                    && $existing->exam_profile_key === $profile['key']
                    && ($userId !== null
                        ? (int) $existing->user_id === (int) $userId
                        : $existing->user_id === null
                            && hash_equals((string) $existing->actor_token, (string) $token)),
                    409);
                return $existing;
            }

            $start = now();
            $run = LearningRun::create([
                'plan_id' => $plan->id, 'task_id' => $task->id,
                'user_id' => $userId, 'actor_token' => $token,
                'start_request_id' => $validated['start_request_id'],
                'question_pack_id' => $pack->id,
                'pack_title_snapshot' => $pack->title,
                'pack_version_snapshot' => $pack->version,
                'mode' => LearningRun::MODE_EXAM, 'status' => LearningRun::STATUS_ACTIVE,
                'current_ordinal' => 1, 'queue_policy_version' => 'exam_frozen_v1',
                'candidate_generation' => 0,
                'exam_profile_key' => $profile['key'],
                'exam_profile_version' => (string) $profile['version'],
                'exam_profile_snapshot' => array_merge($profile, ['resume_policy' => 'continue_timer']),
                'started_at' => $start,
                'exam_deadline_at' => $start->copy()->addMinutes((int) $profile['duration_minutes']),
            ]);

            foreach ($questions as $index => $q) {
                $field = collect($q->response_schema)->firstWhere('id', 'answer');
                $run->items()->create([
                    'ordinal' => $index + 1,
                    'question_id' => $q->id,
                    'question_snapshot' => [
                        'prompt' => $q->prompt,
                        'source_type' => $q->source_type,
                        'source_reference' => $q->source_reference,
                        'pack_version' => $pack->version,
                        'response_field' => [
                            'id' => 'answer', 'type' => 'single_choice',
                            'label' => $field['label'] ?? '回答',
                            'choices' => $field['choices'] ?? [],
                        ],
                    ],
                    'grading_rule_snapshot' => $q->grading_rule,
                    'explanation_snapshot' => $q->explanation,
                    'presented_at' => $index === 0 ? $start : null,
                ]);
            }

            return $run;
        });

        return redirect()->route('plans.tasks.learning.exam.show', [$plan, $task, $run]);
    }

    public function show(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $run = $this->actorRuns($request, $plan, $task, $identity)
            ->whereKey($learningRun->id)->firstOrFail();
        $item = $run->items()->with(['examResponse', 'answer'])
            ->where('ordinal', $run->current_ordinal)->firstOrFail();
        $items = $run->items()->with(['examResponse', 'answer'])->get();
        $expired = $run->status === LearningRun::STATUS_ACTIVE
            && $run->exam_deadline_at?->lessThanOrEqualTo(now());

        // No grading_rule or answer feedback is serialized to the active
        // exam view; Blade only accesses rules after status=completed.
        return view('learning.exam', [
            'plan' => $plan, 'task' => $task, 'run' => $run,
            'item' => $item, 'items' => $items, 'expired' => $expired,
            'responseRequestId' => (string) Str::uuid(),
        ]);
    }

    public function answer(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'learning_run_item_id' => ['required', 'integer'],
            'choice' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity, $input) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            $item = $run->items()->whereKey($input['learning_run_item_id'])->firstOrFail();
            $previous = $item->examResponse;

            // Form retry after auto-next is inert but may never silently edit.
            if ($previous && $previous->last_request_id === $input['request_id']) {
                abort_unless($previous->answer_value === $input['choice'], 409);
                return;
            }

            abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);
            abort_unless(now()->lessThan($run->exam_deadline_at), 409);
            abort_unless((int) $item->ordinal === (int) $run->current_ordinal, 409);
            abort_unless(! LearningExamResponseDraft::where('last_request_id', $input['request_id'])->exists(), 409);

            $choices = data_get($item->question_snapshot, 'response_field.choices', []);
            $allowed = collect(is_array($choices) ? $choices : [])->pluck('id')->map('strval')->all();
            if (! in_array($input['choice'], $allowed, true)) {
                throw ValidationException::withMessages(['choice' => '選択肢から回答してください。']);
            }

            if ($previous) {
                $previous->update([
                    'answer_value' => $input['choice'],
                    'last_request_id' => $input['request_id'],
                    'answered_at' => now(),
                ]);
            } else {
                LearningExamResponseDraft::create([
                    'learning_run_item_id' => $item->id,
                    'answer_value' => $input['choice'],
                    'last_request_id' => $input['request_id'],
                    'answered_at' => now(),
                ]);
            }

            $total = $run->items()->count();
            if ((int) $run->current_ordinal < $total) {
                $run->update(['current_ordinal' => $run->current_ordinal + 1]);
            }
            // Deliberately NOT grading, creating AnswerEvent, or changing Task.
        });

        return redirect()->route('plans.tasks.learning.exam.show', [$plan, $task, $learningRun]);
    }

    public function navigate(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $input = $request->validate(['ordinal' => ['required', 'integer', 'min:1', 'max:200']]);
        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity, $input) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);
            abort_unless(now()->lessThan($run->exam_deadline_at), 409);
            $item = $run->items()->where('ordinal', $input['ordinal'])->first();
            abort_unless($item, 404);
            $run->update(['current_ordinal' => $input['ordinal']]);
            if (! $item->presented_at) $item->update(['presented_at' => now()]);
        });
        return redirect()->route('plans.tasks.learning.exam.show', [$plan, $task, $learningRun]);
    }

    public function finish(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, QuestionBankGrader $grader)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity, $grader) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            if ($run->status === LearningRun::STATUS_COMPLETED) return;
            abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);
            $items = $run->items()->with('examResponse')->get();

            // All questions/answers were frozen before the exam started.
            // Only here do we materialize their grading events.
            foreach ($items as $item) {
                $draft = $item->examResponse;
                if (! $draft) continue; // unanswered counts in exam denominator
                $correct = $grader->gradeRule($item->grading_rule_snapshot ?? [], $draft->answer_value);
                abort_unless($correct !== null, 409);
                LearningAnswerEvent::create([
                    'learning_run_item_id' => $item->id,
                    'request_id' => (string) Str::uuid(),
                    'answer_value' => $draft->answer_value,
                    'was_correct' => $correct,
                    'grading_method' => 'exam_final_question_bank',
                    'answered_at' => $draft->answered_at,
                    'elapsed_ms' => null,
                    'evaluation_contribution' => null,
                    'evaluation_confidence' => null,
                ]);
            }
            $run->update(['status' => LearningRun::STATUS_COMPLETED, 'completed_at' => now()]);
        });
        return redirect()->route('plans.tasks.learning.exam.show', [$plan, $task, $learningRun]);
    }
}
