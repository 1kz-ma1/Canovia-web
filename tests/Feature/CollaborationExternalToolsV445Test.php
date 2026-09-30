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

    public function test_l1_collaboration_lists_shared_projects_before_operational_purposes(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '共同Canovia開発', '個人開発');
        $task = $this->task($plan, '共同UIを進める');

        $this->artifact($plan, 'レビュー対象', 'canovia', 'https://example.com/review', [
            'state' => 'review',
            'task' => $task,
        ]);

        $response = $this->actingAs($owner)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'collaboration',
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l1"', false)
            ->assertSee('L1 · SHARED PROJECTS')
            ->assertSee('共同Canovia開発')
            ->assertSee('共同計画を作る');

        $graph = $response->viewData('graph');
        $ids = $graph['nodes']->pluck('id')->all();

        $this->assertTrue((bool) $graph['collaboration_mode']);
        $this->assertSame('collaboration:hub', $graph['center_node_id']);
        $this->assertContains('collaboration:project:'.$plan->id, $ids);
        $this->assertContains('collaboration:create-project', $ids);
        $this->assertNotContains('collaboration:context:my_action', $ids);
        $this->assertNotContains('collaboration:context:review', $ids);
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'domain'
        ));

        $request = Request::create('/map?level=l1&intent=collaboration', 'GET');
        $request->setUserResolver(fn () => $owner);
        $context = app(CollaborationContextService::class)->resolve($request);
        $semantic = app(CollaborationNavigationGraphService::class)
            ->build(AppEnumsMapLevel::Domain, $context);

        $child = $semantic['nodes']->firstWhere('id', 'collaboration:project:'.$plan->id);
        $this->assertArrayHasKey('attention_role', $child);
        $this->assertArrayNotHasKey('position', $child);
        $this->assertArrayNotHasKey('importance', $child);
    }

    public function test_review_waiting_still_requires_explicit_human_state_inside_project_first_navigation(): void
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

        $request = Request::create('/map?level=l1&intent=collaboration&collab_context=review', 'GET');
        $request->setUserResolver(fn () => $owner);
        $context = app(CollaborationContextService::class)->resolve($request);

        $reviewItems = collect(data_get($context, 'selected_context.items', []));
        $this->assertTrue($reviewItems->contains(
            fn (array $item) => ($item['label'] ?? null) === '明示レビュー対象'
        ));
        $this->assertFalse($reviewItems->contains(
            fn (array $item) => ($item['label'] ?? null) === 'PR #321'
        ));
        $this->assertSame('review', $review->fresh()->collaborationState());
        $this->assertNull($github->fresh()->collaborationState());

        $projects = collect($context['projects'] ?? []);
        $project = $projects->firstWhere('id', $plan->id);
        $this->assertSame(1, (int) data_get($project, 'review_count'));

        $externalRequest = Request::create('/map?level=l1&intent=collaboration&collab_context=external', 'GET');
        $externalRequest->setUserResolver(fn () => $owner);
        $external = app(CollaborationContextService::class)->resolve($externalRequest);
        $externalItems = collect(data_get($external, 'selected_context.items', []));

        $githubItem = $externalItems->first(
            fn (array $item) => ($item['label'] ?? null) === 'PR #321'
        );

        $this->assertIsArray($githubItem);
        $this->assertSame('GitHub Pull Request', $githubItem['external_kind']);
        $this->assertSame('https://github.com/example/project/pull/321', $githubItem['url']);
    }

    public function test_viewer_gets_project_visibility_but_my_action_still_requires_explicit_assignment(): void
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

        $request = Request::create('/map?level=l1&intent=collaboration&collab_context=my_action', 'GET');
        $request->setUserResolver(fn () => $viewer);
        $first = app(CollaborationContextService::class)->resolve($request);

        $this->assertCount(1, collect($first['projects'] ?? []));
        $this->assertCount(0, collect(data_get($first, 'selected_context.items', [])));

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

        $second = app(CollaborationContextService::class)->resolve($request);
        $items = collect(data_get($second, 'selected_context.items', []));

        $this->assertTrue($items->contains(
            fn (array $item) => ($item['label'] ?? null) === 'Viewer担当Artifact'
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
