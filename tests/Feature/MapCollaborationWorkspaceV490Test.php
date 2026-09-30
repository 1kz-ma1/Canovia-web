<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapCollaborationWorkspaceV490Test extends TestCase
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

    public function test_one_shared_plan_still_uses_project_selection_level_before_workspace(): void
    {
        $owner = User::factory()->create([
            'name' => 'Project Owner',
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->sharedPlan($owner, '共同Map刷新');

        $response = $this->actingAs($owner)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'collaboration',
        ]));

        $response
            ->assertOk()
            ->assertSee('L1 · SHARED PROJECTS')
            ->assertSee('共同Map刷新')
            ->assertSee('共同計画を作る');

        $graph = $response->viewData('graph');

        $this->assertSame('collaboration:hub', $graph['center_node_id']);
        $this->assertNotNull(
            $graph['nodes']->firstWhere('id', 'collaboration:project:'.$plan->id)
        );
        $this->assertNotNull(
            $graph['nodes']->firstWhere('id', 'collaboration:create-project')
        );
        $this->assertFalse((bool) ($graph['collaboration_workspace_mode'] ?? false));
    }

    public function test_selected_shared_plan_opens_palette_workspace_instead_of_purpose_nodes(): void
    {
        $owner = User::factory()->create([
            'name' => 'Project Owner',
            'email' => 'owner@example.test',
            'first_run_completed_at' => now(),
        ]);
        $member = User::factory()->create([
            'name' => 'Map Editor',
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->sharedPlan($owner, '共同Palette Project');

        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $member->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);

        PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $member->id,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'Map Workspace PR',
            'url' => 'https://github.com/example/canovia/pull/490',
            'metadata' => ['collaboration_state' => 'review'],
        ]);

        $response = $this->actingAs($owner)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'collaboration',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('L2 · PROJECT WORKSPACE')
            ->assertSee('data-map-collaboration-workspace', false)
            ->assertSee('制作ファイル / 成果物')
            ->assertSee('参加メンバー')
            ->assertSee('最新情報')
            ->assertSee('Map Workspace PR')
            ->assertSee('Map Editor')
            ->assertSee('共同設定')
            ->assertSee('Classic Plan');

        $graph = $response->viewData('graph');

        $this->assertTrue((bool) ($graph['collaboration_workspace_mode'] ?? false));
        $this->assertSame($plan->id, data_get($graph, 'hierarchy.collaboration_project_id'));
        $this->assertCount(1, $graph['nodes']);
        $this->assertSame(
            'collaboration:project:'.$plan->id,
            $graph['center_node_id'],
        );
        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'collaboration:context:review')
        );
    }

    public function test_member_can_open_project_workspace_but_does_not_receive_owner_only_copy(): void
    {
        $owner = User::factory()->create([
            'name' => 'Owner',
            'first_run_completed_at' => now(),
        ]);
        $viewer = User::factory()->create([
            'name' => 'Viewer',
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->sharedPlan($owner, '閲覧Project');

        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'collaboration',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('閲覧Project')
            ->assertSee('閲覧者')
            ->assertSee('参加メンバー');

        $workspace = $response->viewData('graph')['collaboration_workspace'];
        $this->assertFalse((bool) $workspace['can_manage']);
        $this->assertFalse((bool) $workspace['can_edit']);
        $this->assertSame('viewer', $workspace['role']);
    }

    public function test_collaboration_plus_opens_manual_create_with_collaboration_preselected(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['collaborative' => 1]))
            ->assertOk()
            ->assertSee('name="is_collaborative"', false)
            ->assertSee('checked', false)
            ->assertSee('共同計画として作る');
    }

    private function sharedPlan(User $owner, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '共同制作',
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
}
