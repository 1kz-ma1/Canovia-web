<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapV420Test extends TestCase
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

    public function test_map_projects_existing_plan_task_evidence_and_inbox_without_replacing_home(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia V42を形にする',
            'description' => 'Living Goal Mapを検証する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $current = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Map Prototypeを作る',
            'description' => '既存データからMapを投影する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 60,
            'progress_percent' => 40,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'next_action_note' => '読み取り専用Mapを完成させる',
            'sort_order' => 1,
        ]);

        Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $current->id,
            'title' => 'Focus Modeを追加する',
            'description' => '選択ノードの直接関係だけを展開する',
            'estimated_minutes' => 90,
            'remaining_minutes' => 90,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 2,
        ]);

        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $current->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'focus_session_completed',
            'external_key' => 'map-test-evidence',
            'confidence' => 0.8,
            'occurred_at' => now(),
            'metadata' => ['actual_minutes' => 25],
        ]);

        InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => 'Mapに反映するメモ',
            'content' => '入力は左側へ置く',
            'metadata' => [],
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('Canovia Map')
            ->assertSee('Map Prototypeを作る')
            ->assertSee('Focus Modeを追加する')
            ->assertSee('集中作業')
            ->assertSee('Mapに反映するメモ')
            ->assertSee('data-map-node-type="task"', false)
            ->assertSee('data-map-position-role="now"', false)
            ->assertSee('Classic Home');

        $this->assertSame(url('/'), route('home'));
        $this->assertSame(url('/map'), route('map.index'));
    }

    public function test_map_is_protected_by_first_run_gate_for_unintroduced_account(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertRedirect(route('first_run.show'));
    }
}
