<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\WorkSession;
use App\Services\EvidenceProgressService;
use App\Services\TaskEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeEvidenceSignalsV411Test extends TestCase
{
    use RefreshDatabase;

    public function test_linked_artifact_state_is_recorded_idempotently_and_new_state_creates_new_evidence(): void
    {
        [$plan, $task] = $this->planAndTask();

        $artifact = PlanArtifact::create([
            'plan_id' => $plan->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia',
            'url' => 'https://example.com/repository',
            'version_label' => 'v1',
        ]);
        $artifact->tasks()->sync([$task->id]);

        $service = app(TaskEvidenceService::class);

        $first = $service->recordArtifactState($artifact, 'created')->first();
        $same = $service->recordArtifactState($artifact->fresh(), 'created')->first();

        $this->assertNotNull($first);
        $this->assertNotNull($same);
        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('task_evidences', 1);
        $this->assertSame(0.8, (float) $first->confidence);
        $this->assertSame('artifact_state_observed', $first->type);

        $artifact->update(['version_label' => 'v2']);
        $second = $service->recordArtifactState($artifact->fresh(), 'updated')->first();

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('task_evidences', 2);
        $this->assertSame('v2', data_get($second->metadata, 'version_label'));
    }

    public function test_focus_session_is_activity_evidence_but_never_progress_evidence(): void
    {
        [$plan, $task] = $this->planAndTask(progress: 40);

        $session = WorkSession::create([
            'actor_token' => Str::random(64),
            'browser_session_id' => 'native-evidence-test',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'completed',
            'intended_minutes' => 30,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
            'actual_seconds' => 1200,
            'paused_seconds' => 0,
            'source' => 'dashboard',
        ]);

        $evidence = app(TaskEvidenceService::class)->recordFocusSession($session, [
            'actual_minutes' => 20,
            'active_seconds' => 1200,
            'wall_seconds' => 1200,
            'paused_seconds' => 0,
        ], 'completed');

        $this->assertNotNull($evidence);
        $this->assertSame(EvidenceSource::Native, $evidence->source);
        $this->assertSame('focus_session_completed', $evidence->type);
        $this->assertSame(0.4, (float) $evidence->confidence);
        $this->assertNull(app(EvidenceProgressService::class)->recommendPercent($task, $evidence));
        $this->assertSame(40, $task->fresh()->progress_percent);
    }

    public function test_evidence_has_human_readable_labels_and_summary(): void
    {
        [$plan, $task] = $this->planAndTask();

        $evidence = app(TaskEvidenceService::class)->record(
            $task,
            EvidenceSource::Native,
            'study_practice_assessed',
            [
                'score_percent' => 90,
                'evidence_summary' => '10問中9問正解。',
                'recommended_task_progress_percent' => 80,
            ],
            confidence: 1.0,
            externalKey: 'study-practice-attempt:999',
        );

        $this->assertSame('Canovia', $evidence->sourceLabel());
        $this->assertSame('AI演習', $evidence->typeLabel());
        $this->assertStringContainsString('90%', $evidence->summary());
        $this->assertStringContainsString('10問中9問正解', $evidence->summary());
    }

    public function test_plan_hub_exposes_recent_evidence_without_calling_it_progress(): void
    {
        $evidenceView = file_get_contents(resource_path('views/dashboard/surfaces/recent-evidence.blade.php'));
        $currentTaskView = file_get_contents(resource_path('views/dashboard/surfaces/current-task.blade.php'));

        $this->assertStringContainsString('RECENT EVIDENCE', $evidenceView);
        $this->assertStringContainsString('Canoviaが確認できた事実', $evidenceView);
        $this->assertStringContainsString('時間は目安', $currentTaskView);
    }

    private function planAndTask(int $progress = 0): array
    {
        $plan = Plan::create([
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Evidence test',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => 'Evidenceを確認する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => $progress,
            'status' => $progress > 0 ? 'doing' : 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$plan, $task];
    }
}
