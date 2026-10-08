<?php

namespace App\Http\Controllers;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Services\AdaptiveLearningBankQueueService;
use App\Services\AdaptiveLearningCandidateService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\QuestionBankGrader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdaptiveLearningController extends Controller
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
        $runs = LearningRun::query()->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($request->user()) {
            return $runs->where('user_id', $request->user()->id);
        }

        return $runs->whereNull('user_id')
            ->where('actor_token', $identity->resolve($request));
    }

    public function index(Request $request, Plan $plan, Task $task,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveLearningBankQueueService $queue,
        AdaptiveExamProfileRegistry $examProfiles)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $packs = QuestionPack::query()->where('status', 'published')
            ->with(['questions' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('title')->limit(30)->get()
            ->filter(fn (QuestionPack $pack) =>
                $pack->questions->contains(fn ($q) => $queue->isSupported($q)))->values();

        $availableExamProfiles = $examProfiles->available();
        $examPacks = $packs->filter(fn ($pack) =>
            $availableExamProfiles->contains(function (array $profile) use ($pack) {
                $meta = $pack->metadata ?? [];
                return $pack->exam_code === $profile['exam_code']
                    && $pack->subject === $profile['subject']
                    && ($meta['exam_simulation_profile_key'] ?? '') === $profile['key']
                    && ($meta['exam_simulation_profile_version'] ?? '') === (string) $profile['version'];
            }))->values();

        $activeRuns = $this->actorRuns($request, $plan, $task, $identity)
            ->where('status', LearningRun::STATUS_ACTIVE)
            ->latest('id')->limit(10)->get();

        return view('learning.index', [
            'plan' => $plan, 'task' => $task, 'packs' => $packs,
            'activeRuns' => $activeRuns,
            'examProfiles' => $availableExamProfiles, 'examPacks' => $examPacks,
            'startRequestId' => (string) Str::uuid(),
            'examStartRequestId' => (string) Str::uuid(),
        ]);
    }

    public function start(Request $request, Plan $plan, Task $task,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveLearningBankQueueService $queue,
        AdaptiveLearningCandidateService $candidates)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $input = $request->validate([
            'start_request_id' => ['required', 'uuid'],
            'question_pack_id' => ['required', 'integer'],
            'mode' => ['required', 'in:understanding,practice'],
        ]);
        $pack = QuestionPack::where('status', 'published')
            ->findOrFail($input['question_pack_id']);
        $queue->requireSupportedPack($pack);
        $userId = $request->user()?->id;
        $token = $userId ? null : $identity->resolve($request);

        $run = DB::transaction(function () use ($input, $pack, $userId, $token, $plan, $task, $queue, $candidates) {
            $existing = LearningRun::where('start_request_id', $input['start_request_id'])
                ->lockForUpdate()->first();

            if ($existing) {
                abort_unless((int) $existing->plan_id === (int) $plan->id
                    && (int) $existing->task_id === (int) $task->id
                    && (int) $existing->question_pack_id === (int) $pack->id
                    && $existing->mode === $input['mode']
                    && ($userId !== null
                        ? (int) $existing->user_id === (int) $userId
                        : $existing->user_id === null && hash_equals((string) $existing->actor_token, (string) $token)),
                    409);
                return $existing;
            }

            $new = LearningRun::create([
                'plan_id' => $plan->id, 'task_id' => $task->id,
                'user_id' => $userId, 'actor_token' => $token,
                'start_request_id' => $input['start_request_id'],
                'question_pack_id' => $pack->id,
                'pack_title_snapshot' => $pack->title,
                'pack_version_snapshot' => $pack->version,
                'mode' => $input['mode'], 'status' => LearningRun::STATUS_ACTIVE,
                'current_ordinal' => 1, 'queue_policy_version' => 'bank_locked_v1',
                'started_at' => now(),
            ]);
            $queue->refill($new);
            if ($new->items()->doesntExist()) {
                throw ValidationException::withMessages([
                    'question_pack_id' => '現在、この問題集から回答可能な問題を準備できませんでした。',
                ]);
            }
            $candidates->refresh($new);
            return $new;
        });

        return redirect()->route('plans.tasks.learning.show', [$plan, $task, $run]);
    }

    public function show(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $run = $this->actorRuns($request, $plan, $task, $identity)
            ->whereKey($learningRun->id)->firstOrFail();
        abort_unless(in_array($run->mode, [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE], true), 404);
        $item = $run->items()->with('answer')->where('ordinal', $run->current_ordinal)->first();
        $answered = $run->items()->whereHas('answer')->count();
        $correct = $run->items()->whereHas('answer', fn ($q) => $q->where('was_correct', true))->count();
        return view('learning.show', [
            'plan' => $plan, 'task' => $task, 'run' => $run,
            'item' => $item, 'answeredCount' => $answered, 'correctCount' => $correct,
            'answerRequestId' => (string) Str::uuid(),
        ]);
    }

    public function answer(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, QuestionBankGrader $grader,
        AdaptiveLearningCandidateService $candidates)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'learning_run_item_id' => ['required', 'integer'],
            'choice' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity, $input, $grader, $candidates) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->mode, [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE], true), 404);
            $item = $run->items()->where('ordinal', $run->current_ordinal)
                ->whereKey($input['learning_run_item_id'])->firstOrFail();

            $existing = $item->answer;
            if ($existing) {
                abort_unless($existing->answer_value === $input['choice'], 409);
                return; // identical submission or browser retry is inert.
            }
            abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);
            abort_unless(! LearningAnswerEvent::where('request_id', $input['request_id'])->exists(), 409);

            $choices = data_get($item->question_snapshot, 'response_field.choices', []);
            $ids = collect(is_array($choices) ? $choices : [])
                ->pluck('id')->map('strval')->all();
            if (! in_array($input['choice'], $ids, true)) {
                throw ValidationException::withMessages(['choice' => '有効な選択肢から回答してください。']);
            }

            $correct = $grader->gradeRule($item->grading_rule_snapshot ?? [], $input['choice']);
            abort_unless($correct !== null, 409); // no fabricated grade
            $elapsed = $item->presented_at
                ? min(3600000, max(0, (int) $item->presented_at->diffInMilliseconds(now())))
                : null;

            LearningAnswerEvent::create([
                'learning_run_item_id' => $item->id,
                'request_id' => $input['request_id'],
                'answer_value' => $input['choice'],
                'was_correct' => $correct,
                'grading_method' => 'question_bank_exact_choice',
                'answered_at' => now(),
                'elapsed_ms' => $elapsed,
                // Learning inference is intentionally separate from grading.
                'evaluation_contribution' => null,
                'evaluation_confidence' => null,
            ]);
            // Re-rank only FUTURE candidates; persisted locked items stay fixed.
            $candidates->refresh($run);
        });

        return redirect()->route('plans.tasks.learning.show', [$plan, $task, $learningRun]);
    }

    public function next(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveLearningBankQueueService $queue,
        AdaptiveLearningCandidateService $candidates)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $exhausted = false;
        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity, $queue, $candidates, &$exhausted) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->mode, [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE], true), 404);
            abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);
            $current = $run->items()->where('ordinal', $run->current_ordinal)->firstOrFail();
            abort_unless($current->answer()->exists(), 409);

            $nextOrdinal = $run->current_ordinal + 1;
            $next = $run->items()->where('ordinal', $nextOrdinal)->first();

            if (! $next) {
                $queue->refill($run);
                $next = $run->items()->where('ordinal', $nextOrdinal)->first();
            }
            if (! $next) {
                $exhausted = true;
                return;
            }
            $run->update(['current_ordinal' => $nextOrdinal]);
            if (! $next->presented_at) $next->update(['presented_at' => now()]);
            $queue->refill($run);
            $candidates->refresh($run);
        });

        return redirect()->route('plans.tasks.learning.show', [$plan, $task, $learningRun])
            ->with($exhausted ? 'notice' : 'learning_next_ready',
                $exhausted ? 'この問題集の対応問題は出題済みです。ここで終了できます。' : true);
    }

    public function finish(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->mode, [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE], true), 404);
            if ($run->status === LearningRun::STATUS_COMPLETED) return;
            $run->update(['status' => LearningRun::STATUS_COMPLETED, 'completed_at' => now()]);
        });

        return redirect()->route('plans.tasks.learning.show', [$plan, $task, $learningRun]);
    }
}
