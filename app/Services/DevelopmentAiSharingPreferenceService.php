<?php

namespace App\Services;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\DevelopmentAiSharingPreferenceEvent;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prepares and cancels user choices. Deliberately NO delegated authorization,
 * external token, ChatGPT call, MCP endpoint, or private Context retrieval.
 */
final class DevelopmentAiSharingPreferenceService
{
    public const ALLOWED_DURATIONS = [1, 7, 30];
    public const ALLOWED_SCOPES = ['overview', 'tasks'];

    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    public function prepare(
        User $user,
        Plan $plan,
        string $scope,
        int $days,
    ): DevelopmentAiSharingPreference {
        $this->assertPersonalPlanOwner($user, $plan);
        abort_if((bool) $plan->is_collaborative, 404);
        abort_unless($this->profiles->forPlan($plan)->key === 'development', 404);

        if (! in_array($scope, self::ALLOWED_SCOPES, true)
            || ! in_array($days, self::ALLOWED_DURATIONS, true)) {
            throw new InvalidArgumentException('Unsupported scope or preparation lifetime.');
        }

        return DB::transaction(function () use ($user, $plan, $scope, $days) {
            $query = DevelopmentAiSharingPreference::query()
                ->where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('provider_key', DevelopmentAiSharingPreference::PROVIDER_CHATGPT);
            $existing = $query->lockForUpdate()->first();
            $expiresAt = now()->addDays($days);

            if ($existing) {
                $existing->update([
                    'scope' => $scope,
                    'status' => DevelopmentAiSharingPreference::STATUS_PREPARED,
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                ]);
                $preference = $existing;
                $event = 'updated';
            } else {
                $preference = DevelopmentAiSharingPreference::query()->create([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'provider_key' => DevelopmentAiSharingPreference::PROVIDER_CHATGPT,
                    'scope' => $scope,
                    'status' => DevelopmentAiSharingPreference::STATUS_PREPARED,
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                ]);
                $event = 'prepared';
            }

            $this->record($preference, $user, $event);

            return $preference->fresh();
        });
    }

    /**
     * The Plan may have become collaborative or changed category since setup.
     * Cancellation must still be possible for its original owner.
     */
    public function revoke(User $user, Plan $plan): ?DevelopmentAiSharingPreference
    {
        $this->assertPersonalPlanOwner($user, $plan);

        return DB::transaction(function () use ($user, $plan) {
            $preference = DevelopmentAiSharingPreference::query()
                ->where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('provider_key', DevelopmentAiSharingPreference::PROVIDER_CHATGPT)
                ->lockForUpdate()
                ->first();

            if (! $preference) {
                return null;
            }

            if ($preference->status !== DevelopmentAiSharingPreference::STATUS_REVOKED
                || $preference->revoked_at === null) {
                $preference->update([
                    'status' => DevelopmentAiSharingPreference::STATUS_REVOKED,
                    'revoked_at' => now(),
                ]);
                $this->record($preference, $user, 'revoked');
            }

            return $preference->fresh();
        });
    }

    private function assertPersonalPlanOwner(User $user, Plan $plan): void
    {
        if ($plan->user_id === null || (int) $plan->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    private function record(
        DevelopmentAiSharingPreference $preference,
        User $user,
        string $event,
    ): void {
        DevelopmentAiSharingPreferenceEvent::query()->create([
            'preference_id' => $preference->id,
            'actor_user_id' => $user->id,
            'event' => $event,
            'scope' => $preference->scope,
            'expires_at' => $preference->expires_at,
            'created_at' => now(),
        ]);
    }
}
