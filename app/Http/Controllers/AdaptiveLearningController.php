<?php

namespace App\Http\Controllers;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Services\AdaptiveLearningAnswerDraftService;
use App\Services\AdaptiveLearningHistoryService;
use App\Services\AdaptiveLearningReviewEntryService;
use App\Services\AdaptiveLearningDistinctReviewService;
use App\Services\AdaptiveLearningBankQueueService;
use App\Services\AdaptiveLearningCandidateService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\AdaptiveExamPackReadinessService;
use App\Services\AdaptiveLearningModeRecommendationService;
use App\Services\AdaptiveLearningTypedAnswerService;
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
        AdaptiveExamProfileRegistry $examProfiles,
        AdaptiveExamPackReadinessService $examReadiness,
        AdaptiveLearningModeRecommendationService $recommendations,
        AdaptiveLearningHistoryService $history,
        AdaptiveLearningReviewEntryService $reviewEntry,
        AdaptiveLearningDistinctReviewService $distinctReview)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        // Filter eligible packs before applying the display cap. Limiting the
        // first 30 published packs could hide the only usable Bank entirely.
        // Lazy batches avoid loading every published pack into memory.
        $packs = QuestionPack::query()->where('status', 'published')
            ->whereHas('questions', fn ($q) => $q->where('is_active', true))
            ->with(['questions' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('title')->orderBy('id')->lazy(30)
            ->filter(fn (QuestionPack $pack) =>
                $pack->questions->contains(fn ($q) => $queue->isSupported($q)))
            ->take(30)->collect();

        $availableExamProfiles = $examProfiles->available();
        // Full exam packs must not be hidden by the ordinary 30-pack practice
        // display cap. Inspect only published packs matching verified profiles.
        $examOptions = collect();
        foreach ($availableExamProfiles as $profile) {
            QuestionPack::query()->where('status', 'published')
                ->where('exam_code', $profile['exam_code'])
                ->where('subject', $profile['subject'])
                ->orderBy('id')->lazy(30)
                ->each(function (QuestionPack $pack) use ($profile, $examReadiness, $examOptions) {
                    if ($examOptions->count() >= 30) return false;
                    if ($examReadiness->inspect($pack, $profile)['ready']) {
                        $examOptions->push(['pack' => $pack, 'profile' => $profile]);
                    }
                    return null;
                });
            if ($examOptions->count() >= 30) break;
        }
        $examPacks = $examOptions->pluck('pack');

        $learningRecommendations = $recommendations->forPlanTask(
            $request, $plan, $task, $identity->resolve($request), $examPacks->isNotEmpty(),
        );

        $activeRuns = $this->actorRuns($request, $plan, $task, $identity)
            ->where('status', LearningRun::STATUS_ACTIVE)
            ->latest('id')->limit(10)->get();

        $reviewPreview = $request->boolean('review')
            ? $reviewEntry->preview($request, $plan, $task, $identity->resolve($request))
            : null;
        $reviewPacks = $reviewPreview
            ? $reviewEntry->packsForTopic((string) $reviewPreview['topic'])
            : collect();
        $distinctReviewPacks = $reviewPreview
            ? $distinctReview->packsForTopic($request, $plan, $task,
                $identity->resolve($request), (string) $reviewPreview['topic'])
            : collect();
        $learningHistory = $history->forPlanTask($request, $plan, $task,
            $identity->resolve($request));
        $delayedRecheck = $learningHistory['delayed_recheck'] ?? null;
        $delayedPacks = ($delayedRecheck && $delayedRecheck['state'] === 'due')
            ? $distinctReview->packsForTopic($request, $plan, $task,
                $identity->resolve($request), (string) $delayedRecheck['topic'])
            : collect();

        return view('learning.index', [
            'reviewPreview' => $reviewPreview, 'reviewPacks' => $reviewPacks,
            'distinctReviewPacks' => $distinctReviewPacks,
            'distinctStartRequestId' => (string) Str::uuid(),
            'delayedStartRequestId' => (string) Str::uuid(),
            'delayedRecheck' => $delayedRecheck, 'delayedPacks' => $delayedPacks,
            'plan' => $plan, 'task' => $task, 'packs' => $packs,
            'activeRuns' => $activeRuns,
            'examProfiles' => $availableExamProfiles, 'examPacks' => $examPacks,
            'examOptions' => $examOptions,
            'learningRecommendations' => $learningRecommendations,
            'learningHistory' => $learningHistory,
            'startRequestId' => (string) Str::uuid(),
            'reviewStartRequestId' => (string) Str::uuid(),
            'examStartRequestId' => (string) Str::uuid(),
        ]);
    }

    public function start(Request $request, Plan $plan, Task $task,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveLearningBankQueueService $queue,
        AdaptiveLearningCandidateService $candidates,
        AdaptiveLearningReviewEntryService $reviewEntry,
        AdaptiveLearningDistinctReviewService $distinctReview,
        AdaptiveLearningHistoryService $history)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $input = $request->validate([
            'start_request_id' => ['required', 'uuid'],
            'question_pack_id' => ['required', 'integer'],
            'mode' => ['required', 'in:understanding,practice'],
            'review_topic' => ['required_with:review_source_signature', 'nullable', 'string', 'max:50'],
            'review_source_signature' => ['required_with:review_topic', 'nullable', 'regex:/\\A[a-f0-9]{64}\\z/'],
            'review_distinct' => ['sometimes', 'in:1'],
            'delayed_topic' => ['required_with:delayed_source_signature', 'nullable', 'string', 'max:50'],
            'delayed_source_signature' => ['required_with:delayed_topic', 'nullable', 'regex:/\\A[a-f0-9]{64}\\z/'],
            'delayed_distinct' => ['sometimes', 'in:1'],
        ]);
        $pack = QuestionPack::where('status', 'published')
            ->findOrFail($input['question_pack_id']);
        $queue->requireSupportedPack($pack);
        $userId = $request->user()?->id;
        $token = $userId ? null : $identity->resolve($request);

        $reviewTopic = null;
        $distinctIds = [];
        $isDelayed = false;
        abort_unless(! isset($input['delayed_distinct'])
            || (filled($input['delayed_topic'] ?? null)
                && filled($input['delayed_source_signature'] ?? null)), 409,
            '後日の別問題確認には最新の回答記録が必要です。');
        abort_unless(! filled($input['delayed_topic'] ?? null)
            || (! filled($input['review_topic'] ?? null)
                && ! filled($input['review_source_signature'] ?? null)
                && ! isset($input['review_distinct'])), 409,
            '復習経路を同時には選べません。');
        abort_unless(! isset($input['review_distinct']) || filled($input['review_topic'] ?? null),
            409, '別問題での再確認には現在の復習候補が必要です。');
        if (filled($input['review_topic'] ?? null)) {
            abort_unless($input['mode'] === LearningRun::MODE_UNDERSTANDING, 409,
                '分野指定の復習は理解モードで開始してください。');
            // POST re-derives the actor's *current* preview. A forged field,
            // correction or extra answer cannot choose arbitrary curriculum.
            $preview = $reviewEntry->preview($request, $plan, $task, $identity->resolve($request));
            abort_unless(is_array($preview)
                && mb_strtolower(trim((string) $preview['topic']))
                    === mb_strtolower(trim((string) $input['review_topic']))
                && hash_equals((string) $preview['source_signature'],
                    (string) ($input['review_source_signature'] ?? '')),
                409, '回答記録が変わりました。復習候補を開き直してください。');
            abort_unless($reviewEntry->supportsReview($pack, (string) $preview['topic']),
                409, 'この分野の復習問題が足りません。公開済みの問題集を確認してください。');
            $reviewTopic = (string) $preview['topic'];
            if (isset($input['review_distinct'])) {
                $distinctIds = $distinctReview->questionIds($request, $plan, $task,
                    $identity->resolve($request), $pack, $reviewTopic);
                abort_unless(count($distinctIds) === 2, 409,
                    '別問題での再確認に必要な未回答問題が2問ありません。問題集を選び直してください。');
            }
        } elseif (filled($input['review_source_signature'] ?? null)) {
            abort(422, '復習する分野が指定されていません。');
        } elseif (filled($input['delayed_topic'] ?? null)) {
            abort_unless(isset($input['delayed_distinct'])
                && $input['mode'] === LearningRun::MODE_UNDERSTANDING, 409,
                '後日の再確認は理解モード・別問題2問を本人が選んで開始してください。');
            $current = $history->forPlanTask($request, $plan, $task,
                $identity->resolve($request))['delayed_recheck'] ?? null;
            abort_unless(is_array($current) && $current['state'] === 'due'
                && mb_strtolower((string) $current['topic']) ===
                    mb_strtolower(trim((string) $input['delayed_topic']))
                && hash_equals((string) $current['source_signature'],
                    (string) ($input['delayed_source_signature'] ?? '')), 409,
                '再確認の根拠が更新されました。学習画面から確認し直してください。');
            $reviewTopic = (string) $current['topic'];
            $distinctIds = $distinctReview->questionIds($request, $plan, $task,
                $identity->resolve($request), $pack, $reviewTopic);
            abort_unless(count($distinctIds) === 2, 409,
                '後日の再確認に必要な未回答の問題が2問ありません。');
            $isDelayed = true;
        } elseif (filled($input['delayed_source_signature'] ?? null)) {
            abort(422, '再確認する分野が指定されていません。');
        }

        $policy = $isDelayed ? 'bank_topic_delayed_distinct_initial_v617'
            : ($distinctIds !== [] ? 'bank_topic_distinct_initial_v616'
            : ($reviewTopic ? 'bank_topic_review_initial_v613' : 'bank_locked_v1'));
        $run = DB::transaction(function () use ($input, $pack, $userId, $token, $plan, $task, $queue, $candidates, $reviewTopic, $distinctIds, $policy) {
            $existing = LearningRun::where('start_request_id', $input['start_request_id'])
                ->lockForUpdate()->first();

            if ($existing) {
                abort_unless((int) $existing->plan_id === (int) $plan->id
                    && (int) $existing->task_id === (int) $task->id
                    && (int) $existing->question_pack_id === (int) $pack->id
                    && $existing->mode === $input['mode']
                    && $existing->queue_policy_version === $policy
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
                'current_ordinal' => 1,
                'queue_policy_version' => $policy,
                'started_at' => now(),
            ]);
            // On a resumed short-session learning habit, carry over only
            // actor-scoped recorded evidence before locking the first items.
            // With no history, candidate order remains deterministic Bank order.
            $candidates->refresh($new);
            $queue->refill($new, $reviewTopic, $distinctIds);
            if ($distinctIds !== []) {
                $locked = $new->items()->orderBy('ordinal')->limit(2)
                    ->pluck('question_id')->map(fn ($id) => (int) $id)->all();
                abort_unless($locked === $distinctIds, 409,
                    '別問題の初期出題を確保できません。再度確認してください。');
            }
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
        BehaviorIdentityService $identity, AdaptiveLearningAnswerDraftService $drafts)
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
            'answerDraft' => $item ? $drafts->restore($request, $run, $item) : [],
        ]);
    }

    public function answer(Request $request, Plan $plan, Task $task, LearningRun $learningRun,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, QuestionBankGrader $grader,
        AdaptiveLearningCandidateService $candidates, AdaptiveLearningTypedAnswerService $typedAnswers,
        AdaptiveLearningAnswerDraftService $drafts)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'learning_run_item_id' => ['required', 'integer'],
            'choice' => ['nullable', 'string', 'max:255'],
            'choices' => ['sometimes', 'array', 'max:8'],
            'choices.*' => ['string', 'max:20'],
            'number' => ['nullable', 'string', 'max:64'],
            'reasoning' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity, $input, $grader, $candidates, $typedAnswers) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->mode, [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE], true), 404);
            $item = $run->items()->where('ordinal', $run->current_ordinal)
                ->whereKey($input['learning_run_item_id'])->firstOrFail();

            $normalized = $typedAnswers->normalize(
                $item->question_snapshot ?? [],
                $item->grading_rule_snapshot ?? [],
                $input,
            );

            // Optional self-explanation is evidence of what the learner wrote,
            // never a grade or a verified reasoning assessment. Only the
            // Understanding mode exposes or accepts this field.
            $reasoning = trim((string) ($input['reasoning'] ?? ''));
            if ($run->mode !== LearningRun::MODE_UNDERSTANDING && $reasoning !== '') {
                throw ValidationException::withMessages([
                    'reasoning' => '考え方メモは理解モードでのみ保存できます。',
                ]);
            }
            if ($run->mode === LearningRun::MODE_UNDERSTANDING && $reasoning !== '') {
                $normalized['payload']['reasoning'] = $reasoning;
            }

            $existing = $item->answer;
            if ($existing) {
                // Old single-choice rows have no payload. Never rewrite them.
                $identical = $existing->answer_payload !== null
                    ? $existing->answer_payload === $normalized['payload']
                    : $normalized['payload']['type'] === 'single_choice'
                        && $existing->answer_value === $normalized['stored_value']
                        && ! array_key_exists('reasoning', $normalized['payload']);
                abort_unless($identical, 409);
                return; // identical retry, even with a different request UUID
            }
            abort_unless($run->status === LearningRun::STATUS_ACTIVE, 409);
            abort_unless(! LearningAnswerEvent::where('request_id', $input['request_id'])->exists(), 409);

            $correct = $grader->gradeRule(
                $item->grading_rule_snapshot ?? [],
                $normalized['graded_value'],
            );
            abort_unless($correct !== null, 409); // no fabricated grade
            $elapsed = $item->presented_at
                ? min(3600000, max(0, (int) $item->presented_at->diffInMilliseconds(now())))
                : null;

            LearningAnswerEvent::create([
                'learning_run_item_id' => $item->id,
                'request_id' => $input['request_id'],
                'answer_value' => $normalized['stored_value'],
                'answer_payload' => $normalized['payload'],
                'was_correct' => $correct,
                'grading_method' => $normalized['grading_method'],
                'answered_at' => now(),
                'elapsed_ms' => $elapsed,
                // Learning inference is intentionally separate from grading.
                'evaluation_contribution' => null,
                'evaluation_confidence' => null,
            ]);
            // Re-rank only FUTURE candidates; persisted locked items stay fixed.
            $candidates->refresh($run);
        });

        $drafts->forgetItem($request, $learningRun, (int) $input['learning_run_item_id']);
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
        BehaviorIdentityService $identity, AdaptiveLearningAnswerDraftService $drafts)
    {
        $this->authorizeStudy($request, $plan, $task, $ownership, $profiles);
        DB::transaction(function () use ($request, $plan, $task, $learningRun, $identity) {
            $run = $this->actorRuns($request, $plan, $task, $identity)
                ->whereKey($learningRun->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->mode, [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE], true), 404);
            if ($run->status === LearningRun::STATUS_COMPLETED) return;
            $run->update(['status' => LearningRun::STATUS_COMPLETED, 'completed_at' => now()]);
        });

        $drafts->forgetRun($request, $learningRun);
        return redirect()->route('plans.tasks.learning.show', [$plan, $task, $learningRun]);
    }
}
