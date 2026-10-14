<?php

namespace App\Services;

use App\Models\McpLinkedSubject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Last step of a double-identity verified, short-lived session transaction.
 * A linked subject is NOT a delegated permission grant.
 */
final class McpOAuthAccountLinkCommitter
{
    public function __construct(
        private readonly McpOAuthAccountLinkConfiguration $configuration,
    ) {}

    public function confirm(User $user, string $fingerprint): bool
    {
        if ($this->configuration->settings() === null
            || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            return false;
        }

        try {
            return DB::transaction(function () use ($user, $fingerprint): bool {
                // Serialize confirmations for this Canovia account. The
                // active-only DB unique index prevents concurrent claims of
                // the same subject by two accounts even on different workers.
                $owner = User::query()->whereKey($user->id)->lockForUpdate()->first();
                if ($owner === null) {
                    return false;
                }

                // Check ALL live claims for the immutable identity, not
                // just rows whose active reservation looks healthy. A partial
                // migration / out-of-band write could leave a status=linked
                // row with an absent or mismatched active fingerprint. Such a
                // row must block a second account, not silently release it.
                // The database UNIQUE reservation remains the final race
                // fence for otherwise-healthy concurrent confirmations.
                $liveLinks = McpLinkedSubject::query()
                    ->where('identity_fingerprint', $fingerprint)
                    ->where('status', McpLinkedSubject::STATUS_LINKED)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()->get();

                if ($liveLinks->count() > 1) {
                    return false;
                }

                $active = $liveLinks->first();
                if ($active !== null
                    && ($active->active_identity_fingerprint !== $fingerprint
                        || $active->provider_key !== 'chatgpt'
                        || (int) $active->user_id !== (int) $owner->id)) {
                    // Even the current owner must not "confirm" a corrupt
                    // reservation as valid. Require operator review/repair;
                    // no new linked row, grant or audit is created.
                    return false;
                }

                // Only this owner's previously revoked link may be reused.
                // A link revoked by ANOTHER owner is permanently historical:
                // create a new row so old grants/audit stay bound to them.
                $existing = $active ?? McpLinkedSubject::query()
                    ->where('user_id', $owner->id)
                    ->where('identity_fingerprint', $fingerprint)
                    ->orderByDesc('id')
                    ->lockForUpdate()->first();

                // One active ChatGPT identity per Canovia account. A future
                // identity switch requires explicit unlink + OAuth recheck.
                $otherActive = McpLinkedSubject::query()
                    ->where('user_id', $owner->id)
                    ->where('provider_key', 'chatgpt')
                    ->where('status', McpLinkedSubject::STATUS_LINKED)
                    ->whereNull('revoked_at')
                    ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
                    ->exists();
                if ($otherActive) {
                    return false;
                }

                if ($existing !== null) {
                    if ($existing->status === McpLinkedSubject::STATUS_LINKED
                        && $existing->revoked_at === null) {
                        // Already linked; no new audit event.
                        return true;
                    }

                    $existing->forceFill([
                        'status' => McpLinkedSubject::STATUS_LINKED,
                        'revoked_at' => null,
                        'linked_at' => now(),
                    ])->save();
                    $subject = $existing;
                    $event = 'subject_relinked';
                } else {
                    $subject = McpLinkedSubject::query()->create([
                        'user_id' => $owner->id,
                        'provider_key' => 'chatgpt',
                        'identity_fingerprint' => $fingerprint,
                        'status' => McpLinkedSubject::STATUS_LINKED,
                        'linked_at' => now(),
                    ]);
                    $event = 'subject_linked';
                }

                DB::table('mcp_delegated_access_events')->insert([
                    'actor_user_id' => $owner->id,
                    'subject_link_id' => $subject->id,
                    'grant_id' => null,
                    'event_kind' => $event,
                    'scope' => null,
                    'created_at' => now(),
                ]);

                // Importantly: no mcp_delegated_grants row is created and
                // revoked grants remain revoked after explicit relinking.
                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // May be a competing link for a different Canovia user; do not
            // reveal the user or external subject in the public error.
            return false;
        }
    }
}
