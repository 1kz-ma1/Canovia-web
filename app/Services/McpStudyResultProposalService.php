<?php

namespace App\Services;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\McpStudyResultProposal;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class McpStudyResultProposalService
{
    public function __construct(
        private readonly McpDelegatedPlanAccessPolicy $policy,
        private readonly McpExplicitPlanConsentService $consent,
        private readonly McpDelegatedIdentityFingerprintService $fingerprints,
        private readonly McpStudyPilotAccessPolicy $apPilot,
    ) {}

    public function isEnabled(): bool
    {
        return config('canovia_mcp.tools_enabled') === true
            && config('canovia_mcp.study_read_enabled') === true
            && config('canovia_mcp.study_summary_read_enabled') === true
            && config('canovia_mcp.study_result_proposals_enabled') === true
            && config('app.env') !== 'staging'
            && $this->apPilot->configured();
    }

    /**
     * Stores a PENDING proposal only, never changes Task progress or final
     * learning evidence. A separate write OAuth scope is mandatory even
     * though an existing study_progress read grant is also checked.
     *
     * @param list<string> $weaknesses
     * @return array<string,int|string>|null
     */
    public function propose(
        McpVerifiedTokenPrincipal $principal,
        int $planId,
        int $taskId,
        string $key,
        int $questionCount,
        int $correctCount,
        array $weaknesses,
    ): ?array {
        if (! $this->isEnabled()
            || ! in_array(McpProtectedResourceConfiguration::STUDY_WRITE_SCOPE, $principal->scopes, true)
            || $planId < 1 || $taskId < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1
            || $questionCount < 1 || $questionCount > 20
            || $correctCount < 0 || $correctCount > $questionCount
            || count($weaknesses) > 5) {
            return null;
        }

        $names = [];
        foreach ($weaknesses as $weakness) {
            if (! is_string($weakness)) {
                return null;
            }
            $name = trim($weakness);
            if ($name === '' || mb_strlen($name) > 32
                || preg_match('/\A[\p{L}\p{N}\p{Zs}・／+_\/-]+\z/uD', $name) !== 1) {
                return null;
            }
            $names[mb_strtolower($name)] = $name;
        }
        $names = array_values($names);
        sort($names, SORT_STRING);
        $digest = hash('sha256', json_encode([
            $planId, $taskId, $questionCount, $correctCount, $names,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($principal, $planId, $taskId, $key, $questionCount, $correctCount, $names, $digest): ?array {
            $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
            if ($plan === null) {
                return null;
            }
            $task = Task::query()->where('plan_id', $plan->id)->whereKey($taskId)
                ->lockForUpdate()->first();
            if ($task === null || $task->status === 'cancelled') {
                return null;
            }
            // Grants are locked behind Plan + linked subject on every call;
            // downgrade, revoke, subject unlink and ownership changes deny.
            $grant = $this->policy->authorizedGrant(
                $principal,
                $plan,
                'study_progress',
                lockForRead: true,
                requiredDomain: 'study',
            );
            if ($grant === null) {
                return null;
            }

            $existing = McpStudyResultProposal::query()
                ->where('grant_id', $grant->id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $digest)) {
                    return null;
                }
                return ['proposal_id' => (int) $existing->id, 'status' => (string) $existing->status];
            }

            // Bound repeated AI proposals even when bearer remains valid.
            $todayCount = McpStudyResultProposal::query()
                ->where('grant_id', $grant->id)->where('created_at', '>=', now()->subDay())->count();
            $pendingCount = McpStudyResultProposal::query()
                ->where('grant_id', $grant->id)->where('status', McpStudyResultProposal::PENDING)
                ->where('expires_at', '>', now())->count();
            if ($todayCount >= 12 || $pendingCount >= 3) {
                return null;
            }

            $proposal = McpStudyResultProposal::query()->create([
                'user_id' => $plan->user_id,
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'grant_id' => $grant->id,
                'idempotency_key' => $key,
                'payload_hash' => $digest,
                'question_count' => $questionCount,
                'correct_count' => $correctCount,
                'weakness_topics' => $names,
                'status' => McpStudyResultProposal::PENDING,
                'expires_at' => now()->addMinutes(30),
            ]);

            return ['proposal_id' => (int) $proposal->id, 'status' => McpStudyResultProposal::PENDING];
        }, 3);
    }

    /**
     * The Canovia-authenticated owner must explicitly click Approve.
     * Incoming MCP requests cannot call this function. No unconfirmed
     * result enters the approved-study-summary query.
     */
    public function decide(User $actor, int $proposalId, bool $approve): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return DB::transaction(function () use ($actor, $proposalId, $approve): bool {
            $snapshot = McpStudyResultProposal::query()->whereKey($proposalId)
                ->where('user_id', $actor->id)->first();
            if ($snapshot === null) {
                return false;
            }

            $plan = Plan::query()->whereKey($snapshot->plan_id)->lockForUpdate()->first();
            if ($plan === null || ! $this->consent->isEligibleMcpPlanOwner($actor, $plan)) {
                return false;
            }
            $task = Task::query()->whereKey($snapshot->task_id)
                ->where('plan_id', $plan->id)->lockForUpdate()->first();
            if ($task === null || $task->status === 'cancelled') {
                return false;
            }

            // Reserve the same link -> grant lock order as MCP reads and
            // identity unlinks. The snapshot is not an authorization source;
            // all locked records are rechecked before committing.
            $grantSnapshot = McpDelegatedGrant::query()
                ->whereKey($snapshot->grant_id)->where('user_id', $actor->id)
                ->where('plan_id', $plan->id)->first();
            if ($grantSnapshot === null) {
                return false;
            }
            $link = McpLinkedSubject::query()
                ->whereKey($grantSnapshot->subject_link_id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            $grant = McpDelegatedGrant::query()->whereKey($snapshot->grant_id)
                ->where('user_id', $actor->id)->where('plan_id', $plan->id)
                ->lockForUpdate()->first();
            $proposal = McpStudyResultProposal::query()->whereKey($proposalId)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($grant === null || $proposal === null
                || $proposal->status !== McpStudyResultProposal::PENDING) {
                return false;
            }
            // Rejecting the draft requires only ownership. Approval is
            // denied if any currently linked identity or grant has changed.
            if ($approve) {
                $expected = $this->fingerprints->clientResource(
                    (string) config('canovia_mcp.allowed_client_id'),
                    (string) config('canovia_mcp.resource_url'),
                );
                if ($link === null || $expected === null
                    || (int) $grant->subject_link_id !== (int) $link->id
                    || $link->provider_key !== 'chatgpt'
                    || $link->status !== McpLinkedSubject::STATUS_LINKED
                    || $link->revoked_at !== null
                    || $link->identity_fingerprint !== $link->active_identity_fingerprint
                    || $link->linked_at === null || $link->linked_at->isFuture()
                    || ! hash_equals($expected, (string) $grant->client_resource_fingerprint)
                    || $grant->status !== McpDelegatedGrant::STATUS_ACTIVE
                    || $grant->revoked_at !== null
                    || $grant->scope !== 'study_progress'
                    || $grant->consented_at === null
                    || $grant->consented_at->isFuture()
                    || ($grant->expires_at !== null && $grant->expires_at->isPast())
                    || $proposal->expires_at->isPast()) {
                    return false;
                }
            }

            // Exactly one state transition protected by the locked row.
            $proposal->forceFill([
                'status' => $approve ? McpStudyResultProposal::APPROVED : McpStudyResultProposal::REJECTED,
                'decided_at' => now(),
            ])->save();

            return true;
        }, 3);
    }
}
