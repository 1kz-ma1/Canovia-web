<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\InboxItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $contextCandidate = $this->contextCandidate($request, $editablePlans);
        $routeResult = $this->routeResult($request, $editablePlans);

        return [
            'pending_count' => $pendingCount,
            'latest_item' => $latestItem,
            'routing_candidate' => $routingCandidate,
            'routing_destinations' => InboxIntelligenceService::PUBLIC_DESTINATIONS,
            'execution_actor_types' => ExecutionPacketService::ACTOR_TYPES,
            'editable_plans' => $editablePlans,
            'context_candidate' => $contextCandidate,
            'route_result' => $routeResult,
            'can_use_inbox_ai' => $canUseInboxAi,
            'can_use_companion' => $canUseCompanion,
            'companion_published' => $companionPublished,
            'state_key' => $this->stateKey(
                $latestItem,
                $routingCandidate,
                $contextCandidate,
                $routeResult,
                $pendingCount,
            ),
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
            'execution_request' => ['execution', '実行'],
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
     * Current Map context is only a candidate. It never mutates Inbox/Plan/Task
     * until the user submits the confirmation form.
     *
     * @param Collection<int,mixed> $editablePlans
     * @return array<string,mixed>|null
     */
    private function contextCandidate(Request $request, Collection $editablePlans): ?array
    {
        $planId = max(0, (int) $request->query('plan', 0));
        if ($planId <= 0) {
            return null;
        }

        $plan = $editablePlans->firstWhere('id', $planId);
        if (! $plan) {
            return null;
        }

        return [
            'plan_id' => (int) $plan->id,
            'plan_title' => (string) $plan->title,
            'intent' => trim((string) $request->query('intent', '')),
            'map_level' => trim((string) $request->query('level', '')),
            'source' => 'current_map_context',
        ];
    }

    /**
     * A confirmed routing result is flash-only presentation state. Canonical
     * creation already happened inside InboxRoutingService after human confirm.
     *
     * @param Collection<int,mixed> $editablePlans
     * @return array<string,mixed>|null
     */
    private function routeResult(Request $request, Collection $editablePlans): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $payload = $request->session()->get('space_station_route_result');
        if (! is_array($payload)) {
            return null;
        }

        $destination = (string) ($payload['destination'] ?? '');
        if (! array_key_exists($destination, InboxIntelligenceService::DESTINATIONS)) {
            return null;
        }

        $planId = max(0, (int) ($payload['plan_id'] ?? 0));
        $taskId = max(0, (int) ($payload['task_id'] ?? 0));
        $plan = $planId > 0 ? $editablePlans->firstWhere('id', $planId) : null;
        $task = $plan && $taskId > 0
            ? $plan->tasks->firstWhere('id', $taskId)
            : null;

        [$actionLabel, $actionUrl] = match ($destination) {
            'task_evidence' => $plan && $task
                ? [
                    '関連TaskをMapで見る',
                    route('map.index', [
                        'level' => 'l3',
                        'intent' => 'execution',
                        'plan' => $plan->id,
                    ]).'#focus='.rawurlencode('task:'.$task->id),
                ]
                : [null, null],
            'recall_material' => $plan && $task
                ? ['Recallを確認', route('plans.tasks.study_recall.show', [$plan, $task])]
                : [null, null],
            'plan_resource' => $plan
                ? ['Resourceを確認', route('plans.resources.index', $plan)]
                : [null, null],
            'career_capture' => $plan
                ? ['Careerを確認', route('plans.career.index', $plan)]
                : [null, null],
            'keep_inbox' => ['Inboxを確認', route('inbox.index')],
            default => $plan
                ? [
                    '関連PlanをMapで見る',
                    route('map.index', [
                        'level' => 'l3',
                        'intent' => 'execution',
                        'plan' => $plan->id,
                    ]),
                ]
                : [null, null],
        };

        return [
            'destination' => $destination,
            'destination_label' => InboxIntelligenceService::DESTINATIONS[$destination],
            'plan_id' => $plan?->id,
            'plan_title' => $plan?->title,
            'task_id' => $task?->id,
            'task_title' => $task?->title,
            'message' => mb_substr(trim((string) ($payload['message'] ?? '')), 0, 1000),
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
        ];
    }

    /**
     * projection_keyにuser contentを直接含めず、routing stateだけを反映する。
     *
     * @param array<string,mixed>|null $candidate
     * @param array<string,mixed>|null $contextCandidate
     * @param array<string,mixed>|null $routeResult
     */
    private function stateKey(
        ?InboxItem $item,
        ?array $candidate,
        ?array $contextCandidate,
        ?array $routeResult,
        int $pendingCount,
    ): string {
        return hash('sha256', (string) json_encode([
            'pending_count' => $pendingCount,
            'latest_item_id' => $item?->id,
            'latest_item_status' => $item?->status,
            'destination' => $candidate['destination'] ?? null,
            'intent_key' => $candidate['intent_key'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'context_plan_id' => $contextCandidate['plan_id'] ?? null,
            'confirmed_destination' => $routeResult['destination'] ?? null,
            'confirmed_plan_id' => $routeResult['plan_id'] ?? null,
            'confirmed_task_id' => $routeResult['task_id'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
