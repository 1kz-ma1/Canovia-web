<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapCollaborationCandidateExpansionV489Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_explicit_recent_review_state_can_promote_existing_collaboration_purpose(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->sharedPlan($owner, '重要な共同Plan', 1);
        $this->artifact($plan, 'レビュー対象', 'review', 0);

        $response = $this->actingAs($owner)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-map-node-type="satellite_collaboration"', false)
            ->assertSee('レビュー待ち')
            ->assertSee('最近レビュー待ちになった');

        $graph = $response->viewData('graph');
        $shortcut = $graph['nodes']->firstWhere('id', 'satellite:collaboration:review');

        $this->assertIsArray($shortcut);
        $this->assertSame('satellite_collaboration', $shortcut['type']);
        $this->assertSame('satellite-4', $shortcut['position_role']);
        $this->assertSame('intent:collaboration', data_get(
            $graph['edges']->firstWhere('target', 'satellite:collaboration:review'),
            'source',
        ));
        $this->assertSame(
            route('map.index', [
                'level' => 'l1',
                'intent' => 'collaboration',
                'collab_context' => 'review',
            ]),
            data_get($shortcut, 'direct_navigation.url'),
        );
        $this->assertContains(
            '最近レビュー待ちになった',
            data_get($shortcut, 'personalization.reason_labels', []),
        );
        $this->assertStringContainsString(
            'レビュー待ちへ直接戻る近道',
            (string) data_get($shortcut, 'personalization.explanation'),
        );
    }

    public function test_github_pull_request_without_explicit_review_state_never_becomes_review_candidate(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->sharedPlan($owner, 'GitHub共同Plan', 1);

        PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $owner->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'PR #999',
            'url' => 'https://github.com/example/project/pull/999',
            'metadata' => null,
        ]);

        $graph = $this->actingAs($owner)
            ->get(route('map.index'))
            ->assertOk()
            ->viewData('graph');

        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'satellite:collaboration:review')
        );
    }

    public function test_stale_low_importance_review_state_stays_below_existing_threshold(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->sharedPlan($owner, '低優先度の古いレビュー', 5);
        $this->artifact($plan, '古いレビュー対象', 'review', 45);

        $graph = $this->actingAs($owner)
            ->get(route('map.index'))
            ->assertOk()
            ->viewData('graph');

        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'satellite:collaboration:review')
        );
    }

    public function test_collaboration_satellite_telemetry_keeps_only_structural_values(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapClassicActionOpened->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'web',
                    'device' => 'desktop',
                    'node_type' => 'satellite_collaboration',
                    'position_role' => 'satellite-4',
                    'action_role' => 'satellite',
                    'review_count' => 4,
                    'artifact_title' => '保存しない',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapClassicActionOpened->value)
            ->firstOrFail();

        $this->assertSame('satellite_collaboration', data_get($event->metadata, 'node_type'));
        $this->assertSame('satellite-4', data_get($event->metadata, 'position_role'));
        $this->assertSame('satellite', data_get($event->metadata, 'action_role'));
        $this->assertArrayNotHasKey('review_count', $event->metadata);
        $this->assertArrayNotHasKey('artifact_title', $event->metadata);
    }

    private function sharedPlan(User $owner, string $title, int $priority): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '共同制作',
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today()->subMonth(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => true,
            'collaboration_join_code' => 'CNV-'.strtoupper(Str::random(6)),
            'collaboration_share_token' => Str::random(48),
        ]);
    }

    private function artifact(
        Plan $plan,
        string $title,
        string $state,
        int $daysAgo,
    ): PlanArtifact {
        $artifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $plan->user_id,
            'provider' => 'canovia',
            'artifact_type' => 'file',
            'title' => $title,
            'url' => 'https://example.com/'.Str::slug($title),
            'metadata' => ['collaboration_state' => $state],
        ]);

        $artifact->forceFill([
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ])->save();

        return $artifact;
    }
}
