<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Services\DevelopmentTeamTaskProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentTeamTaskDependencyV5869Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_team_surface_shows_actual_unfinished_prerequisites_without_inventing_a_task_assignee(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'HINANEX', true);
        $member = User::factory()->create(['first_run_completed_at' => now()]);
        $this->membership($plan, $owner, $member, PlanMember::ROLE_EDITOR);

        $source = $this->task($plan, 'Aのデータ正規化', 'doing');
        $waiting = $this->task($plan, 'Cの検証ゲート', 'todo');
        $waiting->prerequisites()->sync([$source->id]);
        $completed = $this->task($plan, '環境準備', 'done');
        $unblocked = $this->task($plan, 'UI表示', 'doing');
        $unblocked->prerequisites()->sync([$completed->id]);

        $before = Task::query()->where('plan_id', $plan->id)->pluck('status', 'id')->all();

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('data-development-team-task-overview', false)
            ->assertSee('data-development-team-task-summary', false)
            ->assertSee('未完了 3件')
            ->assertSee('完了 1件')
            ->assertSee('確認した前提待ち 1件')
            ->assertSee('data-development-team-task="'.$waiting->id.'"', false)
            ->assertSee('data-development-team-task-blocked="true"', false)
            ->assertSee('Aのデータ正規化')
            ->assertSee('Cの検証ゲート')
            ->assertSee('data-development-team-task="'.$unblocked->id.'"', false)
            ->assertSee('Task担当者を推測して埋めることはしません。')
            ->assertSee('担当者はTaskへ直接記録されていないため推測しません。');

        $this->assertSame($before, Task::query()->where('plan_id', $plan->id)->pluck('status', 'id')->all());
        $this->assertSame(100, $completed->fresh()->progress_percent);
        $this->assertSame(PlanMember::ROLE_EDITOR, $plan->memberships()->first()->role);
    }

    public function test_completed_prerequisites_unblock_a_task_and_legacy_dependency_is_respected(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, 'チーム開発', true);
        $base = $this->task($plan, 'API契約', 'todo');
        $dependent = $this->task($plan, '画面統合', 'todo');
        $dependent->update(['depends_on_task_id' => $base->id]);

        $service = app(DevelopmentTeamTaskProjectionService::class);
        $initial = $service->project($plan);
        $this->assertSame(1, $initial['blocked_count']);
        $item = $initial['tasks']->firstWhere('id', $dependent->id);
        $this->assertTrue($item['is_blocked']);
        $this->assertSame('API契約', $item['blocked_by'][0]['title']);

        $base->update(['status' => 'done']);
        $next = $service->project($plan);
        $this->assertSame(0, $next['blocked_count']);
        $this->assertFalse($next['tasks']->firstWhere('id', $dependent->id)['is_blocked']);
    }

    public function test_cross_plan_dependencies_must_not_leak_another_plans_task_names(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '公開チーム開発', true);
        $otherOwner = User::factory()->create();
        $private = $this->plan($otherOwner, '非公開', true);
        $secret = $this->task($private, '非公開の秘密Task', 'todo');
        $visible = $this->task($plan, '自分のTask', 'todo');
        $visible->prerequisites()->sync([$secret->id]);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('自分のTask')
            ->assertDontSee('非公開の秘密Task')
            ->assertSee('data-development-team-task-blocked="false"', false);
    }

    public function test_viewer_can_inspect_shared_task_blockers_without_gaining_edit_permission(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $plan = $this->plan($owner, 'HINANEX', true);
        $this->membership($plan, $owner, $viewer, PlanMember::ROLE_VIEWER);
        $blocked = $this->task($plan, '待ちタスク', 'paused');

        $this->actingAs($viewer)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('data-development-team-task="'.$blocked->id.'"', false)
            ->assertSee('確認した保留 1件')
            ->assertSee('保留中です。再開条件をチームで確認してください。');

        $this->actingAs(User::factory()->create())
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'team',
            ]))
            ->assertNotFound();

        $this->assertSame(PlanMember::ROLE_VIEWER, $plan->memberships()->first()->role);
    }

    public function test_solo_development_does_not_get_misleading_team_task_overview(): void
    {
        $owner = User::factory()->create();
        $solo = $this->plan($owner, '一人で開発', false);
        $this->task($solo, '自分の実装', 'todo');

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $solo->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertDontSee('data-development-team-task-overview', false);

        $this->assertSame(0, app(DevelopmentTeamTaskProjectionService::class)->project($solo)['total_open']);
    }

    private function membership(Plan $plan, User $owner, User $member, string $role): void
    {
        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $member->id,
            'role' => $role,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);
    }

    private function plan(User $owner, string $title, bool $collaborative): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'ソフトウェア開発',
            'category' => 'ソフトウェア開発',
            'is_collaborative' => $collaborative,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(30),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title, string $status): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'status' => $status,
            'estimated_minutes' => 90,
            'remaining_minutes' => $status === 'done' ? 0 : 90,
            'progress_percent' => $status === 'done' ? 100 : 0,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
