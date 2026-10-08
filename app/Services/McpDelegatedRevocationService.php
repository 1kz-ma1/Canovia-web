<?php

namespace App\Services;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Session-owner initiated withdrawal of future OAuth consent/linkage.
 *
 * This service can only revoke. It cannot create/link a subject, issue tokens,
 * activate grants, or read Canovia private Context.
 */
final class McpDelegatedRevocationService
{
    public function revokeGrant(User $actor, int $grantId): bool
    {
        return DB::transaction(function () use ($actor, $grantId): bool {
            $grant = McpDelegatedGrant::query()
                ->whereKey($grantId)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                return false;
            }

            if ($grant->status !== McpDelegatedGrant::STATUS_REVOKED
                || $grant->revoked_at === null) {
                $grant->forceFill([
                    'status' => McpDelegatedGrant::STATUS_REVOKED,
                    'revoked_at' => now(),
                ])->save();

                $this->audit(
                    $actor,
                    (int) $grant->subject_link_id,
                    (int) $grant->id,
                    'grant_revoked',
                    $grant->scope,
                );
            }

            return true;
        }, 3);
    }

    public function revokeSubject(User $actor, int $subjectId): bool
    {
        return DB::transaction(function () use ($actor, $subjectId): bool {
            $subject = McpLinkedSubject::query()
                ->whereKey($subjectId)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if ($subject === null) {
                return false;
            }

            // A subject unlink also revokes EVERY grant, even those on Plans
            // transferred to other owners or no longer classified Development.
            $grants = McpDelegatedGrant::query()
                ->where('subject_link_id', $subject->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->get();

            foreach ($grants as $grant) {
                if ($grant->status === McpDelegatedGrant::STATUS_REVOKED
                    && $grant->revoked_at !== null) {
                    continue;
                }

                $grant->forceFill([
                    'status' => McpDelegatedGrant::STATUS_REVOKED,
                    'revoked_at' => now(),
                ])->save();

                $this->audit(
                    $actor,
                    (int) $subject->id,
                    (int) $grant->id,
                    'grant_revoked_by_unlink',
                    $grant->scope,
                );
            }

            if ($subject->status !== McpLinkedSubject::STATUS_REVOKED
                || $subject->revoked_at === null) {
                $subject->forceFill([
                    'status' => McpLinkedSubject::STATUS_REVOKED,
                    'revoked_at' => now(),
                ])->save();

                $this->audit(
                    $actor,
                    (int) $subject->id,
                    null,
                    'subject_unlinked',
                    null,
                );
            }

            return true;
        }, 3);
    }

    private function audit(
        User $actor,
        int $subjectId,
        ?int $grantId,
        string $event,
        ?string $scope,
    ): void {
        // Only metadata: NEVER raw subject, fingerprints, Plan title,
        // cookies, authorization headers, token or context content.
        DB::table('mcp_delegated_access_events')->insert([
            'actor_user_id' => $actor->id,
            'subject_link_id' => $subjectId,
            'grant_id' => $grantId,
            'event_kind' => $event,
            'scope' => in_array($scope, ['overview', 'tasks'], true)
                ? $scope : null,
            'created_at' => now(),
        ]);
    }
}
