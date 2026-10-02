<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEvidenceService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActionFirstV416Test extends TestCase
{
    use RefreshDatabase;

    public function test_home_keeps_primary_action_visible_and_explanation_collapsed(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        $response->assertSee($task->title)->assertSee('今やること');
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(1, $xpath->query('//details[@data-guidance-reasons and not(@open)]')->length);
        $this->assertSame(0, $xpath->query('//details[@data-guidance-reasons]//form')->length);
        $this->assertSame(1, $xpath->query('//details[@data-home-collaboration]/preceding::section[@data-action-home-guidance]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-panel]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-surface-id]')->length);
    }

    public function test_career_action_stays_visible_without_embedding_career_pipeline_on_home(): void
    {
        [$user] = $this->scenario('就活・キャリア', '一次面接の準備');

        $response = $this->actingAs($user)->get(route('home'))->assertOk();

        $response
            ->assertSee('Careerで進める')
            ->assertDontSee('CAREER PIPELINE')
            ->assertDontSee('INTERVIEW FOCUS');
    }

    public function test_home_does_not_mutate_evidence_or_progress(): void
    {
        [$user, $plan, $task] = $this->scenario();

        app(TaskEvidenceService::class)->record(
            $task,
            EvidenceSource::Native,
            'artifact_state_observed',
            ['title' => '確認済みの成果物'],
        );

        $this->actingAs($user)->get(route('home'))->assertOk();

        $this->assertSame(20, (int) $task->fresh()->progress_percent);
        $this->assertDatabaseCount('task_evidences', 1);
    }

    public function test_collaborative_viewer_does_not_receive_edit_or_start_actions(): void
    {
        [$owner, $plan] = $this->scenario();
        $viewer = User::factory()->create();

        $plan->update(['is_collaborative' => true]);
        $plan->memberships()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(0, $xpath->query('//form[@data-work-start-form]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-panel]')->length);
    }

    public function test_empty_home_keeps_plan_creation_available(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-action-home-create-prompt', false)
            ->assertSee('計画を作る');
    }

    public function test_completed_plan_keeps_navigation_without_inventing_actions(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $task->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('home'))->assertOk();

        $response
            ->assertDontSee('data-action-home-guidance', false)
            ->assertSee('星座で全体を見る')
            ->assertSee('data-action-home-utilities', false);
    }

    private function scenario(string $category = '資格学習', string $title = 'ネットワーク演習'): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '表示階層テスト',
            'description' => '計画の説明を全文保持',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => '詳細本文を最後まで保持',
            'next_action_note' => '次の一歩を保持',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
