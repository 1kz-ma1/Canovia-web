<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\McpStudyResultProposal;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextChangeDetectionService;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearningContextSourceCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function plan(User $owner, string $category = '資格学習'): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_AP_STUDY',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(18),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $name = 'DNS'): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id, 'title' => $name,
            'description' => 'SECRET_PRIVATE_DESCRIPTION',
            'status' => 'doing', 'progress_percent' => 20,
            'priority' => 1, 'sort_order' => 1,
        ]);
    }

    private function attempt(User $user, Plan $plan, Task $task, int $score = 60, bool $applied = true): StudyPracticeAttempt
    {
        return StudyPracticeAttempt::query()->create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'task_id' => $task->id,
            'request_hash' => bin2hex(random_bytes(32)),
            'exercise_title' => 'SECRET_STUDY_EXERCISE_TITLE',
            'questions' => [['body' => 'SECRET_QUESTION']],
            'answers' => [['answer' => 'SECRET_ANSWER']],
            'assessment' => [
                'rationale' => 'SECRET_GRADING_RATIONALE',
                'question_feedback' => [[
                    'correctness' => 'incorrect',
                    'weakness_topics' => ['ネットワーク'],
                    'explanation' => 'SECRET_PRIVATE_FEEDBACK',
                ]],
            ],
            'score_percent' => $score,
            'weaknesses' => ['MUST_IGNORE_LEGACY_IF_TYPED'],
            'recommended_task_progress_percent' => 35,
            'applied_at' => $applied ? now() : null,
        ]);
    }

    public function test_owner_capture_is_stable_and_internal_snapshot_is_usable_by_detector(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $this->attempt($owner, $plan, $task);

        $service = app(LearningContextSourceCaptureService::class);
        $a = $service->capture($owner, $plan->id);
        $this->assertNotNull($a);
        $this->assertSame('canovia.learning_context_source_capture.v0.2', $a['schema']);
        $this->assertSame($owner->id, $a['actor_id']);
        $this->assertSame($plan->id, $a['plan_id']);
        $this->assertSame(64, strlen($a['source_revision']));
        $this->assertSame($task->id, $a['snapshot']['facts']['active_tasks']['items'][0]['task_id']);
        $this->assertSame('ネットワーク', $a['snapshot']['observations']['weakness_tags'][0]['topic']);
        $this->assertSame(60, $a['snapshot']['facts']['recent_practice']['average_score_percent']);
        $this->assertSame(1, $a['snapshot']['facts']['recent_practice']['scored_attempts']);
        $this->assertSame($a['source_revision'], $service->capture($owner, $plan->id)['source_revision']);
        $this->assertTrue($service->isCurrent($owner, $plan->id, $a['source_revision']));
        $this->assertSame('unchanged', app(LearningContextChangeDetectionService::class)
            ->compare($a, $service->capture($owner, $plan->id))['status']);

        $json = json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['SECRET_QUESTION', 'SECRET_ANSWER', 'SECRET_PRIVATE_DESCRIPTION',
            'SECRET_GRADING_RATIONALE', 'SECRET_PRIVATE_FEEDBACK',
            'MUST_IGNORE_LEGACY_IF_TYPED', 'SECRET_STUDY_EXERCISE_TITLE'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseCount('study_practice_attempts', 1);
    }

    public function test_correcting_and_deleting_applied_attempt_invalidates_old_revision(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $attempt = $this->attempt($owner, $plan, $task, 60);
        $service = app(LearningContextSourceCaptureService::class);
        $before = $service->capture($owner, $plan->id);
        $attempt->update(['score_percent' => 85]);
        $after = $service->capture($owner, $plan->id);
        $this->assertNotSame($before['source_revision'], $after['source_revision']);
        $this->assertFalse($service->isCurrent($owner, $plan->id, $before['source_revision']));
        $this->assertTrue($service->isCurrent($owner, $plan->id, $after['source_revision']));
        $this->assertSame('changed_no_action',
            app(LearningContextChangeDetectionService::class)->compare($before, $after)['status']);

        $attempt->delete();
        $deleted = $service->capture($owner, $plan->id);
        $this->assertNotSame($deleted['source_revision'], $after['source_revision']);
        $this->assertSame(0, $deleted['snapshot']['facts']['recent_practice']['applied_attempts_considered']);
        $this->assertFalse($service->isCurrent($owner, $plan->id, $after['source_revision']));
    }

    public function test_task_corrections_pause_and_missing_active_task_are_not_falsely_completed(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $service = app(LearningContextSourceCaptureService::class);
        $before = $service->capture($owner, $plan->id);
        $task->update(['status' => 'paused']);
        $after = $service->capture($owner, $plan->id);
        $this->assertSame('review_candidate',
            app(LearningContextChangeDetectionService::class)->compare($before, $after)['status']);
        $this->assertSame('recorded_task_paused',
            app(LearningContextChangeDetectionService::class)->compare($before, $after)['events'][0]['kind']);

        $task->update(['status' => 'done']);
        $removed = $service->capture($owner, $plan->id);
        $this->assertSame([], $removed['snapshot']['facts']['active_tasks']['items']);
        $this->assertSame('changed_no_action',
            app(LearningContextChangeDetectionService::class)->compare($after, $removed)['status']);
        $this->assertFalse($service->isCurrent($owner, $plan->id, $after['source_revision']));
    }

    public function test_unapplied_foreign_and_unapproved_results_are_excluded(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $this->attempt($owner, $plan, $task, 20, false);
        $this->attempt($other, $plan, $task, 5);
        $service = app(LearningContextSourceCaptureService::class);
        $before = $service->capture($owner, $plan->id);
        $this->assertSame(0, $before['snapshot']['facts']['recent_practice']['applied_attempts_considered']);
        $this->assertNull($before['snapshot']['facts']['recent_practice']['average_score_percent']);

        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id, 'provider_key' => 'chatgpt',
            'identity_fingerprint' => hash('sha256', 'synthetic'),
            'status' => 'linked', 'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id, 'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => hash('sha256', 'synthetic-client'),
            'scope' => 'study_progress', 'status' => 'active',
            'consented_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $proposal = McpStudyResultProposal::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'task_id' => $task->id,
            'grant_id' => $grant->id, 'idempotency_key' => str_repeat('b', 64),
            'payload_hash' => str_repeat('c', 64),
            'question_count' => 10, 'correct_count' => 7,
            'weakness_topics' => ['SQL'],
            'status' => McpStudyResultProposal::PENDING,
            'expires_at' => now()->addMinutes(30),
        ]);
        $pending = $service->capture($owner, $plan->id);
        $this->assertSame($before['source_revision'], $pending['source_revision']);
        $proposal->update(['status' => McpStudyResultProposal::APPROVED, 'decided_at' => now()]);
        $approved = $service->capture($owner, $plan->id);
        $this->assertNotSame($pending['source_revision'], $approved['source_revision']);
        $this->assertSame(1, $approved['snapshot']['facts']['recent_practice']['applied_attempts_considered']);
        $this->assertSame(1, $approved['snapshot']['facts']['recent_practice']['owner_approved_external_results_considered']);
        $this->assertSame('SQL', $approved['snapshot']['observations']['weakness_tags'][0]['topic']);
        $proposal->update(['status' => McpStudyResultProposal::REJECTED]);
        $this->assertFalse($service->isCurrent($owner, $plan->id, $approved['source_revision']));
    }

    public function test_wrong_owner_collaboration_public_plan_or_other_domain_denies_capture(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        $service = app(LearningContextSourceCaptureService::class);
        $a = $service->capture($owner, $plan->id);
        $this->assertNotNull($a);
        $this->assertNull($service->capture($other, $plan->id));
        $this->assertFalse($service->isCurrent($other, $plan->id, $a['source_revision']));
        $this->assertFalse($service->isCurrent($owner, $plan->id, 'not-a-hash'));
        $plan->update(['is_collaborative' => true]);
        $this->assertNull($service->capture($owner, $plan->id));
        $plan->update(['is_collaborative' => false, 'is_public' => true]);
        $this->assertNull($service->capture($owner, $plan->id));
        $plan->update(['is_public' => false, 'category' => 'ソフトウェア開発']);
        $this->assertNull($service->capture($owner, $plan->id));
        $plan->update(['category' => '資格学習', 'user_id' => $other->id]);
        $this->assertNull($service->capture($owner, $plan->id));
        $this->assertFalse($service->isCurrent($owner, $plan->id, $a['source_revision']));
    }

    public function test_task_window_is_bounded_and_does_not_export_descriptions(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        for ($i = 0; $i < 11; ++$i) {
            $this->task($plan, 'Task'.$i);
        }
        $capture = app(LearningContextSourceCaptureService::class)->capture($owner, $plan->id);
        $this->assertCount(8, $capture['snapshot']['facts']['active_tasks']['items']);
        $this->assertTrue($capture['snapshot']['facts']['active_tasks']['truncated']);
        $this->assertStringNotContainsString('SECRET_PRIVATE_DESCRIPTION',
            json_encode($capture, JSON_THROW_ON_ERROR));
    }
}
