<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use InvalidArgumentException;

final class MapPersonalizationPreferenceService
{
    public const SCHEMA_VERSION = 1;

    public const MAX_PINNED = 8;

    /**
     * @return array{version:int,pinned_node_ids:array<int,string>}
     */
    public function state(?User $user): array
    {
        if (! $user) {
            return $this->defaults();
        }

        return $this->normalize($user->map_personalization_preferences);
    }

    /**
     * @return array<int,string>
     */
    public function pinnedNodeIds(?User $user): array
    {
        return $this->state($user)['pinned_node_ids'];
    }

    public function ownsPersonalPlan(User $user, Plan $plan): bool
    {
        return (int) $plan->user_id === (int) $user->id
            && ! (bool) $plan->is_collaborative;
    }

    public function canPinPlan(User $user, Plan $plan): bool
    {
        if (! $this->ownsPersonalPlan($user, $plan)) {
            return false;
        }

        return $plan->tasks()
            ->whereNotIn('status', ['done', 'cancelled'])
            ->where('progress_percent', '<', 100)
            ->exists();
    }

    /**
     * @return array{version:int,pinned_node_ids:array<int,string>}
     */
    public function pinPlan(User $user, Plan $plan): array
    {
        if (! $this->canPinPlan($user, $plan)) {
            throw new InvalidArgumentException('Plan is not eligible for a pinned Map shortcut.');
        }

        $state = $this->state($user);
        $nodeId = $this->planNodeId($plan);

        // Current L0 density permits one shortcut per semantic anchor. Replacing
        // the existing Plan pin avoids an account preference that can never be
        // represented truthfully on the Map.
        $otherAnchors = array_values(array_filter(
            $state['pinned_node_ids'],
            fn (string $id) => ! str_starts_with($id, 'satellite:plan:')
        ));

        return $this->persist($user, [
            'version' => self::SCHEMA_VERSION,
            'pinned_node_ids' => array_slice(
                [$nodeId, ...$otherAnchors],
                0,
                self::MAX_PINNED,
            ),
        ]);
    }

    /**
     * @return array{version:int,pinned_node_ids:array<int,string>}
     */
    public function unpinPlan(User $user, Plan $plan): array
    {
        if (! $this->ownsPersonalPlan($user, $plan)) {
            throw new InvalidArgumentException('Plan is not available for this account.');
        }

        $nodeId = $this->planNodeId($plan);
        $state = $this->state($user);

        return $this->persist($user, [
            'version' => self::SCHEMA_VERSION,
            'pinned_node_ids' => array_values(array_filter(
                $state['pinned_node_ids'],
                fn (string $id) => $id !== $nodeId,
            )),
        ]);
    }

    private function planNodeId(Plan $plan): string
    {
        return 'satellite:plan:'.(int) $plan->id;
    }

    /**
     * @return array{version:int,pinned_node_ids:array<int,string>}
     */
    private function normalize(mixed $value): array
    {
        if (! is_array($value) || (int) ($value['version'] ?? 0) !== self::SCHEMA_VERSION) {
            return $this->defaults();
        }

        $rawIds = is_array($value['pinned_node_ids'] ?? null)
            ? $value['pinned_node_ids']
            : [];

        $ids = [];
        $seen = [];

        foreach ($rawIds as $rawId) {
            $id = $this->normalizedNodeId($rawId);

            if (! $id || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;

            if (count($ids) >= self::MAX_PINNED) {
                break;
            }
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'pinned_node_ids' => $ids,
        ];
    }

    private function normalizedNodeId(mixed $value): ?string
    {
        $id = trim((string) $value);

        if ($id === '' || strlen($id) > 160) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9:_-]+$/', $id) === 1
            ? $id
            : null;
    }

    /**
     * @param array{version:int,pinned_node_ids:array<int,string>} $state
     * @return array{version:int,pinned_node_ids:array<int,string>}
     */
    private function persist(User $user, array $state): array
    {
        $normalized = $this->normalize($state);

        $user->forceFill([
            'map_personalization_preferences' => $normalized,
        ])->save();

        return $normalized;
    }

    /**
     * @return array{version:int,pinned_node_ids:array<int,string>}
     */
    private function defaults(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'pinned_node_ids' => [],
        ];
    }
}
