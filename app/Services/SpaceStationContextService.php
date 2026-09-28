<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\InboxItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class SpaceStationContextService
{
    public function __construct(
        private readonly BehaviorIdentityService $identity,
        private readonly PlanOwnershipService $ownership,
        private readonly FeatureAccessService $access,
        private readonly FeatureFlagService $flags,
        private readonly NativeAiGateway $nativeAi,
    ) {}

    /**
     * Resolve dynamic Space Station state without changing the fixed L0 graph.
     *
     * @return array<string,mixed>
     */
    public function build(Request $request): array
    {
        $actorToken = $this->identity->resolve($request);
        $userId = $request->user()?->id;

        $items = $this->ownInboxItems($userId, $actorToken)
            ->with('plan')
            ->whereIn('status', ['new', 'review'])
            ->latest('id');

        $pendingCount = (clone $items)->count();
        $latestItem = $items->first();

        $editablePlans = $this->ownership
            ->ownedPlans($request, [
                'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->filter(fn ($plan) => $this->ownership->canEdit($request, $plan))
            ->values();

        $suggestion = $latestItem
            ? data_get($latestItem->metadata, 'routing_suggestion')
            : null;
        $suggestion = is_array($suggestion) ? $suggestion : null;

        $canUseInboxAi = $this->nativeAi->isConfigured()
            && $this->access->canUse($request->user(), FeatureKey::AutomaticAiExecution);

        $companionPublished = $this->flags->isEnabled(FeatureKey::CanoviaCompanion);
        $canUseCompanion = $request->user() !== null
            && $companionPublished
            && $this->nativeAi->isConfigured()
            && $this->access->canUse($request->user(), FeatureKey::CanoviaCompanion)
            && $this->access->canUse($request->user(), FeatureKey::AutomaticAiExecution);

        $routingCandidate = $suggestion
            ? $this->routingCandidate($suggestion)
            : null;

        return [
            'pending_count' => $pendingCount,
            'latest_item' => $latestItem,
            'routing_candidate' => $routingCandidate,
            'routing_destinations' => InboxIntelligenceService::PUBLIC_DESTINATIONS,
            'editable_plans' => $editablePlans,
            'can_use_inbox_ai' => $canUseInboxAi,
            'can_use_companion' => $canUseCompanion,
            'companion_published' => $companionPublished,
            'state_key' => $this->stateKey($latestItem, $routingCandidate, $pendingCount),
        ];
    }

    private function ownInboxItems(?int $userId, string $actorToken): Builder
    {
        return InboxItem::query()->where(function (Builder $query) use ($userId, $actorToken) {
            if ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('actor_token', $actorToken);
                return;
            }

            $query->whereNull('user_id')->where('actor_token', $actorToken);
        });
    }

    /**
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed>
     */
    private function routingCandidate(array $suggestion): array
    {
        $destination = (string) ($suggestion['destination'] ?? 'keep_inbox');
        if (! array_key_exists($destination, InboxIntelligenceService::PUBLIC_DESTINATIONS)) {
            $destination = 'keep_inbox';
        }

        [$intentKey, $intentLabel] = match ($destination) {
            'task_evidence' => ['reflection', '振り返り'],
            'recall_material' => ['execution', '実行'],
            'career_capture', 'plan_resource' => ['plan', '計画'],
            default => ['space_station', 'Space Station'],
        };

        return [
            'destination' => $destination,
            'destination_label' => InboxIntelligenceService::PUBLIC_DESTINATIONS[$destination],
            'intent_key' => $intentKey,
            'intent_label' => $intentLabel,
            'reason' => mb_substr(trim((string) ($suggestion['reason'] ?? '')), 0, 1000),
            'confidence' => max(0, min(100, (int) ($suggestion['confidence'] ?? 50))),
            'suggested_plan_title' => filled($suggestion['suggested_plan_title'] ?? null)
                ? mb_substr(trim((string) $suggestion['suggested_plan_title']), 0, 255)
                : null,
            'suggested_task_title' => filled($suggestion['suggested_task_title'] ?? null)
                ? mb_substr(trim((string) $suggestion['suggested_task_title']), 0, 255)
                : null,
        ];
    }

    /**
     * projection_keyにuser contentを直接含めず、routing stateだけを反映する。
     *
     * @param array<string,mixed>|null $candidate
     */
    private function stateKey(?InboxItem $item, ?array $candidate, int $pendingCount): string
    {
        return hash('sha256', (string) json_encode([
            'pending_count' => $pendingCount,
            'latest_item_id' => $item?->id,
            'latest_item_status' => $item?->status,
            'destination' => $candidate['destination'] ?? null,
            'intent_key' => $candidate['intent_key'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
