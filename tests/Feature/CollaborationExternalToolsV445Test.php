<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Services\CollaborationContextService;
use App\Services\CollaborationNavigationGraphService;
use App\Services\MapHierarchyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollaborationExternalToolsV445Test extends TestCase
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

    public function test_l1_collaboration_uses_four_purpose_contexts_instead_of_provider_or_category_buckets(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '共同Canovia開発', '個人開発');
        $task = $this->task($plan, '共同UIを進める');

        $this->artifact($plan, '自分担当', 'canovia', 'https://example.com/work', [
            'assigned_user_id' => $owner->id,
            'state' => 'active',
            'task' => $task,
        ]);
        $this->artifact($plan, 'レビュー対象', 'canovia', 'https://example.com/review', [
            'state' => 'review',
        ]);
        $this->artifact($plan, '相手待ち資料', 'google_drive', 'https://drive.google.com/file/d/waiting', [
            'state' => 'waiting',
        ]);
        $this->artifact($plan, 'GitHub PR', 'github', 'https://github.com/1kz-ma1/Canovia/pull/120');

        $response = $this->actingAs($owner)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'collaboration',
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l1"', false)
            ->assertSee('L1 · COLLABORATION CONTEXT')
            ->assertSee('自分が進める')
            ->assertSee('レビュー待ち')
            ->assertSee('相手待ち')
            ->assertSee('外部Toolで確認');

        $graph = $response->viewData('graph');
        $ids = $graph['nodes']->pluck('id')->all();

        $this->assertTrue((bool) $graph['collaboration_mode']);
        $this->assertSame('collaboration:hub', $graph['center_node_id']);
        $this->assertContains('collaboration:context:my_action', $ids);
        $this->assertContains('collaboration:context:review', $ids);
        $this->assertContains('collaboration:context:waiting', $ids);
        $this->assertContains('collaboration:context:external', $ids);
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'domain'
        ));
        $this->assertSame('Purpose', $this->depthLabelFromHtml($response->getContent(), 1));

        $request = Request::create('/map?level=l1&intent=collaboration', 'GET');
        $request->setUserResolver(fn () => $owner);
        $context = app(CollaborationContextService::class)->resolve($request);
        $semantic = app(CollaborationNavigationGraphService::class)
            ->build(\App\Enums\MapLevel::Domain, $context);

        $child = $semantic['nodes']->firstWhere('id', 'collaboration:context:review');
        $this->assertArrayHasKey('attention_role', $child);
        $this->assertArrayNotHasKey('position', $child);
        $this->assertArrayNotHasKey('importance', $child);
    }

    public function test_review_waiting_requires_explicit_human_state_while_github_pr_is_only_an_external_confirmation_target(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '共同リリース', '個人開発');
        $this->task($plan, 'リリース確認');

        $review = $this->artifact(
            $plan,
            '明示レビュー対象',
            'canovia',
            'https://example.com/review-me',
            ['state' => 'review'],
        );
        $github = $this->artifact(
            $plan,
            'PR #321',
            'github',
            'https://github.com/example/project/pull/321',
        );

        $reviewResponse = $this->actingAs($owner)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'collaboration',
            'collab_context' => 'review',
        ]));

        $reviewGraph = $reviewResponse->viewData('graph');
        $reviewLabels = $reviewGraph['nodes']->pluck('label')->all();

        $this->assertContains('明示レビュー対象', $reviewLabels);
        $this->assertNotContains('PR #321', $reviewLabels);
        $this->assertSame('review', $review->fresh()->collaborationState());
        $this->assertNull($github->fresh()->collaborationState());

        $externalResponse = $this->actingAs($owner)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'collaboration',
            'collab_context' => 'external',
        ]));

        $externalGraph = $externalResponse->viewData('graph');
        $githubNode = $externalGraph['nodes']->first(
            fn (array $node) => ($node['label'] ?? null) === 'PR #321'
        );

        $this->assertIsArray($githubNode);
        $this->assertSame('external', data_get($githubNode, 'direct_navigation.kind'));
        $this->assertSame('https://github.com/example/project/pull/321', data_get($githubNode, 'direct_navigation.url'));
        $this->assertStringContainsString('GitHub Pull Request', (string) $githubNode['subtitle']);
        $this->assertStringContainsString('推測せず', (string) data_get($githubNode, 'classic_surface.summary'));

        $externalResponse
            ->assertSee('data-map-action-role="external_tool"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_viewer_gets_only_explicit_assignment_not_editable_task_fallback(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $viewer = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '閲覧共同Plan', '制作活動');
        $task = $this->task($plan, '編集者なら進められるTask');

        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'joined_at' => now(),
        ]);

        $first = $this->actingAs($viewer)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'collaboration',
            'collab_context' => 'my_action',
        ]));
        $firstGraph = $first->viewData('graph');

        $this->assertCount(2, $firstGraph['nodes']);
        $this->assertSame('collaboration:context:my_action', $firstGraph['center_node_id']);
        $this->assertTrue($firstGraph['nodes']->contains(
            fn (array $node) => ($node['id'] ?? null) === 'plan:'.$plan->id
                && data_get($node, 'direct_navigation.kind') === 'zoom-in'
                && str_contains((string) data_get($node, 'direct_navigation.url'), 'level=l3')
        ));
        $this->assertFalse($firstGraph['nodes']->contains(
            fn (array $node) => str_starts_with((string) ($node['id'] ?? ''), 'collaboration:empty:')
        ));

        $artifact = $this->artifact(
            $plan,
            'Viewer担当Artifact',
            'canovia',
            'https://example.com/viewer',
            [
                'assigned_user_id' => $viewer->id,
                'state' => 'active',
                'task' => $task,
            ],
        );

        $secondGraph = $this->actingAs($viewer)
            ->get(route('map.index', [
                'level' => 'l2',
                'intent' => 'collaboration',
                'collab_context' => 'my_action',
            ]))
            ->viewData('graph');

        $this->assertTrue($secondGraph['nodes']->contains(
            fn (array $node) => ($node['label'] ?? null) === 'Viewer担当Artifact'
        ));
        $this->assertSame('active', $artifact->fresh()->collaborationState());
    }

    public function test_artifact_state_is_human_controlled_and_legacy_updates_preserve_it(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '状態管理Plan', '個人開発');

        $this->actingAs($owner)
            ->post(route('plans.artifacts.store', $plan), [
                'provider' => 'github',
                'artifact_type' => 'link',
                'title' => 'Review URL',
                'url' => 'https://github.com/example/project/pull/12',
                'collaboration_state' => 'review',
            ])
            ->assertRedirect(route('plans.artifacts.index', $plan))
            ->assertSessionHasNoErrors();

        $artifact = PlanArtifact::query()->firstOrFail();
        $this->assertSame('review', $artifact->collaborationState());

        $this->actingAs($owner)
            ->put(route('plans.artifacts.update', [$plan, $artifact]), [
                'provider' => 'github',
                'artifact_type' => 'link',
                'title' => 'Review URL updated',
                'url' => 'https://github.com/example/project/pull/12',
            ])
            ->assertRedirect(route('plans.artifacts.index', $plan))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'review',
            $artifact->fresh()->collaborationState(),
            '旧クライアントが新fieldを送らなくても明示stateを消さない',
        );

        $this->actingAs($owner)
            ->put(route('plans.artifacts.update', [$plan, $artifact]), [
                'provider' => 'github',
                'artifact_type' => 'link',
                'title' => 'Review URL updated',
                'url' => 'https://github.com/example/project/pull/12',
                'collaboration_state' => '',
            ])
            ->assertRedirect(route('plans.artifacts.index', $plan))
            ->assertSessionHasNoErrors();

        $this->assertNull($artifact->fresh()->collaborationState());
    }

    public function test_l3_and_space_station_preserve_collaboration_purpose_context(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '共同Execution', '個人開発');
        $this->task($plan, '共同Task');

        $domainKey = app(MapHierarchyContextService::class)->domainKey($plan->category);
        $params = [
            'level' => 'l3',
            'intent' => 'collaboration',
            'domain' => $domainKey,
            'plan' => $plan->id,
            'collab_context' => 'review',
        ];

        $response = $this->actingAs($owner)->get(route('map.index', $params));
        $graph = $response->viewData('graph');

        $response
            ->assertOk()
            ->assertSee('name="map_collab_context" value="review"', false);

        $this->assertSame('review', data_get($graph, 'hierarchy.collaboration_context_key'));
        $this->assertSame('レビュー待ち', data_get($graph, 'hierarchy.collaboration_context_label'));
        $this->assertStringContainsString(
            'collab_context=review',
            (string) data_get($graph, 'hierarchy.parent_url'),
        );

        $this->actingAs($owner)
            ->post(route('inbox.store'), [
                'content' => '共同Contextの途中で追加したメモ',
                'return_to' => 'map_station',
                'map_level' => 'l3',
                'map_intent' => 'collaboration',
                'map_domain' => $domainKey,
                'map_plan' => $plan->id,
                'map_collab_context' => 'review',
            ])
            ->assertRedirect(route('map.index', $params).'#dock=space-station');
    }

    public function test_collaboration_telemetry_keeps_only_structural_metadata(): void
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
                    'node_type' => 'collaboration_item',
                    'position_role' => 'hierarchy-child',
                    'action_role' => 'external_tool',
                    'is_primary' => false,
                    'artifact_title' => '保存しないArtifact名',
                    'external_url' => 'https://github.com/private/repo/pull/1',
                    'collaboration_state' => 'review',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapClassicActionOpened->value)
            ->firstOrFail();

        $this->assertSame('collaboration_item', data_get($event->metadata, 'node_type'));
        $this->assertSame('hierarchy-child', data_get($event->metadata, 'position_role'));
        $this->assertSame('external_tool', data_get($event->metadata, 'action_role'));
        $this->assertArrayNotHasKey('artifact_title', $event->metadata);
        $this->assertArrayNotHasKey('external_url', $event->metadata);
        $this->assertArrayNotHasKey('collaboration_state', $event->metadata);
    }

    private function plan(User $owner, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の説明',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => true,
            'collaboration_join_code' => 'CNV-'.strtoupper(Str::random(6)),
            'collaboration_share_token' => Str::random(48),
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 10,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    /**
     * @param array{assigned_user_id?:int,state?:string,task?:Task} $options
     */
    private function artifact(
        Plan $plan,
        string $title,
        string $provider,
        string $url,
        array $options = [],
    ): PlanArtifact {
        $metadata = isset($options['state'])
            ? ['collaboration_state' => $options['state']]
            : null;

        $artifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $plan->user_id,
            'assigned_user_id' => $options['assigned_user_id'] ?? null,
            'provider' => $provider,
            'artifact_type' => $provider === 'github' ? 'link' : 'file',
            'title' => $title,
            'url' => $url,
            'metadata' => $metadata,
        ]);

        if (($options['task'] ?? null) instanceof Task) {
            $artifact->tasks()->sync([(int) $options['task']->id]);
        }

        return $artifact;
    }

    private function depthLabelFromHtml(string $html, int $depth): ?string
    {
        if (! preg_match('/title="L'.preg_quote((string) $depth, '/').' · ([^"]+)"/u', $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }
}
