<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuestPlanClaimService
{
    public function claim(Request $request, User $user): int
    {
        $claimed = 0;

        foreach ($request->cookies->all() as $name => $token) {
            if (! preg_match('/^pace_keeper_owner_token_(\d+)$/', (string) $name, $matches)) {
                continue;
            }

            if (! is_string($token) || $token === '') {
                continue;
            }

            // Plan-first lock: serialize a guest ownership claim with all
            // Companion owner-epoch checks and any competing owner claim.
            [$claimedNow, $belongsToActor] = DB::transaction(function () use ($matches, $token, $user): array {
                $plan = Plan::query()->whereKey((int) $matches[1])->lockForUpdate()->first();
                if (! $plan || ! is_string($plan->owner_token)
                    || ! hash_equals($plan->owner_token, $token)) {
                    return [false, false];
                }

                $claimedNow = false;
                if ($plan->user_id === null) {
                    $plan->forceFill(['user_id' => $user->id])->save();
                    $claimedNow = true;
                }

                return [$claimedNow, (int) $plan->user_id === (int) $user->id];
            }, 3);

            if ($claimedNow) {
                $claimed++;
            }
            if ($belongsToActor) {
                // An authenticated Plan no longer needs a legacy guest cookie.
                cookie()->queue(cookie()->forget((string) $name));
            }
        }

        return $claimed;
    }
}
