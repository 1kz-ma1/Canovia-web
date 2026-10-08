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
                // Serialize confirmations for one Canovia actor. The unique
                // fingerprint DB constraint prevents cross-user double-link.
                $owner = User::query()->whereKey($user->id)->lockForUpdate()->first();
                if ($owner === null) {
                    return false;
                }

                $existing = McpLinkedSubject::query()
                    ->where('identity_fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && (int) $existing->user_id !== (int) $owner->id) {
                    return false;
                }

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
