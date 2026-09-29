<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReflectionMapV474Test extends TestCase
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

    public function test_reflection_l1_uses_lenses_instead_of_plan_domains(): void
    {
        [$user, $plan, $active, $done] = $this->scenario();

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'reflection',
        ]));

        $response
            ->assertOk()
            ->assertSee('L1 · REFLECTION LENS')
            ->assertSee('最近の実績')
            ->assertSee('Evidence')
            ->assertSee('完了Task')
            ->assertSee('振り返り記録');

        $graph = $response->viewData('graph');
        $ids = $graph['nodes']->pluck('id')->all();

        $this->assertTrue($graph['reflection_mode']);
        $this->assertSame('reflection:hub', $graph['center_node_id']);
        $this->assertContains('reflection:context:recent', $ids);
        $this->assertContains('reflection:context:evidence', $ids);
        $this->assertContains('reflection:context:completed', $ids);
        $this->assertContains('reflection:context:reflections', $ids);
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'domain'
        ));
        $this->assertSame('Lens', $this->depthLabelFromHtml($response->getContent(), 1));
        $this->assertSame($plan->id, $active->plan_id);
        $this->assertSame($plan->id, $done->plan_id);
    }

    public function test_reflection_l2_projects_canonical_records_and_direct_return_paths(): void
    {
        [$user, $plan, , $done, $evidence] = $this->scenario();

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'reflection',
            'reflection_context' => 'reflections',
        ]));

        $response
            ->assertOk()
            ->assertSee('L2 · REFLECTION RECORDS')
            ->assertSee('実行振り返り')
            ->assertSee('Context Inspector');

        $graph = $response->viewData('graph');
        $record = $graph['nodes']->first(
            fn (array $node) => str_starts_with((string) ($node['id'] ?? ''), 'reflection:item:reflection-'.$evidence->id)
        );

        $this->assertIsArray($record);
        $this->assertSame('direct', data_get($record, 'direct_navigation.kind'));
        $this->assertSame(route('plans.show', $plan), data_get($record, 'direct_navigation.url'));
        $this->assertSame('reflections', data_get($graph, 'hierarchy.reflection_context_key'));
        $this->assertSame('Record', $this->depthLabelFromHtml($response->getContent(), 2));

        $completedResponse = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'reflection',
            'reflection_context' => 'completed',
        ]));
        $completedGraph = $completedResponse->viewData('graph');

        $this->assertTrue($completedGraph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'task'
                && ($node['entity_id'] ?? null) === $done->id
                && data_get($node, 'direct_navigation.kind') === 'direct'
        ));
    }

    public function test_empty_reflection_lens_does_not_collapse_into_single_dead_end_node(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'reflection',
            'reflection_context' => 'reflections',
        ]));

        $response
            ->assertOk()
            ->assertSee('Timelineを確認')
            ->assertSee('達成した計画を見る');

        $graph = $response->viewData('graph');

        $this->assertCount(3, $graph['nodes']);
        $this->assertSame('reflection:context:reflections', $graph['center_node_id']);
        $this->assertTrue($graph['nodes']->contains(
            fn (array $node) => ($node['id'] ?? null) === 'reflection:empty:timeline'
                && data_get($node, 'direct_navigation.kind') === 'direct'
        ));
        $this->assertTrue($graph['nodes']->contains(
            fn (array $node) => ($node['id'] ?? null) === 'reflection:empty:achievements'
                && data_get($node, 'direct_navigation.kind') === 'direct'
        ));
    }

    public function test_recent_lens_combines_work_logs_and_evidence_without_creating_new_history_rows(): void
    {
        [$user] = $this->scenario();

        $beforeEvidence = TaskEvidence::query()->count();
        $beforeLogs = WorkLog::query()->count();

        $graph = $this->actingAs($user)
            ->get(route('map.index', [
                'level' => 'l2',
                'intent' => 'reflection',
                'reflection_context' => 'recent',
            ]))
            ->viewData('graph');

        $recordIds = $graph['nodes']->pluck('id')->filter(
            fn ($id) => str_starts_with((string) $id, 'reflection:item:')
        );

        $this->assertGreaterThanOrEqual(2, $recordIds->count());
        $this->assertSame($beforeEvidence, TaskEvidence::query()->count());
        $this->assertSame($beforeLogs, WorkLog::query()->count());
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Reflection Mapを育てる',
            'description' => '過去の事実を意味から辿る',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today()->subWeek(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $active = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Reflection Lensを設計する',
            'description' => 'active task',
            'estimated_minutes' => 60,
            'remaining_minutes' => 30,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $done = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Evidence導線を確認する',
            'description' => 'completed task',
            'estimated_minutes' => 30,
            'remaining_minutes' => 0,
            'progress_percent' => 100,
            'status' => 'done',
            'priority' => 2,
            'activation_cost' => 1,
            'sort_order' => 2,
        ]);

        WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $active->id,
            'task_title_snapshot' => $active->title,
            'worked_on' => today(),
            'actual_minutes' => 25,
            'progress_delta_percent' => 10,
            'progress_before_percent' => 40,
            'progress_after_percent' => 50,
            'remaining_minutes_before' => 55,
            'remaining_minutes_after' => 30,
            'memo' => 'Reflection Lensの構造を整理した',
            'outcome' => 'Lens候補を固定した',
        ]);

        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $done->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'guided_execution_reflected',
            'external_key' => 'reflection-map-'.$done->id,
            'confidence' => 0.7,
            'occurred_at' => now(),
            'metadata' => [
                'intent' => 'Mapの振り返り導線を確認する',
                'actual_outcome' => 'Evidenceから過去へ辿れることを確認した',
                'next_adjustment' => 'Reflection専用Projectionへ分離する',
            ],
        ]);

        return [$user, $plan, $active, $done, $evidence];
    }

    private function depthLabelFromHtml(string $html, int $depth): ?string
    {
        if (! preg_match('/title="L'.preg_quote((string) $depth, '/').' · ([^"]+)"/u', $html, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }
}
