<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Services\AiCapacityService;
use App\Services\AiJsonInputNormalizer;
use App\Services\BehaviorIdentityService;
use App\Services\EvidenceProgressService;
use App\Services\FeatureAccessService;
use App\Services\NativeAiGateway;
use App\Services\PlanOwnershipService;
use App\Services\PlanCategoryProfileService;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Services\PracticeQuestionDemandRecorder;
use App\Services\StudyPracticeOrchestrator;
use App\Services\StudyPracticePromptService;
use App\Services\StudyPracticeReliabilityService;
use App\Services\StudyPracticeStrategyService;
use App\Services\StudyActivityPolicyService;
use App\Services\StudyTaskProgressionService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StudyPracticeController extends Controller
{
    public function __construct(
        private readonly PlanCategoryProfileService $categoryProfiles,
        private readonly StudyAdaptiveActionService $studyActions,
    ) {}
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyPracticeOrchestrator $orchestrator,
        StudyTaskProgressionService $progressionService,
        StudyActivityPolicyService $studyActivityPolicy,
        StudyPracticeReliabilityService $reliabilityService,
        BehaviorIdentityService $identity,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
        AiCapacityService $aiCapacity,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);
        if (! empty($state['questions']) && collect($state['questions'])->contains(
            fn ($question) => is_array($question) && empty($question['response_fields'])
        )) {
            $state['questions'] = $this->normalizeQuestions($state['questions']);
            $request->session()->put($key, $state);
        }

        $actorToken = $identity->resolve($request);
        $attemptQuery = $this->attemptQuery($request, $plan, $task, $actorToken);
        $historyLimit = max(4, (int) config('study.exam_convergence.history_attempt_limit', 16));
        $recentAttempts = (clone $attemptQuery)->latest('created_at')->latest('id')->take($historyLimit)->get();
        $currentAttempt = ! empty($state['attempt_id'])
            ? (clone $attemptQuery)->whereKey((int) $state['attempt_id'])->first()
            : null;
        $currentPracticeSession = ! empty($state['practice_session_id'])
            ? $this->practiceSessionQuery($request, $plan, $task, $actorToken)
                ->whereKey((int) $state['practice_session_id'])
                ->first()
            : null;
        $resumeMode = $request->boolean('resume');

        // Answering resume is a distinct fast path. Do not make the learner
        // traverse the new-practice setup flow or recalculate selection.
        if (! $resumeMode) {
            $resumableSession = $this->resumablePracticeSession(
                $request,
                $plan,
                $task,
                $actorToken,
                $currentPracticeSession,
            );

            if ($resumableSession) {
                return redirect()->route(
                    'plans.tasks.study_practice.resume',
                    [$plan, $task],
                );
            }
        }

        // Recover handoff/result stages from durable StudyPracticeSession state.
        // V40.7.1 covered answer drafts; V40.7.3 also restores the handoff/result
        // stages so a duplicate/reloaded GET cannot make a successful assessment
        // look as if "nothing happened".
        if (! $currentPracticeSession && empty($state['questions'])) {
            $currentPracticeSession = $this->practiceSessionQuery($request, $plan, $task, $actorToken)
                ->whereIn('status', [
                    StudyPracticeSession::STATUS_ANSWERED,
                    StudyPracticeSession::STATUS_ASSESSED,
                ])
                ->whereNotNull('questions_snapshot')
                ->latest('updated_at')
                ->latest('id')
                ->first();

            if (
                $currentPracticeSession
                && is_array($currentPracticeSession->questions_snapshot)
                && $currentPracticeSession->questions_snapshot !== []
            ) {
                $recoveredAttempt = $currentPracticeSession->status === StudyPracticeSession::STATUS_ASSESSED
                    ? (clone $attemptQuery)
                        ->where('study_practice_session_id', $currentPracticeSession->id)
                        ->latest('created_at')
                        ->latest('id')
                        ->first()
                    : null;

                $state = [
                    'title' => $currentPracticeSession->exercise_title ?: 'AI演習',
                    'questions' => $currentPracticeSession->questions_snapshot,
                    'answers' => $recoveredAttempt && is_array($recoveredAttempt->answers)
                        ? $recoveredAttempt->answers
                        : $this->answersFromDraft(
                            $currentPracticeSession->questions_snapshot,
                            is_array($currentPracticeSession->draft_answers)
                                ? $currentPracticeSession->draft_answers
                                : [],
                        ),
                    'draft_answers' => is_array($currentPracticeSession->draft_answers)
                        ? $currentPracticeSession->draft_answers
                        : [],
                    'evaluation_prompt' => $currentPracticeSession->status === StudyPracticeSession::STATUS_ANSWERED
                        ? (string) data_get($currentPracticeSession->assessment_payload, 'evaluation_prompt', '')
                        : null,
                    'assessment' => $recoveredAttempt && is_array($recoveredAttempt->assessment)
                        ? $recoveredAttempt->assessment
                        : null,
                    'attempt_id' => $recoveredAttempt?->id,
                    'attempt_token' => (string) Str::uuid(),
                    'practice_session_id' => $currentPracticeSession->id,
                ];
                $currentAttempt = $recoveredAttempt;
                $request->session()->put($key, $state);
            }
        }

        if (
            $currentPracticeSession
            && $currentPracticeSession->status === StudyPracticeSession::STATUS_ASSESSED
            && empty($state['assessment'])
        ) {
            $currentAttempt ??= (clone $attemptQuery)
                ->where('study_practice_session_id', $currentPracticeSession->id)
                ->latest('created_at')
                ->latest('id')
                ->first();

            if ($currentAttempt && is_array($currentAttempt->assessment)) {
                $state['assessment'] = $currentAttempt->assessment;
                $state['attempt_id'] = $currentAttempt->id;
                if (empty($state['answers']) && is_array($currentAttempt->answers)) {
                    $state['answers'] = $currentAttempt->answers;
                }
                $request->session()->put($key, $state);
            }
        }

        if (
            $currentPracticeSession
            && $currentPracticeSession->status === StudyPracticeSession::STATUS_ANSWERED
            && empty($state['evaluation_prompt'])
        ) {
            $evaluationPrompt = (string) data_get(
                $currentPracticeSession->assessment_payload,
                'evaluation_prompt',
                ''
            );
            if ($evaluationPrompt !== '') {
                $state['evaluation_prompt'] = $evaluationPrompt;
                $request->session()->put($key, $state);
            }
        }

        $questionPackAllowed = $featureAccess->canUse(
            $request->user(),
            FeatureKey::QuestionPack,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );
        $nativeAiDecision = $featureAccess->resolveAccess(
            $request->user(),
            FeatureKey::AutomaticAiExecution,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );
        $nativeAiEntitled = $nativeAiDecision->allowed;
        $nativeAiAvailable = $nativeAiEntitled && $nativeAi->isConfigured();
        $nativeAiCapacity = $aiCapacity->policyFor($request->user());

        $resumeFastPath = $resumeMode
            && $currentPracticeSession
            && in_array($currentPracticeSession->status, [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
            ], true)
            && $this->hasQuestionSnapshot($currentPracticeSession);

        // New-practice setup owns orchestration preview. Existing Sessions own
        // their already-selected strategy/provider and must not be re-routed.
        $orchestration = $currentPracticeSession
            ? null
            : $orchestrator->previewHandoff(
                $plan,
                $task,
                $recentAttempts,
                $questionPackAllowed ? null : 'external_ai',
            );

        $storedStrategy = $currentPracticeSession
            ? data_get($currentPracticeSession->selection_context, 'strategy')
            : null;
        $practiceStrategy = is_array($storedStrategy) && $storedStrategy !== []
            ? $storedStrategy
            : (
                $currentPracticeSession
                    ? [
                        'key' => $currentPracticeSession->strategy ?: 'existing_practice',
                        'label' => '既存の演習',
                        'reason' => 'このSessionで確定済みの問題セットを続けます。',
                        'target_question_count' => count($currentPracticeSession->questions_snapshot ?? []),
                        'learning_phase' => [],
                        'focus_topics' => [],
                        'question_mix' => [],
                        'weakness_priority' => [],
                    ]
                    : (array) ($orchestration['strategy'] ?? [])
            );
        $studyProgression = $progressionService->resolve(
            $plan,
            $task,
            $recentAttempts->take(8)->values(),
            (array) ($practiceStrategy['learning_phase'] ?? []),
        );
        $practiceProvider = $currentPracticeSession
            ? [
                'provider' => $currentPracticeSession->question_provider,
                'mode' => $currentPracticeSession->question_provider_mode,
            ]
            : (array) ($orchestration['provider'] ?? []);
        $generationPrompt = $currentPracticeSession
            ? (string) data_get($currentPracticeSession->provider_payload, 'generation_prompt', '')
            : (string) data_get($orchestration, 'provider.payload.generation_prompt', '');
        $studyActivity = $studyActivityPolicy->forPlanTask($plan, $task);
        $practiceReliability = $reliabilityService->evaluate(
            $studyActivity,
            $practiceProvider,
            $currentPracticeSession,
            $practiceStrategy,
        );
        $prepareRequestId = old('prepare_request_id')
            ?: ($currentPracticeSession?->prepare_request_id ?? (string) Str::uuid());
        $draftAnswers = $this->draftAnswersForView($state, $currentPracticeSession);
        $resumeProgress = $resumeFastPath
            ? $this->resumeProgress($state['questions'] ?? [], $draftAnswers)
            : null;
        $assessmentForView = is_array($state['assessment'] ?? null) ? $state['assessment'] : null;

        if ($assessmentForView && ! is_array($assessmentForView['next_step'] ?? null)) {
            $assessmentForView['next_step'] = $this->normalizeNextStep(null, $task, $assessmentForView);
            if (! filled($assessmentForView['next_action'] ?? null)) {
                $assessmentForView['next_action'] = $assessmentForView['next_step']['label'];
            }
            $state['assessment'] = $assessmentForView;
            $request->session()->put($key, $state);
        }

        $practiceStage = match (true) {
            $assessmentForView !== null => 'result',
            filled($state['evaluation_prompt'] ?? null) => 'evaluation',
            ! empty($state['questions']) => 'answering',
            default => 'setup',
        };

        return view('study_practice.show', [
            'plan' => $plan,
            'task' => $task,
            'generationPrompt' => $generationPrompt,
            'practiceStrategy' => $practiceStrategy,
            'practiceProvider' => $practiceProvider,
            'currentPracticeSession' => $currentPracticeSession,
            'prepareRequestId' => $prepareRequestId,
            'exerciseTitle' => $state['title'] ?? null,
            'questions' => $state['questions'] ?? [],
            'answers' => $state['answers'] ?? [],
            'draftAnswers' => $draftAnswers,
            'evaluationPrompt' => $state['evaluation_prompt'] ?? null,
            'assessment' => $assessmentForView,
            'nextStep' => $assessmentForView['next_step'] ?? null,
            'studyProgression' => $studyProgression,
            'studyActivity' => $studyActivity,
            'practiceReliability' => $practiceReliability,
            'practiceStage' => $practiceStage,
            'currentAttempt' => $currentAttempt,
            'recentAttempts' => $recentAttempts,
            'nativeAiEntitled' => $nativeAiEntitled,
            'nativeAiAvailable' => $nativeAiAvailable,
            'nativeAiConfigured' => $nativeAi->isConfigured(),
            'nativeAiCapacity' => $nativeAiCapacity,
            'resumeFastPath' => $resumeFastPath,
            'resumeProgress' => $resumeProgress,
            'studyPracticeScrollTo' => $resumeFastPath
                ? 'practice-questions'
                : session('study_practice_scroll_to'),
        ]);
    }

    public function resume(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $actorToken = $identity->resolve($request);
        $practiceSession = $this->resumablePracticeSession(
            $request,
            $plan,
            $task,
            $actorToken,
        );

        if (! $practiceSession || ! $this->hasQuestionSnapshot($practiceSession)) {
            return redirect()
                ->route('plans.tasks.study_practice.show', [$plan, $task])
                ->with('status', '再開できる途中の演習はありません。新しい演習を準備できます。');
        }

        $questions = $practiceSession->questions_snapshot;
        $draftAnswers = is_array($practiceSession->draft_answers)
            ? $practiceSession->draft_answers
            : [];
        $existingState = $request->session()->get(
            $this->sessionKey($plan, $task),
            [],
        );

        $request->session()->put($this->sessionKey($plan, $task), [
            'title' => $practiceSession->exercise_title ?: 'AI演習',
            'questions' => $questions,
            'answers' => $this->answersFromDraft($questions, $draftAnswers),
            'draft_answers' => $draftAnswers,
            'evaluation_prompt' => null,
            'assessment' => null,
            'attempt_id' => null,
            'attempt_token' => (
                (int) ($existingState['practice_session_id'] ?? 0)
                    === (int) $practiceSession->id
                && filled($existingState['attempt_token'] ?? null)
            )
                ? (string) $existingState['attempt_token']
                : (string) Str::uuid(),
            'practice_session_id' => $practiceSession->id,
        ]);

        return redirect()
            ->route('plans.tasks.study_practice.show', [
                $plan,
                $task,
                'resume' => 1,
            ]);
    }

    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyPracticeOrchestrator $orchestrator,
        BehaviorIdentityService $identity,
        FeatureAccessService $featureAccess,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);
        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::QuestionPack,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

        $validated = $request->validate([
            'prepare_request_id' => ['required', 'uuid'],
        ]);

        $actorToken = $identity->resolve($request);
        $attemptQuery = $this->attemptQuery($request, $plan, $task, $actorToken);
        $historyLimit = max(4, (int) config('study.exam_convergence.history_attempt_limit', 16));
        $recentAttempts = (clone $attemptQuery)->latest('created_at')->latest('id')->take($historyLimit)->get();

        try {
            $practiceSession = $orchestrator->prepare(
                $plan,
                $task,
                $recentAttempts,
                $request->user()?->id,
                $request->user() ? null : $actorToken,
                (string) $validated['prepare_request_id'],
                'question_bank',
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'prepare_request_id' => $exception->getMessage(),
            ]);
        }

        if ($practiceSession->question_provider_mode !== 'direct') {
            throw ValidationException::withMessages([
                'prepare_request_id' => 'Question Bankの準備状態が変わりました。画面を再読み込みして演習方法を確認してください。',
            ]);
        }

        $questions = data_get($practiceSession->provider_payload, 'questions', []);
        if (! is_array($questions) || $questions === []) {
            throw ValidationException::withMessages([
                'prepare_request_id' => 'Question Bankから問題を準備できませんでした。',
            ]);
        }

        $title = trim((string) data_get(
            $practiceSession->provider_payload,
            'title',
            'Canovia Question Bank演習',
        ));
        $exerciseTitle = $title !== '' ? mb_substr($title, 0, 120) : 'Canovia Question Bank演習';

        $practiceSession->update([
            'exercise_title' => $exerciseTitle,
            'questions_snapshot' => $questions,
        ]);

        $request->session()->put($this->sessionKey($plan, $task), [
            'title' => $exerciseTitle,
            'questions' => $questions,
            'answers' => [],
            'draft_answers' => is_array($practiceSession->draft_answers) ? $practiceSession->draft_answers : [],
            'evaluation_prompt' => null,
            'assessment' => null,
            'attempt_id' => null,
            'attempt_token' => (string) Str::uuid(),
            'practice_session_id' => $practiceSession->id,
        ]);

        return redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with('success', count($questions).'問をCanovia Question Bankから準備しました。')
            ->with('study_practice_scroll_to', 'practice-questions');
    }

    public function prepareNative(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyPracticeOrchestrator $orchestrator,
        BehaviorIdentityService $identity,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::AutomaticAiExecution,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

        $validated = $request->validate([
            'prepare_request_id' => ['required', 'uuid'],
        ]);

        $actorToken = $identity->resolve($request);
        $attemptQuery = $this->attemptQuery($request, $plan, $task, $actorToken);
        $historyLimit = max(4, (int) config('study.exam_convergence.history_attempt_limit', 16));
        $recentAttempts = (clone $attemptQuery)->latest('created_at')->latest('id')->take($historyLimit)->get();

        try {
            $practiceSession = $orchestrator->prepare(
                $plan,
                $task,
                $recentAttempts,
                $request->user()?->id,
                $request->user() ? null : $actorToken,
                (string) $validated['prepare_request_id'],
                'hybrid_ai',
            );
        } catch (NativeAiExecutionException $exception) {
            return redirect()
                ->route('plans.tasks.study_practice.show', [$plan, $task])
                ->with('status', $exception->getMessage().' 外部AIの手動フローはそのまま利用できます。')
                ->with('native_ai_fallback', true);
        }

        $runId = (int) data_get($practiceSession->provider_payload, 'native_ai.run_id', 0);

        try {
            $questions = $this->normalizeQuestions($practiceSession->questions_snapshot);
        } catch (ValidationException $exception) {
            $practiceSession->update(['status' => StudyPracticeSession::STATUS_ABANDONED]);
            if ($runId > 0) {
                $nativeAi->markRunFailed(
                    $runId,
                    'native_ai_validation_failed',
                    collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                );
            }

            return redirect()
                ->route('plans.tasks.study_practice.show', [$plan, $task])
                ->with('status', '演習セットを安全に読み込めなかったため、外部AIの手動フローへ切り替えました。')
                ->with('native_ai_fallback', true);
        }

        $title = trim((string) data_get($practiceSession->provider_payload, 'title', 'Canovia Hybrid演習'));
        $exerciseTitle = $title !== '' ? mb_substr($title, 0, 120) : 'Canovia Hybrid演習';

        $practiceSession->update([
            'status' => StudyPracticeSession::STATUS_READY,
            'exercise_title' => $exerciseTitle,
            'questions_snapshot' => $questions,
        ]);

        if ($runId > 0) {
            $nativeAi->attachRun($runId, $request->user()?->id, $practiceSession);
        }

        $request->session()->put($this->sessionKey($plan, $task), [
            'title' => $exerciseTitle,
            'questions' => $questions,
            'answers' => [],
            'draft_answers' => [],
            'evaluation_prompt' => null,
            'assessment' => null,
            'attempt_id' => null,
            'attempt_token' => (string) Str::uuid(),
            'practice_session_id' => $practiceSession->id,
        ]);

        $bankCount = (int) data_get($practiceSession->provider_payload, 'source_mix.bank_selected_count', 0);
        $nativeCount = (int) data_get($practiceSession->provider_payload, 'source_mix.native_generated_count', 0);
        $successMessage = match (true) {
            $bankCount > 0 && $nativeCount > 0 => count($questions)."問を準備しました（Question Bank {$bankCount}問 + Native AI {$nativeCount}問）。",
            $bankCount > 0 => count($questions).'問をQuestion Bankから準備しました。Native AI生成は不要でした。',
            default => count($questions).'問をCanovia Native AIで準備しました。',
        };

        return redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with('success', $successMessage)
            ->with('study_practice_scroll_to', 'practice-questions');
    }

    public function import(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        AiJsonInputNormalizer $normalizer,
        StudyPracticeOrchestrator $orchestrator,
        BehaviorIdentityService $identity,
        PracticeQuestionDemandRecorder $demandRecorder,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $validated = $request->validate([
            'questions_json' => ['required', 'string', 'max:120000'],
            'prepare_request_id' => ['nullable', 'uuid'],
        ]);

        try {
            $json = $normalizer->normalize($validated['questions_json']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['questions_json' => $exception->getMessage()]);
        }

        $decoded = json_decode($json, true);
        $this->assertEnvelope($decoded, 'study_practice', $plan, $task, 'questions_json');

        $questions = $this->normalizeQuestions($decoded['questions'] ?? null);
        $title = trim((string) ($decoded['title'] ?? 'AI演習'));
        $exerciseTitle = $title !== '' ? mb_substr($title, 0, 120) : 'AI演習';

        $actorToken = $identity->resolve($request);
        $attemptQuery = $this->attemptQuery($request, $plan, $task, $actorToken);
        $historyLimit = max(4, (int) config('study.exam_convergence.history_attempt_limit', 16));
        $recentAttempts = (clone $attemptQuery)->latest('created_at')->latest('id')->take($historyLimit)->get();
        $practiceSession = $orchestrator->prepare(
            $plan,
            $task,
            $recentAttempts,
            $request->user()?->id,
            $request->user() ? null : $actorToken,
            (string) ($validated['prepare_request_id'] ?? Str::uuid()),
            'external_ai',
        );

        $practiceSession->update([
            'status' => StudyPracticeSession::STATUS_READY,
            'exercise_title' => $exerciseTitle,
            'selected_questions' => collect($questions)->map(fn (array $question) => [
                'question_ref' => (string) $question['id'],
                'question_id' => null,
                'source_type' => (string) $practiceSession->question_provider,
            ])->values()->all(),
            'questions_snapshot' => $questions,
            'draft_answers' => null,
            'draft_saved_at' => null,
        ]);

        $demandRecorder->record(
            $practiceSession->fresh(),
            $plan,
            $task,
            (array) data_get($practiceSession->selection_context, 'strategy', []),
            [
                'provider' => (string) $practiceSession->question_provider,
                'questions' => $questions,
                'payload' => is_array($practiceSession->provider_payload)
                    ? $practiceSession->provider_payload
                    : [],
            ],
        );

        $request->session()->put($this->sessionKey($plan, $task), [
            'title' => $exerciseTitle,
            'questions' => $questions,
            'answers' => [],
            'draft_answers' => [],
            'evaluation_prompt' => null,
            'assessment' => null,
            'attempt_id' => null,
            'attempt_token' => (string) Str::uuid(),
            'practice_session_id' => $practiceSession->id,
        ]);

        return redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with('success', count($questions).'問の演習を読み込みました。Canovia上で回答できます。')
            ->with('study_practice_scroll_to', 'practice-questions');
    }

    public function saveDraft(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $validated = $request->validate([
            'practice_session_id' => ['required', 'integer', 'min:1'],
            'answers_json' => ['required', 'string', 'max:120000'],
        ]);

        $decoded = json_decode((string) $validated['answers_json'], true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'answers_json' => '途中回答を保存できませんでした。',
            ]);
        }

        $actorToken = $identity->resolve($request);
        $practiceSession = $this->practiceSessionQuery($request, $plan, $task, $actorToken)
            ->whereKey((int) $validated['practice_session_id'])
            ->whereIn('status', [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
            ])
            ->first();

        if (! $practiceSession) {
            return response()->noContent(409);
        }

        $questions = $practiceSession->questions_snapshot;
        if (! is_array($questions) || $questions === []) {
            $state = $request->session()->get($this->sessionKey($plan, $task), []);
            $questions = $state['questions'] ?? [];
        }

        if (! is_array($questions) || $questions === []) {
            return response()->noContent(409);
        }

        $draftAnswers = $this->normalizeDraftAnswers($decoded, $questions);
        $practiceSession->update([
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'questions_snapshot' => $questions,
            'draft_answers' => $draftAnswers,
            'draft_saved_at' => now(),
        ]);

        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);
        if ((int) ($state['practice_session_id'] ?? 0) === (int) $practiceSession->id) {
            $state['draft_answers'] = $draftAnswers;
            $request->session()->put($key, $state);
        }

        return response()->noContent();
    }

    public function submitAnswers(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyPracticePromptService $promptService,
        StudyPracticeOrchestrator $orchestrator,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidenceService,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);
        $questions = $state['questions'] ?? [];

        if (! is_array($questions) || $questions === []) {
            return redirect()->route('plans.tasks.study_practice.show', [$plan, $task])
                ->withErrors(['answers' => '先に問題JSONを読み込んでください。']);
        }

        $rawAnswers = $request->input('answers', []);
        $draftAnswers = $this->normalizeDraftAnswers($rawAnswers, $questions);
        $answers = [];

        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            $questionInput = $rawAnswers[$questionId] ?? [];

            // Backward compatibility for old forms/tests that posted
            // answers[q1]=value instead of answers[q1][answer]=value.
            if (! is_array($questionInput)) {
                $questionInput = ['answer' => $questionInput];
            }

            $responseFields = $question['response_fields'] ?? [];
            if (! is_array($responseFields) || $responseFields === []) {
                $legacyType = (string) ($question['type'] ?? 'text');
                $responseFields = [[
                    'id' => 'answer',
                    'type' => match ($legacyType) {
                        'single_choice' => 'single_choice',
                        'multiple_choice' => 'multiple_choice',
                        'number' => 'number',
                        default => 'textarea',
                    },
                    'label' => '回答',
                    'required' => true,
                    'choices' => $question['choices'] ?? [],
                ]];
            }

            $fields = [];
            foreach ($responseFields as $field) {
                $fieldId = (string) $field['id'];
                $type = (string) $field['type'];
                $required = (bool) ($field['required'] ?? true);
                $value = $questionInput[$fieldId] ?? null;

                if ($type === 'multiple_choice') {
                    $value = is_array($value)
                        ? array_values(array_unique(array_map('strval', $value)))
                        : [];

                    $allowed = collect($field['choices'] ?? [])->pluck('id')->map('strval')->all();
                    $value = array_values(array_filter($value, fn ($item) => in_array($item, $allowed, true)));

                    if ($required && $value === []) {
                        throw ValidationException::withMessages([
                            "answers.{$questionId}.{$fieldId}" => ($field['label'] ?? '回答').'を入力してください。',
                        ]);
                    }
                } else {
                    $value = is_scalar($value) ? trim((string) $value) : '';

                    if ($required && $value === '') {
                        throw ValidationException::withMessages([
                            "answers.{$questionId}.{$fieldId}" => ($field['label'] ?? '回答').'を入力してください。',
                        ]);
                    }

                    if ($type === 'single_choice' && $value !== '') {
                        $allowed = collect($field['choices'] ?? [])->pluck('id')->map('strval')->all();
                        if (! in_array($value, $allowed, true)) {
                            throw ValidationException::withMessages([
                                "answers.{$questionId}.{$fieldId}" => '選択肢をもう一度選んでください。',
                            ]);
                        }
                    }

                    if ($type === 'number' && $value !== '' && ! is_numeric($value)) {
                        throw ValidationException::withMessages([
                            "answers.{$questionId}.{$fieldId}" => '数値で回答してください。',
                        ]);
                    }

                    $value = mb_substr($value, 0, $type === 'textarea' ? 12000 : 3000);
                }

                $fields[] = [
                    'field_id' => $fieldId,
                    'type' => $type,
                    'label' => $field['label'] ?? $fieldId,
                    'value' => $value,
                ];
            }

            $answers[] = [
                'question_id' => $questionId,
                'fields' => $fields,
            ];
        }

        $state['answers'] = $answers;
        $state['draft_answers'] = $draftAnswers;
        $actorToken = $identity->resolve($request);
        $practiceSession = ! empty($state['practice_session_id'])
            ? $this->practiceSessionQuery($request, $plan, $task, $actorToken)
                ->whereKey((int) $state['practice_session_id'])
                ->first()
            : null;

        if ($practiceSession) {
            $practiceSession->update([
                'draft_answers' => $draftAnswers,
                'draft_saved_at' => now(),
            ]);

            $nativeFallback = false;
            $assessmentProviderKey = null;

            $practiceUsesNativeAssessment =
                (string) $practiceSession->question_provider === 'native_ai'
                || (
                    (string) $practiceSession->question_provider === 'hybrid_ai'
                    && (int) data_get($practiceSession->provider_payload, 'source_mix.native_generated_count', 0) > 0
                );

            if ($practiceUsesNativeAssessment) {
                $nativeAllowed = $featureAccess->canUse(
                    $request->user(),
                    FeatureKey::AutomaticAiExecution,
                    ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
                );

                if ($nativeAllowed && $nativeAi->isConfigured()) {
                    $assessmentProviderKey = 'native_ai';
                } else {
                    $assessmentProviderKey = 'external_ai';
                    $nativeFallback = true;
                }
            }

            try {
                $assessmentHandoff = $orchestrator->prepareAssessment(
                    $practiceSession,
                    $plan,
                    $task,
                    $questions,
                    $answers,
                    $assessmentProviderKey,
                );
            } catch (NativeAiExecutionException $exception) {
                $assessmentHandoff = $orchestrator->prepareAssessment(
                    $practiceSession,
                    $plan,
                    $task,
                    $questions,
                    $answers,
                    'external_ai',
                );
                $nativeFallback = true;
            }

            if (
                (string) ($assessmentHandoff['mode'] ?? '') === 'direct'
                && is_array(data_get($assessmentHandoff, 'payload.assessment_envelope'))
            ) {
                try {
                    $assessment = $this->normalizeAssessmentEnvelope(
                        data_get($assessmentHandoff, 'payload.assessment_envelope'),
                        $plan,
                        $task,
                        $questions,
                        'answers',
                    );
                } catch (ValidationException $exception) {
                    $runId = (int) data_get($assessmentHandoff, 'payload.native_ai.run_id', 0);
                    if ($runId > 0) {
                        $nativeAi->markRunFailed(
                            $runId,
                            'native_ai_validation_failed',
                            collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                        );
                    }

                    $assessmentHandoff = $orchestrator->prepareAssessment(
                        $practiceSession,
                        $plan,
                        $task,
                        $questions,
                        $answers,
                        'external_ai',
                    );
                    $nativeFallback = true;
                    $assessment = null;
                }
            } else {
                $assessment = data_get($assessmentHandoff, 'payload.assessment');
            }

            if ((string) ($assessmentHandoff['mode'] ?? '') === 'direct') {
                if (! is_array($assessment)) {
                    throw ValidationException::withMessages([
                        'answers' => 'Canoviaの採点結果を作成できませんでした。',
                    ]);
                }

                $assessment['next_step'] = $this->normalizeNextStep(
                    $assessment['next_step'] ?? null,
                    $task,
                    $assessment,
                );
                if (! filled($assessment['next_action'] ?? null)) {
                    $assessment['next_action'] = $assessment['next_step']['label'];
                }

                $state['evaluation_prompt'] = null;
                $state['assessment'] = $assessment;
                $attempt = $this->persistAssessment(
                    $request,
                    $plan,
                    $task,
                    $state,
                    $assessment,
                    $actorToken,
                );
                $state['attempt_id'] = $attempt->id;
                $evidenceService->recordStudyPracticeAssessment($attempt);
                $this->studyActions->tryRefresh(
                    $plan,
                    $attempt->created_at ?? now(),
                );
                $practiceSession->update(['status' => StudyPracticeSession::STATUS_ASSESSED]);
                $request->session()->put($key, $state);

                $successMessage = (string) ($assessmentHandoff['provider'] ?? '') === 'native_ai'
                    ? 'Canovia Native AIが回答を評価しました。結果を確認できます。'
                    : 'Canovia Question Bankの採点ルールで評価しました。結果を確認できます。';

                return redirect()
                    ->route('plans.tasks.study_practice.show', [$plan, $task])
                    ->with('success', $successMessage)
                    ->with('study_practice_scroll_to', 'practice-assessment');
            }

            $state['evaluation_prompt'] = (string) data_get(
                $assessmentHandoff,
                'payload.evaluation_prompt',
                ''
            );
            $practiceSession->update(['status' => StudyPracticeSession::STATUS_ANSWERED]);
        } else {
            // V40 compatibility for an in-progress browser session created
            // before StudyPracticeSession existed.
            $state['evaluation_prompt'] = $promptService->evaluationPrompt($plan, $task, $questions, $answers);
        }

        $state['assessment'] = null;
        $state['attempt_id'] = null;
        $request->session()->put($key, $state);

        $redirect = redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with(
                'success',
                isset($nativeFallback) && $nativeFallback
                    ? 'Native AI評価を完了できなかったため、外部AI用の評価プロンプトを準備しました。'
                    : '回答をまとめました。評価用プロンプトをAIへ送ってください。',
            )
            ->with('study_practice_scroll_to', 'practice-evaluation');

        if (isset($nativeFallback) && $nativeFallback) {
            $redirect->with('native_ai_fallback', true);
        }

        return $redirect;
    }

    public function previewAssessment(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        AiJsonInputNormalizer $normalizer,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidenceService,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $validated = $request->validate([
            'assessment_json' => ['required', 'string', 'max:80000'],
        ]);

        try {
            $json = $normalizer->normalize($validated['assessment_json']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['assessment_json' => $exception->getMessage()]);
        }

        $decoded = json_decode($json, true);
        $this->assertEnvelope($decoded, 'study_assessment', $plan, $task, 'assessment_json');

        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);
        $actorToken = $identity->resolve($request);
        $state = $this->recoverAssessmentHandoffState(
            $request,
            $plan,
            $task,
            $state,
            $actorToken,
        );

        if (empty($state['evaluation_prompt']) || empty($state['answers'])) {
            throw ValidationException::withMessages([
                'assessment_json' => '先にCanovia上で問題へ回答し、評価用プロンプトを生成してください。',
            ]);
        }

        // Keep the recovered durable state in the PHP session before persisting
        // the assessment. A redirect/reload after this POST must see the same
        // handoff that was used to build the attempt.
        $request->session()->put($key, $state);

        $assessment = $this->normalizeAssessmentEnvelope(
            $decoded,
            $plan,
            $task,
            $state['questions'] ?? [],
            'assessment_json',
        );

        $attempt = $this->persistAssessment(
            $request,
            $plan,
            $task,
            $state,
            $assessment,
            $actorToken,
        );
        $state['assessment'] = $assessment;
        $state['attempt_id'] = $attempt->id;
        $evidenceService->recordStudyPracticeAssessment($attempt);
                $this->studyActions->tryRefresh(
                    $plan,
                    $attempt->created_at ?? now(),
                );
        $request->session()->put($key, $state);

        if (! empty($state['practice_session_id'])) {
            $this->practiceSessionQuery($request, $plan, $task, $actorToken)
                ->whereKey((int) $state['practice_session_id'])
                ->update(['status' => StudyPracticeSession::STATUS_ASSESSED]);
        }

        return redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with('success', $attempt->wasRecentlyCreated
                ? 'AIの評価を学習履歴へ保存しました。内容を確認してTaskへ反映できます。'
                : '同じ評価はすでに保存済みです。既存の学習履歴を開きました。')
            ->with('study_practice_scroll_to', 'practice-assessment');
    }

    public function applyAssessment(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        EvidenceProgressService $evidenceProgress,
        StudyTaskProgressionService $progressionService,
        StudyPracticeStrategyService $strategyService,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $validated = $request->validate([
            'attempt_id' => ['required', 'integer', 'min:1'],
            'request_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $actorToken = $identity->resolve($request);
        $alreadyApplied = false;
        $progressionDecision = null;

        DB::transaction(function () use ($request, $plan, $task, $validated, $actorToken, $evidenceProgress, $progressionService, $strategyService, &$alreadyApplied, &$progressionDecision) {
            $attempt = $this->attemptQuery($request, $plan, $task, $actorToken)
                ->whereKey((int) $validated['attempt_id'])
                ->where('request_hash', $validated['request_hash'])
                ->lockForUpdate()
                ->first();

            if (! $attempt) {
                throw ValidationException::withMessages([
                    'attempt_id' => 'この学習結果を確認できません。AI演習画面からもう一度開いてください。',
                ]);
            }

            if ($attempt->applied_at) {
                $alreadyApplied = true;

                if ($attempt->study_practice_session_id) {
                    StudyPracticeSession::query()
                        ->whereKey($attempt->study_practice_session_id)
                        ->where('plan_id', $plan->id)
                        ->where('task_id', $task->id)
                        ->update([
                            'status' => StudyPracticeSession::STATUS_COMPLETED,
                            'completed_at' => now(),
                        ]);
                }

                return;
            }

            $lockedTask = Task::query()
                ->where('plan_id', $plan->id)
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTask->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'attempt_id' => 'このTaskは現在中止されています。学習結果を反映するには、先にTask状態を見直してください。',
                ]);
            }

            $progressBefore = (int) $lockedTask->progress_percent;
            $evidence = $lockedTask->evidences()
                ->where('source', 'native')
                ->where('external_key', 'study-practice-attempt:'.$attempt->id)
                ->first();
            $progressAfter = $evidence
                ? $evidenceProgress->recommendPercent($lockedTask, $evidence)
                : null;
            $progressAfter ??= max($progressBefore, (int) $attempt->recommended_task_progress_percent);

            $historyLimit = max(4, (int) config('study.exam_convergence.history_attempt_limit', 16));
            $recentAttempts = $this->attemptQuery($request, $plan, $lockedTask, $actorToken)
                ->latest('created_at')
                ->latest('id')
                ->take($historyLimit)
                ->get();
            $nextStrategy = $strategyService->build(
                $plan,
                $lockedTask,
                $recentAttempts,
            );
            $progressionDecision = $progressionService->resolve(
                $plan,
                $lockedTask,
                $recentAttempts->take(8)->values(),
                (array) ($nextStrategy['learning_phase'] ?? []),
            );

            if (($progressionDecision['kind'] ?? null) === 'verify_mastery' && $progressAfter >= 100) {
                $progressAfter = max($progressBefore, 99);
            } elseif (in_array(($progressionDecision['kind'] ?? null), ['advance_task', 'plan_complete'], true)) {
                $progressAfter = 100;
            }

            $remainingAfter = $progressAfter >= 100 ? 0 : $lockedTask->remaining_minutes;
            $statusAfter = match (true) {
                $progressAfter >= 100 => 'done',
                $progressAfter > 0 => 'doing',
                default => $lockedTask->status,
            };
            $reason = trim('AI演習 '.$attempt->score_percent.'%'.(
                $attempt->evidence_summary ? '：'.$attempt->evidence_summary : ''
            ));

            $nextActionAfter = match ($progressionDecision['kind'] ?? null) {
                'verify_mastery' => '完了前の仕上げ確認を行う',
                'general_practice_return' => '総合演習で全体成績を再確認する',
                'exam_mode' => '本番形式の総合演習を進める',
                'advance_task' => '次のTask「'.data_get($progressionDecision, 'next_task.title', '次のTask').'」へ進む',
                'plan_complete' => 'このPlanの学習完了を確認する',
                default => $attempt->next_action ?: $lockedTask->next_action_note,
            };

            $lockedTask->update([
                'progress_percent' => $progressAfter,
                'remaining_minutes' => $remainingAfter,
                'progress_reason' => mb_substr($reason, 0, 4000),
                'next_action_note' => $nextActionAfter,
                'status' => $statusAfter,
            ]);

            $attempt->update([
                'progress_before_percent' => $progressBefore,
                'progress_after_percent' => $progressAfter,
                'applied_at' => now(),
            ]);

            if ($attempt->study_practice_session_id) {
                StudyPracticeSession::query()
                    ->whereKey($attempt->study_practice_session_id)
                    ->where('plan_id', $plan->id)
                    ->where('task_id', $task->id)
                    ->update([
                        'status' => StudyPracticeSession::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
            }
        }, 3);

        if (! is_array($progressionDecision)) {
            $task->refresh();
            $historyLimit = max(4, (int) config('study.exam_convergence.history_attempt_limit', 16));
            $recentAttempts = $this->attemptQuery($request, $plan, $task, $actorToken)
                ->latest('created_at')
                ->latest('id')
                ->take($historyLimit)
                ->get();
            $nextStrategy = $strategyService->build(
                $plan,
                $task,
                $recentAttempts,
            );
            $progressionDecision = $progressionService->resolve(
                $plan,
                $task,
                $recentAttempts->take(8)->values(),
                (array) ($nextStrategy['learning_phase'] ?? []),
            );
        }

        if ($request->boolean('continue_after_apply')) {
            $request->session()->forget($this->sessionKey($plan, $task));

            if (($progressionDecision['kind'] ?? null) === 'verify_mastery') {
                return redirect()
                    ->route('plans.tasks.study_practice.show', [$plan, $task])
                    ->with('status', 'Task完了前の仕上げ確認を行います。別の問題で理解が安定しているか確認します。');
            }

            if (($progressionDecision['kind'] ?? null) === 'reinforce_current') {
                return redirect()
                    ->route('plans.tasks.study_practice.show', [$plan, $task])
                    ->with('status', '前回の結果を引き継いで、弱点補強を続けます。');
            }

            if (($progressionDecision['kind'] ?? null) === 'general_practice_return') {
                return redirect()
                    ->route('plans.tasks.study_practice.show', [$plan, $task])
                    ->with('status', '弱点の集中補完を区切り、総合演習で全体成績を再確認します。');
            }

            if (($progressionDecision['kind'] ?? null) === 'exam_mode') {
                return redirect()
                    ->route('plans.tasks.study_practice.show', [$plan, $task])
                    ->with('status', '試験日が近いため、本番形式の総合演習を優先します。');
            }

            if (($progressionDecision['kind'] ?? null) === 'advance_task' && data_get($progressionDecision, 'next_task.id')) {
                return redirect()
                    ->route('plans.tasks.study_practice.show', [$plan, data_get($progressionDecision, 'next_task.id')])
                    ->with('status', '前のTaskを完了しました。次のTask内容から演習方針を組み立てます。');
            }

            if (($progressionDecision['kind'] ?? null) === 'review_plan') {
                return redirect()
                    ->route('plans.review_assistant.show', $plan)
                    ->with('status', '学習結果を反映しました。計画全体を見直します。');
            }

            return redirect()
                ->route('plans.show', $plan)
                ->with('status', ($progressionDecision['kind'] ?? null) === 'plan_complete'
                    ? 'このPlanで次に実行可能な学習Taskはありません。学習全体を確認してください。'
                    : '学習結果をTaskへ反映しました。次の行動を確認してください。');
        }

        return redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with('success', $alreadyApplied
                ? 'この学習結果はすでにTaskへ反映済みです。重複反映は行いませんでした。'
                : '学習結果をTaskへ反映しました。次にやることへそのまま進めます。')
            ->with('study_practice_scroll_to', 'practice-assessment');
    }

    public function reset(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $this->authorizeStudyPlan($plan);

        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);
        if (! empty($state['practice_session_id'])) {
            $actorToken = $identity->resolve($request);
            $this->practiceSessionQuery($request, $plan, $task, $actorToken)
                ->whereKey((int) $state['practice_session_id'])
                ->whereNotIn('status', [StudyPracticeSession::STATUS_COMPLETED, StudyPracticeSession::STATUS_ABANDONED])
                ->update(['status' => StudyPracticeSession::STATUS_ABANDONED]);
        }

        $continuing = $request->boolean('continue');
        $request->session()->forget($key);

        return redirect()
            ->route('plans.tasks.study_practice.show', [$plan, $task])
            ->with('status', $continuing
                ? '前回の結果を引き継いで、次の演習を準備します。'
                : 'この演習をリセットしました。');
    }

    private function resumablePracticeSession(
        Request $request,
        Plan $plan,
        Task $task,
        string $actorToken,
        ?StudyPracticeSession $current = null,
    ): ?StudyPracticeSession {
        if (
            $current
            && in_array($current->status, [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
            ], true)
            && $this->hasQuestionSnapshot($current)
        ) {
            return $current;
        }

        return $this->practiceSessionQuery(
            $request,
            $plan,
            $task,
            $actorToken,
        )
            ->whereIn('status', [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
            ])
            ->whereNotNull('questions_snapshot')
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->first(fn (StudyPracticeSession $session) =>
                $this->hasQuestionSnapshot($session)
            );
    }

    private function hasQuestionSnapshot(
        StudyPracticeSession $practiceSession,
    ): bool {
        return is_array($practiceSession->questions_snapshot)
            && $practiceSession->questions_snapshot !== [];
    }

    /**
     * @param array<int,array<string,mixed>> $questions
     * @param array<string,array<string,mixed>> $draftAnswers
     * @return array{answered:int,total:int}
     */
    private function resumeProgress(
        array $questions,
        array $draftAnswers,
    ): array {
        $answered = collect($questions)
            ->filter(fn ($question) => is_array($question))
            ->filter(function (array $question) use ($draftAnswers) {
                $questionId = trim((string) ($question['id'] ?? ''));
                if ($questionId === '') {
                    return false;
                }

                $fields = is_array($draftAnswers[$questionId] ?? null)
                    ? $draftAnswers[$questionId]
                    : [];

                foreach ($fields as $value) {
                    if (is_array($value) && $value !== []) {
                        return true;
                    }
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        return [
            'answered' => $answered,
            'total' => count($questions),
        ];
    }

    private function authorizeStudyPlan(Plan $plan): void
    {
        abort_unless($this->categoryProfiles->forPlan($plan)->key === 'study', 404);
    }

    private function authorizeTask(Request $request, Plan $plan, Task $task, PlanOwnershipService $ownership): void
    {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<int,array<string,mixed>> $questions
     * @return array<string,mixed>
     */
    private function normalizeAssessmentEnvelope(
        array $decoded,
        Plan $plan,
        Task $task,
        array $questions,
        string $field,
    ): array {
        $this->assertEnvelope($decoded, 'study_assessment', $plan, $task, $field);

        $score = filter_var($decoded['score_percent'] ?? null, FILTER_VALIDATE_INT);
        $recommendedProgress = filter_var(
            $decoded['recommended_task_progress_percent'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($score === false || $score < 0 || $score > 100) {
            throw ValidationException::withMessages([$field => 'score_percentは0〜100の整数で返してください。']);
        }
        if ($recommendedProgress === false || $recommendedProgress < 0 || $recommendedProgress > 100) {
            throw ValidationException::withMessages([$field => 'recommended_task_progress_percentは0〜100の整数で返してください。']);
        }

        $questionFeedback = $this->normalizeQuestionFeedback(
            $decoded['question_feedback'] ?? [],
            $questions,
        );
        $hasStructuredFeedback = collect($questionFeedback)->contains(
            fn (array $item) =>
                array_key_exists('correctness', $item)
                || array_key_exists('error_type', $item),
        );
        $routingWeaknesses = collect($questionFeedback)
            ->filter(fn (array $item) => in_array(
                (string) ($item['correctness'] ?? ''),
                ['incorrect', 'partial'],
                true,
            ))
            ->flatMap(fn (array $item) => $item['weakness_topics'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $assessment = [
            'score_percent' => $score,
            'question_feedback' => $questionFeedback,
            'strengths' => $this->stringList($decoded['strengths'] ?? []),
            // Modern structured feedback is the routing authority. This keeps
            // prose-only reasoning improvements out of durable weaknesses.
            'weaknesses' => $hasStructuredFeedback
                ? $routingWeaknesses
                : $this->stringList($decoded['weaknesses'] ?? []),
            'recommended_task_progress_percent' => $recommendedProgress,
            'evidence_summary' => mb_substr(trim((string) ($decoded['evidence_summary'] ?? '')), 0, 2000),
            'next_action' => mb_substr(trim((string) ($decoded['next_action'] ?? '')), 0, 1000),
        ];
        $assessment['next_step'] = $this->normalizeNextStep(
            $decoded['next_step'] ?? null,
            $task,
            $assessment,
        );

        if (! filled($assessment['next_action'])) {
            $assessment['next_action'] = $assessment['next_step']['label'];
        }

        return $assessment;
    }

    private function assertEnvelope(?array $decoded, string $flow, Plan $plan, Task $task, string $field): void
    {
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'JSONオブジェクトを読み取れませんでした。']);
        }
        if (($decoded['flow'] ?? null) !== $flow) {
            throw ValidationException::withMessages([$field => "flowは{$flow}である必要があります。"]);
        }
        if ((int) data_get($decoded, 'target_plan.id') !== (int) $plan->id) {
            throw ValidationException::withMessages([$field => '別のPlan向けJSONです。開いているPlanのIDを変更せずAIへ返してください。']);
        }
        if ((int) data_get($decoded, 'target_task.id') !== (int) $task->id) {
            throw ValidationException::withMessages([$field => '別のTask向けJSONです。開いているTaskのIDを変更せずAIへ返してください。']);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, array<string, mixed>>
     */
    private function draftAnswersForView(array $state, ?StudyPracticeSession $practiceSession): array
    {
        if ($practiceSession && is_array($practiceSession->draft_answers)) {
            return $practiceSession->draft_answers;
        }

        if (is_array($state['draft_answers'] ?? null)) {
            return $state['draft_answers'];
        }

        $draft = [];
        foreach (($state['answers'] ?? []) as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $questionId = trim((string) ($answer['question_id'] ?? ''));
            if ($questionId === '') {
                continue;
            }

            if (is_array($answer['fields'] ?? null)) {
                foreach ($answer['fields'] as $field) {
                    if (! is_array($field)) {
                        continue;
                    }

                    $fieldId = trim((string) ($field['field_id'] ?? ''));
                    if ($fieldId !== '') {
                        $draft[$questionId][$fieldId] = $field['value'] ?? '';
                    }
                }
            } elseif (array_key_exists('answer', $answer)) {
                $draft[$questionId]['answer'] = $answer['answer'];
            }
        }

        return $draft;
    }

    /**
     * Rebuild the normalized answer snapshot from durable draft answers.
     * This is used only for recovery after the browser/PHP session is lost.
     *
     * @param array<int, array<string, mixed>> $questions
     * @param array<string, array<string, mixed>> $draftAnswers
     * @return array<int, array<string, mixed>>
     */
    private function answersFromDraft(array $questions, array $draftAnswers): array
    {
        $answers = [];

        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }

            $questionId = trim((string) ($question['id'] ?? ''));
            if ($questionId === '') {
                continue;
            }

            $questionDraft = is_array($draftAnswers[$questionId] ?? null)
                ? $draftAnswers[$questionId]
                : [];
            $fields = [];

            foreach (($question['response_fields'] ?? []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldId = trim((string) ($field['id'] ?? ''));
                if ($fieldId === '') {
                    continue;
                }

                $type = (string) ($field['type'] ?? 'textarea');
                $value = $questionDraft[$fieldId] ?? ($type === 'multiple_choice' ? [] : '');

                if ($type === 'multiple_choice') {
                    $value = is_array($value) ? array_values(array_map('strval', $value)) : [];
                } else {
                    $value = is_scalar($value) ? (string) $value : '';
                }

                $fields[] = [
                    'field_id' => $fieldId,
                    'type' => $type,
                    'label' => $field['label'] ?? $fieldId,
                    'value' => $value,
                ];
            }

            $answers[] = [
                'question_id' => $questionId,
                'fields' => $fields,
            ];
        }

        return $answers;
    }

    /**
     * Keep partial answers safe without requiring the learner to have completed
     * every required field. Final validation still happens in submitAnswers().
     *
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, array<string, mixed>>
     */
    private function normalizeDraftAnswers(mixed $raw, array $questions): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $draft = [];

        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }

            $questionId = (string) ($question['id'] ?? '');
            if ($questionId === '' || ! array_key_exists($questionId, $raw)) {
                continue;
            }

            $questionInput = $raw[$questionId];
            if (! is_array($questionInput)) {
                $questionInput = ['answer' => $questionInput];
            }

            foreach (($question['response_fields'] ?? []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $fieldId = (string) ($field['id'] ?? '');
                $type = (string) ($field['type'] ?? 'textarea');
                if ($fieldId === '' || ! array_key_exists($fieldId, $questionInput)) {
                    continue;
                }

                $value = $questionInput[$fieldId];

                if ($type === 'multiple_choice') {
                    $allowed = collect($field['choices'] ?? [])->pluck('id')->map('strval')->all();
                    $values = is_array($value) ? array_map('strval', $value) : [];
                    $draft[$questionId][$fieldId] = array_values(array_unique(
                        array_filter($values, fn ($item) => in_array($item, $allowed, true))
                    ));
                    continue;
                }

                $value = is_scalar($value) ? (string) $value : '';

                if ($type === 'single_choice') {
                    $allowed = collect($field['choices'] ?? [])->pluck('id')->map('strval')->all();
                    $value = in_array($value, $allowed, true) ? $value : '';
                }

                $draft[$questionId][$fieldId] = mb_substr(
                    $value,
                    0,
                    $type === 'textarea' ? 12000 : 3000,
                );
            }
        }

        return $draft;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeQuestions(mixed $raw): array
    {
        if (! is_array($raw) || $raw === [] || count($raw) > 20) {
            throw ValidationException::withMessages(['questions_json' => 'questionsは1〜20問で返してください。']);
        }

        $questions = [];
        $seen = [];

        foreach (array_values($raw) as $index => $question) {
            if (! is_array($question)) {
                throw ValidationException::withMessages(['questions_json' => '各questionはJSONオブジェクトで返してください。']);
            }

            $id = trim((string) ($question['id'] ?? 'q'.($index + 1)));
            $prompt = trim((string) ($question['prompt'] ?? ''));

            if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || isset($seen[$id])) {
                throw ValidationException::withMessages([
                    'questions_json' => 'question.idは英数字・_・-だけを使い、重複しない64文字以内の値にしてください。',
                ]);
            }
            if ($prompt === '') {
                throw ValidationException::withMessages(['questions_json' => '問題文が空のquestionがあります。']);
            }

            $rawFields = $question['response_fields'] ?? null;

            if (! is_array($rawFields) || $rawFields === []) {
                // V39 compatibility: convert type + choices into one response field.
                $legacyType = trim((string) ($question['type'] ?? ''));
                $legacyMap = [
                    'single_choice' => 'single_choice',
                    'multiple_choice' => 'multiple_choice',
                    'number' => 'number',
                    'text' => 'textarea',
                ];

                if (! isset($legacyMap[$legacyType])) {
                    throw ValidationException::withMessages([
                        'questions_json' => 'response_fieldsを指定するか、互換形式のquestion.typeを使用してください。',
                    ]);
                }

                $rawFields = [[
                    'id' => 'answer',
                    'type' => $legacyMap[$legacyType],
                    'label' => '回答',
                    'required' => true,
                    'choices' => $question['choices'] ?? [],
                ]];
            }

            $workInput = $this->normalizeWorkInput(
                $question['work_input'] ?? null,
                $rawFields,
            );

            if ($workInput !== 'none' && ! $this->hasDiagnosticTextarea($rawFields)) {
                if (count($rawFields) >= 4) {
                    throw ValidationException::withMessages([
                        'questions_json' => 'work_inputがreasoningまたはcalculationのquestionには、textareaを含めてresponse_fieldsを1〜4件にしてください。',
                    ]);
                }

                $rawFields[] = $this->diagnosticTextareaFor($workInput);
            }

            if (count($rawFields) < 1 || count($rawFields) > 4) {
                throw ValidationException::withMessages([
                    'questions_json' => '各questionのresponse_fieldsは1〜4件にしてください。',
                ]);
            }

            $fields = [];
            $seenFields = [];
            foreach (array_values($rawFields) as $fieldIndex => $field) {
                $fields[] = $this->normalizeResponseField($field, $fieldIndex, $seenFields);
            }

            if (! collect($fields)->contains(fn ($field) => (bool) ($field['required'] ?? false))) {
                throw ValidationException::withMessages([
                    'questions_json' => '各questionには最低1つrequired=trueのresponse_fieldが必要です。',
                ]);
            }

            $seen[$id] = true;
            $first = $fields[0];
            $legacyType = match ($first['type']) {
                'short_text', 'textarea' => 'text',
                default => $first['type'],
            };

            $normalizedQuestion = [
                'id' => mb_substr($id, 0, 64),
                'prompt' => mb_substr($prompt, 0, 4000),
                'work_input' => $workInput,
                'response_fields' => $fields,
                // Compatibility keys remain while old attempts and consumers exist.
                'type' => $legacyType,
                'choices' => $first['choices'] ?? [],
            ];

            if (is_numeric($question['source_question_id'] ?? null)) {
                $normalizedQuestion['source_question_id'] = (int) $question['source_question_id'];
            }
            if (filled($question['source_type'] ?? null)) {
                $normalizedQuestion['source_type'] = mb_substr(trim((string) $question['source_type']), 0, 32);
            }
            if (filled($question['source_reference'] ?? null)) {
                $normalizedQuestion['source_reference'] = mb_substr(trim((string) $question['source_reference']), 0, 500);
            }

            $questions[] = $normalizedQuestion;
        }

        return $questions;
    }

    /**
     * @param array<int, mixed> $rawFields
     */
    private function normalizeWorkInput(mixed $raw, array $rawFields): string
    {
        $value = mb_strtolower(trim((string) ($raw ?? '')));

        if ($value === '') {
            foreach ($rawFields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $id = mb_strtolower(trim((string) ($field['id'] ?? '')));
                $label = mb_strtolower(trim((string) ($field['label'] ?? '')));

                if (
                    str_contains($id, 'calculation')
                    || str_contains($id, 'work')
                    || str_contains($label, '計算')
                    || str_contains($label, '途中')
                ) {
                    return 'calculation';
                }

                if (
                    str_contains($id, 'reasoning')
                    || str_contains($id, 'reason')
                    || str_contains($label, '考え')
                    || str_contains($label, '理由')
                    || str_contains($label, '根拠')
                ) {
                    return 'reasoning';
                }
            }

            return 'none';
        }

        if (! in_array($value, ['none', 'reasoning', 'calculation'], true)) {
            throw ValidationException::withMessages([
                'questions_json' => 'question.work_inputはnone / reasoning / calculationのいずれかにしてください。',
            ]);
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $rawFields
     */
    private function hasDiagnosticTextarea(array $rawFields): bool
    {
        foreach ($rawFields as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'textarea') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticTextareaFor(string $workInput): array
    {
        if ($workInput === 'calculation') {
            return [
                'id' => 'calculation_work',
                'type' => 'textarea',
                'label' => '計算過程',
                'required' => false,
                'placeholder' => '式・途中値・単位変換などを入力',
                'choices' => [],
            ];
        }

        return [
            'id' => 'reasoning',
            'type' => 'textarea',
            'label' => '考え方・判断理由',
            'required' => false,
            'placeholder' => '選んだ根拠や条件整理を入力',
            'choices' => [],
        ];
    }

    /**
     * @param array<string, bool> $seenFields
     * @return array<string, mixed>
     */
    private function normalizeResponseField(mixed $raw, int $index, array &$seenFields): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'questions_json' => 'response_fieldはJSONオブジェクトで返してください。',
            ]);
        }

        $allowed = ['single_choice', 'multiple_choice', 'number', 'short_text', 'textarea'];
        $id = trim((string) ($raw['id'] ?? 'field'.($index + 1)));
        $type = trim((string) ($raw['type'] ?? ''));
        $label = trim((string) ($raw['label'] ?? '回答'));
        $requiredRaw = $raw['required'] ?? true;
        $required = is_bool($requiredRaw)
            ? $requiredRaw
            : ! in_array(mb_strtolower(trim((string) $requiredRaw)), ['false', '0', 'no'], true);

        if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || isset($seenFields[$id])) {
            throw ValidationException::withMessages([
                'questions_json' => 'response_field.idは英数字・_・-だけを使い、question内で重複しない64文字以内の値にしてください。',
            ]);
        }
        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages([
                'questions_json' => 'response_field.typeがCanoviaの対応形式ではありません。',
            ]);
        }
        if ($label === '') {
            $label = '回答';
        }

        $choices = [];
        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $rawChoices = $raw['choices'] ?? [];
            if (! is_array($rawChoices) || count($rawChoices) < 2 || count($rawChoices) > 6) {
                throw ValidationException::withMessages([
                    'questions_json' => '選択式response_fieldのchoicesは2〜6件にしてください。',
                ]);
            }

            $seenChoiceIds = [];
            foreach (array_values($rawChoices) as $choiceIndex => $choice) {
                if (! is_array($choice)) {
                    throw ValidationException::withMessages([
                        'questions_json' => 'choiceはidとlabelを持つJSONオブジェクトにしてください。',
                    ]);
                }

                $choiceId = trim((string) ($choice['id'] ?? chr(65 + $choiceIndex)));
                $choiceLabel = trim((string) ($choice['label'] ?? ''));

                if (
                    $choiceId === ''
                    || preg_match('/^[\p{L}\p{N}_-]{1,20}$/u', $choiceId) !== 1
                    || isset($seenChoiceIds[$choiceId])
                    || $choiceLabel === ''
                ) {
                    throw ValidationException::withMessages([
                        'questions_json' => 'choice.idは文字・数字・_・-だけの重複しない20文字以内の値にし、labelも入力してください。',
                    ]);
                }

                $seenChoiceIds[$choiceId] = true;
                $choices[] = ['id' => $choiceId, 'label' => mb_substr($choiceLabel, 0, 1000)];
            }
        }

        $seenFields[$id] = true;

        return [
            'id' => $id,
            'type' => $type,
            'label' => mb_substr($label, 0, 120),
            'required' => $required,
            'placeholder' => mb_substr(trim((string) ($raw['placeholder'] ?? '')), 0, 240),
            'choices' => $choices,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeQuestionFeedback(mixed $raw, array $questions): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $questionIds = collect($questions)->pluck('id')->map('strval')->all();
        $allowedCorrectness = ['correct', 'partial', 'incorrect', 'ungraded'];
        $allowedErrorTypes = [
            'none',
            'knowledge_gap',
            'concept_gap',
            'reasoning_gap',
            'condition_reading',
            'unit_error',
            'calculation_slip',
            'careless',
            'unknown',
        ];
        $result = [];
        $seen = [];

        foreach (array_values($raw) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $questionId = trim((string) ($item['question_id'] ?? ''));
            if ($questionId === '' || ! in_array($questionId, $questionIds, true) || isset($seen[$questionId])) {
                continue;
            }

            $correctness = trim((string) ($item['correctness'] ?? 'ungraded'));
            if (! in_array($correctness, $allowedCorrectness, true)) {
                $correctness = 'ungraded';
            }

            $errorType = trim((string) ($item['error_type'] ?? ($correctness === 'correct' ? 'none' : 'unknown')));
            if (! in_array($errorType, $allowedErrorTypes, true)) {
                $errorType = $correctness === 'correct' ? 'none' : 'unknown';
            }
            $weaknessTopics = array_slice(
                $this->stringList($item['weakness_topics'] ?? []),
                0,
                8,
            );
            $misconceptions = array_slice(
                $this->stringList($item['misconceptions'] ?? []),
                0,
                8,
            );

            if ($correctness === 'correct') {
                $errorType = 'none';
                // Reasoning-quality comments may remain visible, but a correct
                // answer cannot register a routing weakness. If the reasoning
                // reveals a substantive misconception, the AI must use partial.
                $weaknessTopics = [];
                $misconceptions = [];
            }

            $seen[$questionId] = true;
            $result[] = [
                'question_id' => $questionId,
                'correctness' => $correctness,
                'feedback' => mb_substr(trim((string) ($item['feedback'] ?? '')), 0, 2000),
                'reasoning_feedback' => mb_substr(trim((string) ($item['reasoning_feedback'] ?? '')), 0, 2000),
                'error_type' => $errorType,
                'weakness_topics' => $weaknessTopics,
                'misconceptions' => $misconceptions,
            ];
        }

        return $result;
    }

    /**
     * Normalize the AI's recommendation into one deterministic next step that
     * Canovia can turn into a primary action. Old assessments without next_step
     * remain compatible through a conservative fallback.
     *
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    private function normalizeNextStep(mixed $raw, Task $task, array $assessment): array
    {
        $allowedKinds = ['practice', 'review', 'continue_task', 'complete_task', 'plan_update'];
        $weaknesses = $this->stringList($assessment['weaknesses'] ?? []);
        $recommendedProgress = max(0, min(100, (int) ($assessment['recommended_task_progress_percent'] ?? $task->progress_percent)));
        $legacyAction = mb_substr(trim((string) ($assessment['next_action'] ?? '')), 0, 1000);

        $fallbackKind = match (true) {
            $recommendedProgress >= 100 => 'complete_task',
            $weaknesses !== [] => 'practice',
            default => 'continue_task',
        };

        $data = is_array($raw) ? $raw : [];
        $kind = trim((string) ($data['kind'] ?? $fallbackKind));
        if (! in_array($kind, $allowedKinds, true)) {
            $kind = $fallbackKind;
        }

        $fallbackLabel = match ($kind) {
            'practice' => $legacyAction !== '' ? $legacyAction : '今回の弱点をもう一度演習する',
            'review' => $legacyAction !== '' ? $legacyAction : '今回の弱点を復習する',
            'complete_task' => $legacyAction !== '' ? $legacyAction : 'このTaskの学習結果を反映して完了を確認する',
            'plan_update' => $legacyAction !== '' ? $legacyAction : '学習結果をもとに計画を見直す',
            default => $legacyAction !== '' ? $legacyAction : 'このTaskの学習を続ける',
        };

        $label = mb_substr(trim((string) ($data['label'] ?? $fallbackLabel)), 0, 240);
        if ($label === '') {
            $label = $fallbackLabel;
        }

        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 1000);
        if ($reason === '' && $weaknesses !== []) {
            $reason = '今回の評価で「'.implode(' / ', array_slice($weaknesses, 0, 3)).'」を補強する必要があるため。';
        }

        $focusTopics = array_slice($this->stringList($data['focus_topics'] ?? []), 0, 6);
        if ($kind === 'practice' && $focusTopics === []) {
            $focusTopics = array_slice($weaknesses, 0, 6);
        }

        $questionCountRaw = filter_var($data['question_count'] ?? null, FILTER_VALIDATE_INT);
        $questionCount = $kind === 'practice'
            ? ($questionCountRaw !== false ? max(1, min(20, $questionCountRaw)) : 5)
            : null;

        return [
            'kind' => $kind,
            'label' => $label,
            'reason' => $reason,
            'focus_topics' => $focusTopics,
            'question_count' => $questionCount,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => mb_substr(trim((string) $item), 0, 1000))
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $assessment
     */
    /**
     * Recover the answered AI handoff from durable StudyPracticeSession state
     * when the browser/PHP session was lost before the assessment JSON POST.
     *
     * Only STATUS_ANSWERED sessions are eligible here. An already assessed
     * session is intentionally not reopened, which keeps attempt idempotency
     * and the completed/result stages authoritative.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function recoverAssessmentHandoffState(
        Request $request,
        Plan $plan,
        Task $task,
        array $state,
        string $actorToken,
    ): array {
        if (
            filled($state['evaluation_prompt'] ?? null)
            && ! empty($state['answers'])
        ) {
            return $state;
        }

        $query = $this->practiceSessionQuery($request, $plan, $task, $actorToken)
            ->whereIn('status', [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
                StudyPracticeSession::STATUS_ANSWERED,
                StudyPracticeSession::STATUS_ASSESSED,
            ])
            ->whereNotNull('questions_snapshot');

        $practiceSession = null;

        if (! empty($state['practice_session_id'])) {
            $practiceSession = (clone $query)
                ->whereKey((int) $state['practice_session_id'])
                ->first();
        }

        $practiceSession ??= (clone $query)
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if (
            ! $practiceSession
            || $practiceSession->status !== StudyPracticeSession::STATUS_ANSWERED
            || ! is_array($practiceSession->questions_snapshot)
            || $practiceSession->questions_snapshot === []
        ) {
            return $state;
        }

        $evaluationPrompt = trim((string) data_get(
            $practiceSession->assessment_payload,
            'evaluation_prompt',
            '',
        ));
        $draftAnswers = is_array($practiceSession->draft_answers)
            ? $practiceSession->draft_answers
            : [];
        $answers = $this->answersFromDraft(
            $practiceSession->questions_snapshot,
            $draftAnswers,
        );

        if ($evaluationPrompt === '' || $answers === []) {
            return $state;
        }

        return array_replace($state, [
            'title' => filled($state['title'] ?? null)
                ? (string) $state['title']
                : ($practiceSession->exercise_title ?: 'AI演習'),
            'questions' => $practiceSession->questions_snapshot,
            'answers' => $answers,
            'draft_answers' => $draftAnswers,
            'evaluation_prompt' => $evaluationPrompt,
            'assessment' => null,
            'attempt_id' => null,
            'attempt_token' => filled($state['attempt_token'] ?? null)
                ? (string) $state['attempt_token']
                : (string) Str::uuid(),
            'practice_session_id' => $practiceSession->id,
        ]);
    }

    private function persistAssessment(
        Request $request,
        Plan $plan,
        Task $task,
        array &$state,
        array $assessment,
        string $actorToken,
    ): StudyPracticeAttempt {
        $identityScope = $request->user()
            ? 'user:'.(int) $request->user()->id
            : 'actor:'.$actorToken;
        $attemptToken = (string) ($state['attempt_token'] ?? Str::uuid());
        $state['attempt_token'] = $attemptToken;

        $requestHash = hash('sha256', json_encode([
            'identity' => $identityScope,
            'attempt_token' => $attemptToken,
            'plan_id' => (int) $plan->id,
            'task_id' => (int) $task->id,
            'questions' => $state['questions'] ?? [],
            'answers' => $state['answers'] ?? [],
            'assessment' => $assessment,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return StudyPracticeAttempt::query()->createOrFirst(
            ['request_hash' => $requestHash],
            [
                'study_practice_session_id' => ! empty($state['practice_session_id'])
                    ? (int) $state['practice_session_id']
                    : null,
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $request->user()?->id,
                'actor_token' => $request->user() ? null : $actorToken,
                'exercise_title' => mb_substr((string) ($state['title'] ?? 'AI演習'), 0, 120),
                'questions' => $state['questions'] ?? [],
                'answers' => $state['answers'] ?? [],
                'assessment' => $assessment,
                'score_percent' => (int) ($assessment['score_percent'] ?? 0),
                'strengths' => $assessment['strengths'] ?? [],
                'weaknesses' => $assessment['weaknesses'] ?? [],
                'recommended_task_progress_percent' => (int) ($assessment['recommended_task_progress_percent'] ?? $task->progress_percent),
                'evidence_summary' => filled($assessment['evidence_summary'] ?? null)
                    ? (string) $assessment['evidence_summary']
                    : null,
                'next_action' => filled($assessment['next_action'] ?? null)
                    ? (string) $assessment['next_action']
                    : null,
            ]
        );
    }

    private function attemptQuery(Request $request, Plan $plan, Task $task, string $actorToken)
    {
        $query = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($request->user()) {
            return $query->where('user_id', $request->user()->id);
        }

        return $query->whereNull('user_id')->where('actor_token', $actorToken);
    }

    private function practiceSessionQuery(Request $request, Plan $plan, Task $task, string $actorToken)
    {
        $query = StudyPracticeSession::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id);

        if ($request->user()) {
            return $query->where('user_id', $request->user()->id);
        }

        return $query->whereNull('user_id')->where('actor_token', $actorToken);
    }

    private function sessionKey(Plan $plan, Task $task): string
    {
        return "study_practice.{$plan->id}.{$task->id}";
    }
}
