<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Http\Request;

final class MapShortcutPinProjectionService
{
    public function __construct(
        private readonly MapPersonalizationPreferenceService $preferences,
    ) {}

    /**
     * Add account-level shortcut pin controls to canonical Plan nodes without
     * changing Plan / Task state or creating new Map entities.
     *
     * @param array<string,mixed> $projection
     * @return array<string,mixed>
     */
    public function decorate(Request $request, array $projection): array
    {
        $user = $request->user();

        if (! $user) {
            return $projection;
        }

        $nodes = collect($projection['nodes'] ?? []);
        $planIds = $nodes
            ->filter(fn ($node) => is_array($node)
                && ($node['type'] ?? null) === 'plan'
                && (int) ($node['entity_id'] ?? 0) > 0)
            ->map(fn (array $node) => (int) $node['entity_id'])
            ->unique()
            ->values();

        if ($planIds->isEmpty()) {
            return $projection;
        }

        $plans = Plan::query()
            ->where('user_id', $user->id)
            ->where('is_collaborative', false)
            ->whereIn('id', $planIds->all())
            ->withCount([
                'tasks as shortcut_eligible_task_count' => fn ($query) => $query
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->where('progress_percent', '<', 100),
            ])
            ->get()
            ->keyBy('id');

        $pinned = array_fill_keys(
            $this->preferences->pinnedNodeIds($user),
            true,
        );

        $nodes = $nodes
            ->map(function ($node) use ($plans, $pinned) {
                if (! is_array($node) || ($node['type'] ?? null) !== 'plan') {
                    return $node;
                }

                $planId = (int) ($node['entity_id'] ?? 0);
                /** @var Plan|null $plan */
                $plan = $plans->get($planId);

                if (! $plan) {
                    return $node;
                }

                $shortcutNodeId = $this->preferences->planNodeId($planId);
                $isPinned = isset($pinned[$shortcutNodeId]);
                $eligible = (int) ($plan->shortcut_eligible_task_count ?? 0) > 0;

                if (! $eligible && ! $isPinned) {
                    return $node;
                }

                $node['shortcut_pin'] = [
                    'node_id' => $shortcutNodeId,
                    'eligible' => $eligible,
                    'pinned' => $isPinned,
                    'status' => match (true) {
                        $eligible && $isPinned => 'pinned',
                        $isPinned => 'inactive',
                        default => 'available',
                    },
                ];

                return $node;
            })
            ->values();

        $projection['nodes'] = $nodes;

        $pinState = $nodes
            ->filter(fn ($node) => is_array($node) && is_array($node['shortcut_pin'] ?? null))
            ->map(fn (array $node) => [
                'id' => (string) ($node['id'] ?? ''),
                'shortcut_pin' => $node['shortcut_pin'],
            ])
            ->values()
            ->all();

        if ($pinState !== []) {
            $projection['projection_key'] = hash('sha256', (string) json_encode([
                'base' => (string) ($projection['projection_key'] ?? ''),
                'shortcut_pins' => $pinState,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $projection;
    }
}
