<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Services\CompanionContextService;
use App\Services\CompanionConversationService;
use App\Services\CompanionMutationApplyService;
use App\Services\FeatureAccessService;
use App\Services\FeatureFlagService;
use App\Services\NativeAiGateway;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanionController extends Controller
{
    public function index(
        Request $request,
        PlanOwnershipService $ownership,
        FeatureFlagService $flags,
        FeatureAccessService $access,
        NativeAiGateway $nativeAi,
    ) {
        $user = $request->user();
        $plans = $this->editablePlans($request, $ownership);
        $threads = CompanionThread::query()
            ->with(['plan', 'task'])
            ->where('user_id', $user->id)
            ->where('status', CompanionThread::STATUS_ACTIVE)
            ->latest('last_message_at')
            ->latest('id')
            ->limit(30)
            ->get();

        return view('companion.index', [
            'plans' => $plans,
            'threads' => $threads,
            ...$this->availability($user, $flags, $access, $nativeAi),
        ]);
    }

    public function storeThread(
        Request $request,
        PlanOwnershipService $ownership,
        FeatureFlagService $flags,
        FeatureAccessService $access,
        NativeAiGateway $nativeAi,
    ) {
        $this->authorizeAvailable($request, $flags, $access, $nativeAi);

        $validated = $request->validate([
            'scope' => ['nullable', 'string', 'max:80'],
        ]);

        [$plan, $task] = $this->resolveScope(
            $request,
            $ownership,
            (string) ($validated['scope'] ?? 'global'),
        );

        $thread = CompanionThread::query()->create([
            'user_id' => $request->user()->id,
            'plan_id' => $plan?->id,
            'task_id' => $task?->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => [
                'scope' => $task ? 'task' : ($plan ? 'plan' : 'global'),
            ],
        ]);

        return redirect()->route('companion.show', $thread);
    }

    public function show(
        Request $request,
        CompanionThread $companionThread,
        PlanOwnershipService $ownership,
        CompanionContextService $context,
        CompanionMutationApplyService $mutationApply,
        FeatureFlagService $flags,
        FeatureAccessService $access,
        NativeAiGateway $nativeAi,
    ) {
        $this->authorizeThread($request, $companionThread);

        $plan = $companionThread->plan;
        $task = $companionThread->task;

        if ($plan) {
            $ownership->authorizeView($request, $plan);
        }
        if ($task && (! $plan || (int) $task->plan_id !== (int) $plan->id)) {
            $task = null;
        }

        $companionThread->load([
            'messages.mutationCandidates',
            'plan',
            'task',
        ]);

        $candidatePreviews = $companionThread->mutationCandidates
            ->mapWithKeys(fn (CompanionMutationCandidate $candidate) => [
                (int) $candidate->id => $mutationApply->reviewPreview($candidate),
            ])
            ->all();

        $candidateApplyRequestIds = $companionThread->mutationCandidates
            ->where('status', CompanionMutationCandidate::STATUS_PENDING)
            ->mapWithKeys(fn (CompanionMutationCandidate $candidate) => [
                (int) $candidate->id => (string) Str::uuid(),
            ])
            ->all();

        return view('companion.show', [
            'thread' => $companionThread,
            'contextSnapshot' => $context->snapshot($request->user(), $plan, $task),
            'messageRequestId' => (string) Str::uuid(),
            'candidatePreviews' => $candidatePreviews,
            'candidateApplyRequestIds' => $candidateApplyRequestIds,
            ...$this->availability($request->user(), $flags, $access, $nativeAi),
        ]);
    }

    public function send(
        Request $request,
        CompanionThread $companionThread,
        PlanOwnershipService $ownership,
        FeatureFlagService $flags,
        FeatureAccessService $access,
        NativeAiGateway $nativeAi,
        CompanionConversationService $conversation,
    ) {
        $this->authorizeThread($request, $companionThread);
        $this->authorizeAvailable($request, $flags, $access, $nativeAi);

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:6000'],
            'request_id' => ['required', 'uuid'],
            'source_path' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = $companionThread->plan;
        $task = $companionThread->task;

        if ($plan) {
            $ownership->authorizeEdit($request, $plan);
        }
        if ($task) {
            abort_unless($plan && (int) $task->plan_id === (int) $plan->id, 404);
        }

        try {
            $conversation->send(
                thread: $companionThread,
                user: $request->user(),
                content: $validated['content'],
                requestId: $validated['request_id'],
                plan: $plan,
                task: $task,
                sourcePath: $validated['source_path'] ?? null,
            );
        } catch (NativeAiExecutionException $exception) {
            return back()
                ->withErrors(['content' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('companion.show', $companionThread)
            ->with('status', 'CompanionがCanoviaの文脈を使って整理しました。');
    }

    public function applyCandidate(
        Request $request,
        CompanionThread $companionThread,
        CompanionMutationCandidate $candidate,
        FeatureAccessService $access,
        FeatureFlagService $flags,
        CompanionMutationApplyService $mutationApply,
    ) {
        $this->authorizeThread($request, $companionThread);
        $this->authorizeCandidate($request, $companionThread, $candidate);
        abort_unless($flags->isEnabled(FeatureKey::CanoviaCompanion), 404);
        $access->authorizeUse($request->user(), FeatureKey::CanoviaCompanion);

        $validated = $request->validate([
            'apply_request_id' => ['required', 'uuid'],
        ]);

        $result = $mutationApply->apply(
            $request,
            $candidate,
            (string) $validated['apply_request_id'],
        );

        return redirect()
            ->route('companion.show', $companionThread)
            ->with('status', $result['message']);
    }

    public function dismissCandidate(
        Request $request,
        CompanionThread $companionThread,
        CompanionMutationCandidate $candidate,
    ) {
        $this->authorizeThread($request, $companionThread);
        $this->authorizeCandidate($request, $companionThread, $candidate);

        if ($candidate->status === CompanionMutationCandidate::STATUS_PENDING) {
            $candidate->update([
                'status' => CompanionMutationCandidate::STATUS_DISMISSED,
                'reviewed_at' => now(),
            ]);
        }

        return redirect()
            ->route('companion.show', $companionThread)
            ->with('status', 'この変更候補は見送りました。');
    }

    private function authorizeThread(Request $request, CompanionThread $thread): void
    {
        abort_unless(
            $request->user()
            && (int) $thread->user_id === (int) $request->user()->id,
            404,
        );
    }

    private function authorizeCandidate(
        Request $request,
        CompanionThread $thread,
        CompanionMutationCandidate $candidate,
    ): void {
        abort_unless(
            (int) $candidate->companion_thread_id === (int) $thread->id
            && $request->user()
            && (int) $candidate->user_id === (int) $request->user()->id,
            404,
        );
    }

    private function authorizeAvailable(
        Request $request,
        FeatureFlagService $flags,
        FeatureAccessService $access,
        NativeAiGateway $nativeAi,
    ): void {
        abort_unless($flags->isEnabled(FeatureKey::CanoviaCompanion), 404);
        abort_unless($nativeAi->isConfigured(), 503, 'Canovia Companionは現在利用準備中です。');
        $access->authorizeUse($request->user(), FeatureKey::CanoviaCompanion);
        $access->authorizeUse($request->user(), FeatureKey::AutomaticAiExecution);
    }

    /**
     * @return array<string,bool>
     */
    private function availability(
        $user,
        FeatureFlagService $flags,
        FeatureAccessService $access,
        NativeAiGateway $nativeAi,
    ): array {
        $published = $flags->isEnabled(FeatureKey::CanoviaCompanion);
        $configured = $nativeAi->isConfigured();
        $featureEntitled = $access->canUse($user, FeatureKey::CanoviaCompanion);
        $nativeEntitled = $access->canUse($user, FeatureKey::AutomaticAiExecution);

        return [
            'companionPublished' => $published,
            'companionConfigured' => $configured,
            'companionFeatureEntitled' => $featureEntitled,
            'companionEntitled' => $featureEntitled && $nativeEntitled,
            'canUseCompanion' => $published && $configured && $featureEntitled && $nativeEntitled,
            'canApplyCompanionCandidates' => $published && $featureEntitled,
        ];
    }

    private function editablePlans(Request $request, PlanOwnershipService $ownership)
    {
        return $ownership->ownedPlans($request, [
            'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ])->filter(fn (Plan $plan) => $ownership->canEdit($request, $plan))->values();
    }

    /**
     * @return array{0:?Plan,1:?Task}
     */
    private function resolveScope(
        Request $request,
        PlanOwnershipService $ownership,
        string $scope,
    ): array {
        $scope = trim($scope);
        if ($scope === '' || $scope === 'global') {
            return [null, null];
        }

        $plans = $this->editablePlans($request, $ownership);

        if (preg_match('/^plan:(\d+)$/', $scope, $matches)) {
            $plan = $plans->firstWhere('id', (int) $matches[1]);
            if (! $plan) {
                throw ValidationException::withMessages(['scope' => 'このPlanをCompanionで使う権限がありません。']);
            }

            return [$plan, null];
        }

        if (preg_match('/^task:(\d+)$/', $scope, $matches)) {
            $taskId = (int) $matches[1];
            foreach ($plans as $plan) {
                $task = $plan->tasks->firstWhere('id', $taskId);
                if ($task) {
                    return [$plan, $task];
                }
            }

            throw ValidationException::withMessages(['scope' => 'このTaskをCompanionで使う権限がありません。']);
        }

        throw ValidationException::withMessages(['scope' => 'Companionの対象を確認してください。']);
    }
}
