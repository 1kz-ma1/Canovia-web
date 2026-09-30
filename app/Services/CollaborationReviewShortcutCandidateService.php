<?php

namespace App\Services;

use App\Enums\MapLevel;
use App\Models\Plan;
use App\Models\PlanArtifact;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class CollaborationReviewShortcutCandidateService
{
    public function __construct(
        private readonly PlanOwnershipService $ownership,
        private readonly PlanPriorityService $priorities,
    ) {}

    /**
     * Build an L0 candidate for the existing Collaboration "レビュー待ち"
     * purpose only from human-explicit collaboration_state=review artifacts.
     *
     * @return array<string,mixed>|null
     */
    public function candidate(Request $request): ?array
    {
        $plans = $this->ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
            'artifacts' => fn ($query) => $query
                ->latest('updated_at')
                ->latest('id'),
        ])
            ->filter(fn (Plan $plan) => (bool) $plan->is_collaborative)
            ->values();

        if ($plans->isEmpty()) {
            return null;
        }

        $reviews = $plans
            ->flatMap(fn (Plan $plan) => $plan->artifacts
                ->filter(fn (PlanArtifact $artifact) => $artifact->collaborationState() === 'review')
                ->map(fn (PlanArtifact $artifact) => [
                    'plan' => $plan,
                    'plan_id' => (int) $plan->id,
                    'artifact_id' => (int) $artifact->id,
                    'updated_at' => $artifact->updated_at,
                ]))
            ->sortByDesc(fn (array $item) => $item['updated_at']?->timestamp ?? 0)
            ->values();

        if ($reviews->isEmpty()) {
            return null;
        }

        $representedPlans = $reviews
            ->pluck('plan')
            ->filter(fn ($plan) => $plan instanceof Plan)
            ->unique(fn (Plan $plan) => (int) $plan->id)
            ->values();

        $importance = (float) ($representedPlans
            ->map(fn (Plan $plan) => $this->planImportance($plan))
            ->max() ?? 0.0);

        $latest = $reviews
            ->pluck('updated_at')
            ->filter(fn ($time) => $time instanceof CarbonInterface)
            ->sortByDesc(fn (CarbonInterface $time) => $time->timestamp)
            ->first();

        $signals = [
            'importance' => round(max(0, min(1, $importance)), 4),
            'usage_frequency' => round(min(1, $reviews->count() / 3), 4),
            'recency' => round($this->recencyScore($latest), 4),
            // Explicit review state remains active until a human changes it.
            // Multiple review items/plans strengthen the sense of an ongoing queue.
            'continuity' => round(min(
                1,
                0.55
                    + max(0, $reviews->count() - 1) * 0.10
                    + max(0, $representedPlans->count() - 1) * 0.10,
            ), 4),
        ];

        $url = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => 'collaboration',
            'collab_context' => 'review',
        ]);

        return [
            'id' => 'satellite:collaboration:review',
            'kind' => 'collaboration',
            'node_type' => 'satellite_collaboration',
            'entity_id' => null,
            'plan_id' => null,
            'task_id' => null,
            'eyebrow' => 'COLLABORATION',
            'label' => 'レビュー待ち',
            'subtitle' => '確認が必要な共同作業へ戻る',
            'available_action' => $url,
            'navigation_kind' => 'satellite',
            'anchor_node_id' => 'intent:collaboration',
            'signals' => $signals,
            'signal_reason_labels' => [
                'importance' => '重要な共同Planにレビューがある',
                'usage_frequency' => 'レビュー項目がまとまっている',
                'recency' => '最近レビュー待ちになった',
                'continuity' => 'レビュー待ちが継続中',
            ],
            'explanation_suffix' => 'ため、レビュー待ちへ直接戻る近道として表示しています。',
            'classic_surface' => [
                'kind' => 'Collaboration Shortcut',
                'title' => 'レビュー待ち',
                'summary' => 'Canovia上で人が明示的に「レビュー待ち」とした共同Artifactへ戻るためのショートカットです。外部サービスの状態は推測しません。',
                'actions' => [
                    [
                        'label' => 'レビュー待ちを見る',
                        'url' => $url,
                        'primary' => true,
                        'navigation_kind' => 'satellite',
                    ],
                    [
                        'label' => '共同Plan一覧を開く',
                        'url' => route('my_plans.index'),
                        'primary' => false,
                    ],
                ],
                'meta' => [
                    $reviews->count().'件のレビュー待ち',
                    $representedPlans->count().' Shared Plan',
                ],
            ],
        ];
    }

    private function planImportance(Plan $plan): float
    {
        $priority = (int) data_get($this->priorities->evaluate($plan), 'priority', 3);

        return (6 - max(1, min(5, $priority))) / 5;
    }

    private function recencyScore(?CarbonInterface $time): float
    {
        if (! $time) {
            return 0.0;
        }

        $days = max(0, (int) $time->diffInDays(now()));

        return match (true) {
            $days <= 1 => 1.0,
            $days <= 3 => 0.85,
            $days <= 7 => 0.65,
            $days <= 14 => 0.45,
            $days <= 30 => 0.25,
            default => 0.05,
        };
    }
}
