<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\TaskEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * December 1 C-Light cross-mode smoke.
 *
 * Synthetic persisted source facts only, NOT provider/GitHub integration,
 * real browser, paid capability, physical-device, or production acceptance.
 * Existing Study and Development domain rules remain authoritative.
 */
final class Dec1EvidenceToActionCrossModeTest extends TestCase
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

    public function test_owner_sees_recorded_study_and_development_state_together_without_false_progress(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $study = $this->plan($owner, 'DEC1 合成学習Plan', '資格学習');
        $studyTask = $this->task($study, 'ネットワーク演習');
        $this->scope($study);

        $attempt = StudyPracticeAttempt::query()->create([
            'plan_id' => $study->id,
            'task_id' => $studyTask->id,
            'user_id' => $owner->id,
            'request_hash' => hash('sha256', 'dec1-synthetic-graded-attempt'),
            'exercise_title' => 'DEC1 合成10問演習',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'question_feedback' => [
                    ['question_id' => 'synthetic-1', 'correctness' => 'incorrect', 'weakness_topics' => ['network']],
                ],
            ],
            'score_percent' => 70,
            'strengths' => ['database'],
            'weaknesses' => ['network'],
            'recommended_task_progress_percent' => 30,
            'evidence_summary' => '合成演習でネットワークに復習余地',
            'next_action' => 'ネットワークの復習',
        ]);
        app(TaskEvidenceService::class)->recordStudyPracticeAssessment($attempt);

        $development = $this->plan($owner, 'DEC1 合成開発Plan', '個人開発');
        $devTask = $this->task($development, 'CI確認');
        // The next two rows are fixtures only. They MUST NOT be construed as
        // proof of a real GitHub App installation, webhook, CI, or merge.
        UserProductGrant::query()->firstOrCreate(
            ['user_id' => $owner->id, 'product_key' => ProductKey::AllAccess],
            ['source' => 'manual', 'starts_at' => now()->subMinute()],
        );
        $repository = PlanArtifact::query()->create([
            'plan_id' => $development->id,
            'created_by_user_id' => $owner->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'DEC1-synthetic-repository',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                    'source_epoch' => '18edfd47-0fa5-4020-aa2d-d273c603ec90',
                ],
            ],
        ]);
        TaskEvidence::query()->create([
            'plan_id' => $development->id,
            'task_id' => $devTask->id,
            'user_id' => $owner->id,
            'source' => EvidenceSource::GitHub->value,
            'type' => 'github_commit_observed',
            'external_key' => 'dec1-synthetic-commit',
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'github_repository_artifact_id' => $repository->id,
                'github_source_epoch' => data_get($repository->metadata, 'github_app_connection.source_epoch'),
                'commit_sha' => str_repeat('a', 40),
                'branch' => 'test/dec1-synthetic-only',
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-mode="study"', false)
            ->assertSee('data-overview-mode="development"', false)
            ->assertSee($study->title)
            ->assertSee($development->title)
            ->assertSee('Exam Readiness')
            ->assertSee('Release Readiness');

        $this->assertDatabaseCount('study_practice_attempts', 1);
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $studyTask->id,
            'type' => 'study_practice_assessed',
        ]);
        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $devTask->id,
            'type' => 'github_commit_observed',
        ]);
        $this->assertSame(0, (int) $studyTask->fresh()->progress_percent);
        $this->assertSame(0, (int) $devTask->fresh()->progress_percent);
        $this->assertSame('todo', $studyTask->fresh()->status);
        $this->assertSame('todo', $devTask->fresh()->status);
    }

    public function test_other_account_does_not_see_either_private_mode(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $viewer = User::factory()->create(['first_run_completed_at' => now()]);
        $this->plan($owner, 'SECRET DEC1 STUDY TITLE', '資格学習');
        $this->plan($owner, 'SECRET DEC1 DEVELOPMENT TITLE', '個人開発');

        $this->actingAs($viewer)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertDontSee('SECRET DEC1 STUDY TITLE')
            ->assertDontSee('SECRET DEC1 DEVELOPMENT TITLE')
            ->assertDontSee('data-overview-mode="study"', false)
            ->assertDontSee('data-overview-mode="development"', false);
    }

    private function plan(User $owner, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function scope(Plan $plan): void
    {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => 'DEC1合成試験',
            'exam_date' => $plan->deadline?->format('Y-m-d'),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);
        StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => 'ネットワーク',
            'unit' => 'CIDR',
            'range_text' => 'CIDR',
            'confidence' => 1,
            'sort_order' => 0,
        ]);
    }
}
