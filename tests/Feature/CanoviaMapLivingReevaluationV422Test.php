<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaMapLivingReevaluationV422Test extends TestCase
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

    public function test_projection_key_changes_and_next_task_becomes_primary_after_current_task_completion(): void
    {
        [$user, $plan, $current, $next] = $this->scenario();

        $before = $this->actingAs($user)->get(route('map.index'));
        $before->assertOk();

        $beforeKey = $this->projectionKey($before->getContent());
        $this->assertNotSame('', $beforeKey);
        $this->assertPrimaryNode($before->getContent(), 'task:'.$current->id);

        $current->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $after = $this->actingAs($user)->get(route('map.index'));
        $after->assertOk();

        $afterKey = $this->projectionKey($after->getContent());

        $this->assertNotSame($beforeKey, $afterKey);
        $this->assertPrimaryNode($after->getContent(), 'task:'.$next->id);
        $after->assertSee('次のActionを実行する');
    }

    public function test_projection_key_changes_when_new_evidence_changes_the_visible_context(): void
    {
        [$user, $plan, $current] = $this->scenario();

        $before = $this->actingAs($user)->get(route('map.index'));
        $beforeKey = $this->projectionKey($before->getContent());

        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $current->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'focus_session_completed',
            'external_key' => 'v422-evidence-'.$current->id,
            'confidence' => 0.8,
            'occurred_at' => now(),
            'metadata' => ['actual_minutes' => 20],
        ]);

        $after = $this->actingAs($user)->get(route('map.index'));
        $afterKey = $this->projectionKey($after->getContent());

        $this->assertNotSame($beforeKey, $afterKey);
        $after
            ->assertSee('PAST / EVIDENCE')
            ->assertSee('集中作業');
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
            'title' => 'Living Reevaluationを検証する',
            'description' => '意味のある更新後だけMapを変える',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $current = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '現在のActionを実行する',
            'description' => '完了後に次へ進む',
            'estimated_minutes' => 45,
            'remaining_minutes' => 30,
            'progress_percent' => 40,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $next = Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $current->id,
            'title' => '次のActionを実行する',
            'description' => '現在Actionの完了後に中央へ来る',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 2,
        ]);

        return [$user, $plan, $current, $next];
    }

    private function projectionKey(string $html): string
    {
        preg_match('/data-map-projection-key="([^"]+)"/', $html, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function assertPrimaryNode(string $html, string $nodeId): void
    {
        $pattern = '/data-map-node-id="'.preg_quote($nodeId, '/').'".*?aria-current="true"/s';

        $this->assertMatchesRegularExpression($pattern, $html);
    }
}
