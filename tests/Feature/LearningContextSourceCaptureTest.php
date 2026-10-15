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
        $this->assertSame('internal_owner_scoped_read_only', $a['snapshot']['delivery']);
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

    public function test_old_native_attempt_corrections_and_deletion_invalidate_revision_without_invented_learning_change(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $old = null;
        for ($i = 0; $i < 12; ++$i) {
            $attempt = $this->attempt($owner, $plan, $task, 60);
            $attempt->update(['applied_at' => now()->subDays(24 - $i)]);
            $old ??= $attempt;
        }

        $capture = app(LearningContextSourceCaptureService::class);
        $detector = app(LearningContextChangeDetectionService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertNotNull($before);
        $this->assertTrue($before['snapshot']['facts']['recent_practice']['truncated']);
        $this->assertSame(8, $before['snapshot']['facts']['recent_practice']['applied_attempts_considered']);

        $old->update(['score_percent' => 10]);
        $corrected = $capture->capture($owner, $plan->id);
        $this->assertNotSame($before['source_revision'], $corrected['source_revision']);
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $before['source_revision']));
        $this->assertSame($before['snapshot'], $corrected['snapshot']);
        $this->assertSame('unchanged', $detector->compare($before, $corrected)['status']);
        $this->assertStringNotContainsString('SECRET_GRADING_RATIONALE',
            json_encode($corrected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $old->update(['assessment' => ['rationale' => 'PRIVATE_CORRECTION_SECRET']]);
        $assessmentCorrected = $capture->capture($owner, $plan->id);
        $this->assertNotSame($corrected['source_revision'], $assessmentCorrected['source_revision']);
        $this->assertStringNotContainsString('PRIVATE_CORRECTION_SECRET',
            json_encode($assessmentCorrected, JSON_THROW_ON_ERROR));

        $old->delete();
        $deleted = $capture->capture($owner, $plan->id);
        $this->assertNotSame($assessmentCorrected['source_revision'], $deleted['source_revision']);
        $this->assertSame($before['snapshot'], $deleted['snapshot']);
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $assessmentCorrected['source_revision']));
    }

    public function test_out_of_window_task_change_invalidates_hash_but_not_visible_eight_tasks(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $later = null;
        for ($i = 0; $i < 12; ++$i) {
            $later = $this->task($plan, 'Task '.$i);
        }
        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertTrue($before['snapshot']['facts']['active_tasks']['truncated']);
        $later->update(['progress_percent' => 87, 'title' => 'HIDDEN_12TH_TASK']);
        $after = $capture->capture($owner, $plan->id);
        $this->assertSame($before['snapshot'], $after['snapshot']);
        $this->assertNotSame($before['source_revision'], $after['source_revision']);
        $this->assertStringNotContainsString('HIDDEN_12TH_TASK', json_encode($after, JSON_THROW_ON_ERROR));

        $later->delete();
        $this->assertNotSame($after['source_revision'],
            $capture->capture($owner, $plan->id)['source_revision']);
    }

    public function test_old_approved_mcp_result_correction_and_revocation_invalidate_history_revision(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        for ($i = 0; $i < 10; ++$i) {
            $attempt = $this->attempt($owner, $plan, $task, 70);
            $attempt->update(['applied_at' => now()->subDays(10 - $i)]);
        }

        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id, 'provider_key' => 'chatgpt',
            'identity_fingerprint' => hash('sha256', 'historical-owner'),
            'status' => 'linked', 'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id, 'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => hash('sha256', 'historical-client'),
            'scope' => 'study_progress', 'status' => 'active',
            'consented_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $old = McpStudyResultProposal::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'task_id' => $task->id,
            'grant_id' => $grant->id,
            'idempotency_key' => hash('sha256', 'historical-attempt'),
            'payload_hash' => hash('sha256', 'historical-payload'),
            'question_count' => 10, 'correct_count' => 6,
            'weakness_topics' => ['PRIVATE_OLD_SQL_TOPIC'],
            'status' => McpStudyResultProposal::APPROVED,
            'decided_at' => now()->subDays(40),
            'expires_at' => now()->addDays(7),
        ]);

        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertSame(0,
            $before['snapshot']['facts']['recent_practice']['owner_approved_external_results_considered']);
        $old->update(['correct_count' => 8]);
        $changed = $capture->capture($owner, $plan->id);
        $this->assertNotSame($before['source_revision'], $changed['source_revision']);
        $this->assertSame($before['snapshot'], $changed['snapshot']);
        $this->assertStringNotContainsString('PRIVATE_OLD_SQL_TOPIC',
            json_encode($changed, JSON_THROW_ON_ERROR));

        $old->update(['status' => McpStudyResultProposal::REJECTED]);
        $revoked = $capture->capture($owner, $plan->id);
        $this->assertNotSame($changed['source_revision'], $revoked['source_revision']);
        $this->assertSame($before['snapshot'], $revoked['snapshot']);
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $changed['source_revision']));
    }

    public function test_over_budget_active_tasks_refuse_partial_fingerprint(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertNotNull($before);

        // Synthetic bulk fixtures; no accounts or real exam data.
        $now = now();
        $bulk = [];
        for ($i = 0; $i < 256; ++$i) {
            $bulk[] = [
                'plan_id' => $plan->id,
                'title' => 'Synthetic overflow '.$i,
                'status' => 'todo', 'progress_percent' => 0,
                'priority' => 1, 'sort_order' => $i + 10,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        Task::query()->insert($bulk);
        $this->assertNull($capture->capture($owner, $plan->id));
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $before['source_revision']));
    }

    public function test_over_budget_applied_practice_refuses_partial_revision(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $capture = app(LearningContextSourceCaptureService::class);
        $before = $capture->capture($owner, $plan->id);
        $this->assertNotNull($before);

        // Batches avoid SQLite/driver bind-variable limits; no real people
        // or private questions. 513 exceeds the strictly bounded 512 rows.
        $now = now();
        for ($batch = 0; $batch < 5; ++$batch) {
            $rows = [];
            $start = $batch * 110;
            $stop = min(513, $start + 110);
            for ($i = $start; $i < $stop; ++$i) {
                $rows[] = [
                    'user_id' => $owner->id, 'plan_id' => $plan->id,
                    'task_id' => $task->id,
                    'request_hash' => hash('sha256', 'source-limit-'.$plan->id.'-'.$i),
                    'exercise_title' => 'synthetic',
                    'questions' => '[]', 'answers' => '[]',
                    'assessment' => '{}',
                    'score_percent' => 60,
                    'recommended_task_progress_percent' => 50,
                    'applied_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            StudyPracticeAttempt::query()->insert($rows);
        }

        $this->assertNull($capture->capture($owner, $plan->id));
        $this->assertFalse($capture->isCurrent($owner, $plan->id, $before['source_revision']));
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
