<?php

namespace App\Services;

use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DevelopmentTaskAssociationService
{
    public function __construct(
        private readonly GitHubReturnEvidenceService $pullRequestEvidence,
        private readonly GitHubDevelopmentEvidenceService $developmentEvidence,
        private readonly DevelopmentAdaptiveActionService $developmentActions,
    ) {}

    /**
     * @return array{artifact:PlanArtifact,evidence_synced:bool,warning:?string}
     */
    public function link(
        Plan $plan,
        Task $task,
        DevelopmentActivityObservation $observation,
    ): array {
        $this->assertLinkable($plan, $task, $observation);

        $artifact = DB::transaction(function () use ($plan, $task, $observation) {
            $artifact = $this->artifactForObservation($plan, $observation);
            $artifact->tasks()->syncWithoutDetaching([(int) $task->id]);

            $observation->update([
                'resolution_status' => 'linked',
                'resolved_artifact_id' => (int) $artifact->id,
            ]);

            return $artifact->fresh('tasks');
        });

        $synced = false;
        $warning = null;

        try {
            if ($observation->kind === 'pull_request') {
                $this->pullRequestEvidence->sync($plan, $task, $artifact);
                $synced = true;
            } elseif (in_array($observation->kind, ['issue', 'branch', 'commit'], true)) {
                $synced = $this->developmentEvidence->syncObservation(
                    $observation,
                    $task,
                    $artifact,
                ) !== null;
            }

            if ($synced) {
                $this->developmentActions->tryRefresh($plan, now());
            }
        } catch (Throwable) {
            $warning = 'Taskとの関連付けは保存しましたが、GitHubの現在状態を再取得できませんでした。次回Webhookまたは再同期で更新します。';
        }

        return [
            'artifact' => $artifact,
            'evidence_synced' => $synced,
            'warning' => $warning,
        ];
    }

    public function ignore(
        Plan $plan,
        DevelopmentActivityObservation $observation,
    ): void {
        if ((int) $observation->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages([
                'observation' => 'このPlanのGitHub活動を選んでください。',
            ]);
        }

        if ($observation->resolution_status === 'linked') {
            throw ValidationException::withMessages([
                'observation' => 'Taskへ関連付け済みのGitHub活動は無視できません。',
            ]);
        }

        $observation->update([
            'resolution_status' => 'ignored',
            'suggested_task_id' => null,
            'suggestion_confidence' => null,
            'suggestion_basis' => null,
        ]);
    }

    private function assertLinkable(
        Plan $plan,
        Task $task,
        DevelopmentActivityObservation $observation,
    ): void {
        if (
            (int) $task->plan_id !== (int) $plan->id
            || (int) $observation->plan_id !== (int) $plan->id
        ) {
            throw ValidationException::withMessages([
                'task_id' => '同じPlanのTaskを選んでください。',
            ]);
        }

        if (in_array($task->status, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'task_id' => '進行中または未着手のTaskを選んでください。',
            ]);
        }

        if ($observation->resolution_status === 'ignored') {
            throw ValidationException::withMessages([
                'observation' => '無視済みのGitHub活動です。',
            ]);
        }

        if (! in_array(
            $observation->kind,
            ['pull_request', 'issue', 'branch', 'commit'],
            true,
        )) {
            throw ValidationException::withMessages([
                'observation' => 'このGitHub活動はV57.2のTask関連付け対象ではありません。',
            ]);
        }
    }

    private function artifactForObservation(
        Plan $plan,
        DevelopmentActivityObservation $observation,
    ): PlanArtifact {
        if ($observation->resolved_artifact_id) {
            $existing = PlanArtifact::query()
                ->where('plan_id', $plan->id)
                ->whereKey($observation->resolved_artifact_id)
                ->first();

            if ($existing instanceof PlanArtifact) {
                return $existing;
            }
        }

        $url = $this->artifactUrl($observation);
        if ($url === null) {
            throw ValidationException::withMessages([
                'observation' => 'GitHub URLを構成できないため、この活動はまだTaskへ関連付けできません。',
            ]);
        }

        $existing = PlanArtifact::query()
            ->where('plan_id', $plan->id)
            ->where('provider', 'github')
            ->where('url', $url)
            ->first();

        if ($existing instanceof PlanArtifact) {
            return $existing;
        }

        return PlanArtifact::query()->create([
            'plan_id' => (int) $plan->id,
            'created_by_user_id' => $plan->user_id,
            'assigned_user_id' => null,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => $this->artifactTitle($observation),
            'url' => $url,
            'external_id' => $this->externalId($observation),
            'metadata' => null,
        ]);
    }

    private function artifactUrl(
        DevelopmentActivityObservation $observation,
    ): ?string {
        $url = trim((string) $observation->url);
        if ($url !== '') {
            return $url;
        }

        if ($observation->kind !== 'branch') {
            return null;
        }

        $root = $observation->repositoryArtifact;
        $rootUrl = rtrim(trim((string) $root?->url), '/');
        $ref = trim((string) $observation->ref);

        if ($rootUrl === '' || $ref === '') {
            return null;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $ref)));

        return $rootUrl.'/tree/'.$encoded;
    }

    private function artifactTitle(
        DevelopmentActivityObservation $observation,
    ): string {
        $title = trim((string) $observation->title);

        return match ($observation->kind) {
            'pull_request' => 'PR #'.(int) $observation->provider_number
                .($title !== '' ? ' · '.$title : ''),
            'issue' => 'Issue #'.(int) $observation->provider_number
                .($title !== '' ? ' · '.$title : ''),
            'branch' => 'Branch '.trim((string) $observation->ref),
            'commit' => 'Commit '.mb_substr((string) $observation->sha, 0, 10),
            default => $title !== '' ? $title : 'GitHub activity',
        };
    }

    private function externalId(
        DevelopmentActivityObservation $observation,
    ): ?string {
        return match ($observation->kind) {
            'pull_request', 'issue' => $observation->provider_number
                ? (string) $observation->provider_number
                : null,
            'commit' => trim((string) $observation->sha) ?: null,
            default => null,
        };
    }
}
