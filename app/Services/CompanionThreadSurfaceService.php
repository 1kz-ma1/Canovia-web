<?php

namespace App\Services;

use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\User;
use Illuminate\Support\Str;

final class CompanionThreadSurfaceService
{
    public function __construct(
        private readonly CompanionContextService $context,
        private readonly CompanionContinuityService $continuity,
        private readonly CompanionMutationApplyService $mutationApply,
    ) {}

    /**
     * Shared read model for the full Companion page and the floating Palette.
     *
     * @return array<string,mixed>
     */
    public function build(
        CompanionThread $thread,
        User $user,
        ?string $sourcePath = null,
    ): array {
        $thread->load([
            'messages.mutationCandidates',
            'mutationCandidates',
            'plan',
            'task',
        ]);

        $plan = $thread->plan;
        $task = $thread->task;

        if ($task && (! $plan || (int) $task->plan_id !== (int) $plan->id)) {
            $task = null;
            $thread->setRelation('task', null);
        }

        $candidatePreviews = $thread->mutationCandidates
            ->mapWithKeys(fn (CompanionMutationCandidate $candidate) => [
                (int) $candidate->id => $this->mutationApply->reviewPreview($candidate),
            ])
            ->all();

        $candidateApplyRequestIds = $thread->mutationCandidates
            ->where('status', CompanionMutationCandidate::STATUS_PENDING)
            ->mapWithKeys(fn (CompanionMutationCandidate $candidate) => [
                (int) $candidate->id => (string) Str::uuid(),
            ])
            ->all();

        $contextSnapshot = $this->context->snapshot(
            $user,
            $plan,
            $task,
            is_array($thread->context_scope) ? $thread->context_scope : null,
        );

        $continuitySignals = $this->continuity->signals($thread, $contextSnapshot);
        $contextSnapshot['continuity'] = $this->continuity->promptContext($continuitySignals);

        $continuityRequestIds = collect($continuitySignals)
            ->where('action', 'ask')
            ->mapWithKeys(fn (array $signal) => [
                (string) $signal['key'] => (string) Str::uuid(),
            ])
            ->all();

        return [
            'thread' => $thread,
            'contextSnapshot' => $contextSnapshot,
            'continuitySignals' => $continuitySignals,
            'continuityRequestIds' => $continuityRequestIds,
            'messageRequestId' => (string) Str::uuid(),
            'companionSourcePath' => $sourcePath
                ?: data_get($thread->context_scope, 'source_path')
                ?: request()->path(),
            'candidatePreviews' => $candidatePreviews,
            'candidateApplyRequestIds' => $candidateApplyRequestIds,
        ];
    }
}
