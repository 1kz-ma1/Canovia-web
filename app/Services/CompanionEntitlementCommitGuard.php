<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * G3-R4 narrow commit boundary for Companion's revocable Product Grants.
 *
 * Apply only after the caller locks its Plan/Task/Candidate targets. Locks
 * existing grant rows regardless of their current status, in stable ID order,
 * and holds them until the outer transaction commits. A competing UPDATE or
 * DELETE of an existing entitlement must serialize with derived-data writes.
 *
 * Does not claim to lock absence of grants, admin preview/config overrides,
 * external billing state, or grants changed through another database.
 */
final class CompanionEntitlementCommitGuard
{
    public function __construct(
        private readonly FeatureAccessService $access,
    ) {}

    public function allowed(User $actor): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Companion entitlement commit guard requires a database transaction.');
        }

        UserProductGrant::query()
            ->where('user_id', $actor->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // Fresh query through the canonical Free/Premium/Gift/Sponsor/Admin
        // resolution, after existing grant writers have been serialized.
        return $this->access->canUse($actor, FeatureKey::CanoviaCompanion);
    }
}
