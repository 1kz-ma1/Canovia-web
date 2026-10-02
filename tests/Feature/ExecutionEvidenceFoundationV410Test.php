<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskMilestone;
use App\Services\EvidenceProgressService;
use App\Services\PlanToolService;
use App\Services\TaskEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionEvidenceFoundationV410Test extends TestCase
{
    use RefreshDatabase;

    public function test_focus_timer_is_optional_instead_of_the_default_execution_recommendation(): void
    {
        $plan = $this->plan('制作計画', 'その他');
        $task = $this->task($plan, '外部ツールで作業する');

        $tools = collect(app(PlanToolService::class)->forTask($plan, $task));

        $timer = $tools->firstWhere('id', 'timer');

        $this->assertNotNull($timer);
        $this->assertFalse((bool) $timer['recommended']);
        $this->assertSame('任意', $timer['badge']);
        $this->assertStringContainsString('時間は進捗の証拠ではなく目安', $timer['description']);
    }

    public function test_stable_external_key_makes_evidence_recording_idempotent(): void
    {
        $plan = $this->plan('Evidence計画', 'その他');
        $task = $this->task($plan, 'Evidenceを集める');
        $service = app(TaskEvidenceService::class);

        $first = $service->record(
            $task,
            EvidenceSource::GitHub,
            'pull_request_merged',
            ['pull_request' => 42],
            confidence: 1.0,
            externalKey: 'github:pr:42:merged',
        );
        $second = $service->record(
            $task,
            EvidenceSource::GitHub,
            'pull_request_merged',
            ['pull_request' => 42, 'head' => 'abc123'],
            confidence: 1.0,
            externalKey: 'github:pr:42:merged',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('task_evidences', 1);
        $this->assertSame('abc123', data_get($second->fresh()->metadata, 'head'));
    }

    public function test_strong_study_evidence_can_recommend_progress_without_ever_lowering_it(): void
    {
        $plan = $this->plan('AP対策', '資格学習');
        $task = $this->task($plan, '可用性演習', progress: 80);
        $evidence = app(TaskEvidenceService::class)->record(
            $task,
            EvidenceSource::Native,
            'study_practice_assessed',
            ['recommended_task_progress_percent' => 70],
            confidence: 1.0,
            externalKey: 'study:1',
        );

        $recommended = app(EvidenceProgressService::class)->recommendPercent($task, $evidence);

        $this->assertSame(80, $recommended);

        $evidence->update([
            'metadata' => ['recommended_task_progress_percent' => 92],
        ]);

        $this->assertSame(
            92,
            app(EvidenceProgressService::class)->recommendPercent($task, $evidence->fresh()),
        );
    }

    public function test_task_milestones_are_available_as_a_future_progress_boundary(): void
    {
        $plan = $this->plan('発表準備', '制作活動');
        $task = $this->task($plan, '発表資料を完成させる');

        TaskMilestone::create([
            'task_id' => $task->id,
            'title' => '初稿を作る',
            'status' => TaskMilestone::STATUS_COMPLETED,
            'weight' => 2,
            'sort_order' => 1,
            'completed_at' => now(),
        ]);

        TaskMilestone::create([
            'task_id' => $task->id,
            'title' => '提出版を出力する',
            'status' => TaskMilestone::STATUS_PENDING,
            'weight' => 1,
            'sort_order' => 2,
        ]);

        $this->assertCount(2, $task->fresh()->milestones);
        $this->assertSame('初稿を作る', $task->fresh()->milestones->first()->title);
    }

    public function test_action_home_keeps_plan_specific_execution_surfaces_out_of_home(): void
    {
        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));
        $currentTask = file_get_contents(resource_path('views/dashboard/surfaces/current-task.blade.php'));
        $planTools = file_get_contents(resource_path('views/dashboard/surfaces/plan-tools.blade.php'));

        $this->assertStringContainsString('data-action-home', $view);
        $this->assertStringContainsString('data-action-home-guidance', $view);
        $this->assertStringNotContainsString('$surfaceModules', $view);
        $this->assertStringNotContainsString('data-dashboard-panel=', $view);

        // Plan-specific execution assets remain available outside Home.
        $this->assertStringContainsString('CURRENT TASK', $currentTask);
        $this->assertStringContainsString('PLAN TOOLS', $planTools);
        $this->assertStringContainsString('集中タイマーで進める', $currentTask);
        $this->assertStringContainsString('時間は目安', $currentTask);
        $this->assertStringContainsString("route('roadmap.index', ['plan_id' =>", $planTools);
    }

    private function plan(string $title, string $category): Plan
    {
        return Plan::create([
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'Evidence中心で進める計画',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title, int $progress = 0): Task
    {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => '次のActionを実行する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => $progress,
            'status' => $progress > 0 ? 'doing' : 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
