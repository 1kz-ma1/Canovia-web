<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapPinnedSpatialShortcutsV486Test extends TestCase
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

    public function test_account_pin_keeps_an_eligible_weak_plan_visible_without_mutating_plan_or_task(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '低signalでも固定するPlan', 5);
        $task = $this->task($plan, 'まだ未着手', status: 'todo', progress: 0);

        $before = [
            'priority' => (int) $plan->priority,
            'status' => (string) $task->status,
            'progress' => (int) $task->progress_percent,
        ];

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertRedirect(route('map.index'));

        $user->refresh();

        $this->assertSame([
            'version' => 1,
            'pinned_node_ids' => ['satellite:plan:'.$plan->id],
        ], $user->map_personalization_preferences);

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->viewData('graph');

        $shortcut = $graph['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id);

        $this->assertIsArray($shortcut);
        $this->assertSame('manual_pin', data_get($shortcut, 'personalization.mode'));
        $this->assertSame('pinned', data_get($shortcut, 'personalization.strength'));
        $this->assertTrue((bool) data_get($shortcut, 'personalization.pinned'));
        $this->assertSame(['固定中'], data_get($shortcut, 'personalization.reason_labels'));
        $this->assertStringContainsString('固定しているため', (string) data_get($shortcut, 'personalization.explanation'));

        $plan->refresh();
        $task->refresh();

        $this->assertSame($before, [
            'priority' => (int) $plan->priority,
            'status' => (string) $task->status,
            'progress' => (int) $task->progress_percent,
        ]);
    }

    public function test_new_plan_pin_replaces_the_previous_plan_anchor_pin_and_wins_same_anchor_projection(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $actorToken = Str::random(64);

        $strong = $this->plan($user, '自動signalが強いPlan', 1);
        $strongTask = $this->task($strong, '継続中', status: 'doing', progress: 40);

        foreach ([0, 1, 2] as $daysAgo) {
            BehaviorEvent::query()->create([
                'actor_token' => $actorToken,
                'event_type' => BehaviorEventType::WorkStarted,
                'plan_id' => $strong->id,
                'task_id' => $strongTask->id,
                'session_id' => 'v486-strong-'.$daysAgo,
                'occurred_at' => now()->subDays($daysAgo),
                'metadata' => [],
            ]);
        }

        $weak = $this->plan($user, '固定を優先するPlan', 5);
        $this->task($weak, '未着手', status: 'todo', progress: 0);

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $strong))
            ->assertRedirect(route('map.index'));

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $weak))
            ->assertRedirect(route('map.index'));

        $user->refresh();

        $this->assertSame(
            ['satellite:plan:'.$weak->id],
            data_get($user->map_personalization_preferences, 'pinned_node_ids'),
        );

        $graph = $this
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $shortcuts = $graph['nodes']
            ->filter(fn (array $node) => ($node['type'] ?? null) === 'satellite_plan')
            ->values();

        $this->assertCount(1, $shortcuts);
        $this->assertSame('satellite:plan:'.$weak->id, data_get($shortcuts, '0.id'));
        $this->assertSame('manual_pin', data_get($shortcuts, '0.personalization.mode'));
    }

    public function test_pin_does_not_resurrect_a_plan_after_candidate_eligibility_is_lost(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '完了後は復活させないPlan', 3);
        $task = $this->task($plan, '完了するTask', status: 'doing', progress: 20);

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertRedirect(route('map.index'));

        $task->forceFill([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ])->save();

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id)
        );

        $user->refresh();
        $this->assertSame(
            ['satellite:plan:'.$plan->id],
            data_get($user->map_personalization_preferences, 'pinned_node_ids'),
        );
    }

    public function test_unpin_returns_the_shortcut_to_normal_signal_rules(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'signal不足Plan', 5);
        $this->task($plan, '未着手', status: 'todo', progress: 0);

        $this->actingAs($user)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertRedirect(route('map.index'));

        $this->actingAs($user)
            ->delete(route('map.personalization.pins.destroy', $plan))
            ->assertRedirect(route('map.index'));

        $user->refresh();

        $this->assertSame([], data_get(
            $user->map_personalization_preferences,
            'pinned_node_ids',
        ));

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $this->assertNull(
            $graph['nodes']->firstWhere('id', 'satellite:plan:'.$plan->id)
        );
    }

    public function test_other_users_plan_cannot_be_pinned(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '他人のPlan', 1);
        $this->task($plan, '作業', status: 'doing', progress: 10);

        $this->actingAs($other)
            ->post(route('map.personalization.pins.store', $plan))
            ->assertNotFound();

        $other->refresh();
        $this->assertNull($other->map_personalization_preferences);
    }

    private function plan(User $user, string $title, int $priority): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '個人開発',
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status,
        int $progress,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => $progress >= 100 ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
