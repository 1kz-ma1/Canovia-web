<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\McpStudyResultProposal;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\LearningContextSqlMutationProbe as Probe;
use Tests\TestCase;

/**
 * CI-only differential oracle: v0.7 historical-hash changes must NEVER
 * occur without a filtered v0.15 SQL probe counter increase in this
 * synthetic mutation corpus. This is NOT an equivalence/completeness proof.
 */
final class LearningContextSqlMutationDifferentialV016Test extends TestCase
{
    private bool $installed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Probe::assertDisposableDatabase();
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        $this->withoutVite();
        Probe::install(true);
        $this->installed = true;
    }

    protected function tearDown(): void
    {
        if ($this->installed) {
            Probe::uninstall();
        }
        parent::tearDown();
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_SOURCE_DIFF',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(12),
            'is_public' => false, 'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $status = 'doing'): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'SYNTHETIC_TASK_'.Str::random(8),
            'description' => 'PRIVATE_SYNTHETIC_ONLY',
            'status' => $status,
            'progress_percent' => $status === 'done' ? 100 : 25,
            'sort_order' => 1,
            'priority' => 1,
        ]);
    }

    private function attempt(User $owner, Plan $plan, Task $task, bool $applied = false): StudyPracticeAttempt
    {
        return StudyPracticeAttempt::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'task_id' => $task->id,
            'request_hash' => bin2hex(random_bytes(32)),
            'exercise_title' => 'SECRET_NOT_IN_PROBE',
            'questions' => [], 'answers' => [], 'assessment' => ['initial' => true],
            'score_percent' => 60, 'weaknesses' => [],
            'recommended_task_progress_percent' => 35,
            'applied_at' => $applied ? now() : null,
        ]);
    }

    private function capture(User $owner, Plan $plan): string
    {
        $result = app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id);
        $this->assertNotNull($result, 'Synthetic Study source must be capturable.');

        return $result['source_revision'];
    }

    private function epoch(Plan $plan): int
    {
        $value = Probe::inspect($plan->id);
        return $value === null ? 0 : $value['epoch'];
    }

    private function expectChanged(User $owner, Plan $plan, string $name, callable $mutation): void
    {
        $before = $this->capture($owner, $plan);
        $counter = $this->epoch($plan);
        $mutation();
        $after = $this->capture($owner, $plan);

        $this->assertNotSame($before, $after, $name.' must alter authoritative source_revision.');
        $this->assertGreaterThan(
            $counter, $this->epoch($plan),
            $name.' CHANGED hash but missed filtered probe; never treat it as authoritative.'
        );
    }

    private function expectUnchanged(User $owner, Plan $plan, string $name, callable $mutation): void
    {
        $before = $this->capture($owner, $plan);
        $counter = $this->epoch($plan);
        $mutation();
        $this->assertSame($before, $this->capture($owner, $plan), $name);
        $this->assertSame($counter, $this->epoch($plan), $name);
    }

    public function test_plan_and_active_task_field_corpus_has_no_filtered_probe_false_negative(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);

        $this->expectChanged($owner, $plan, 'Plan title', function () use ($plan): void {
            DB::table('plans')->where('id', $plan->id)->update(['title' => 'CHANGED_SYNTHETIC_STUDY']);
        });
        $this->expectChanged($owner, $plan, 'Plan deadline', function () use ($plan): void {
            DB::table('plans')->where('id', $plan->id)->update([
                'deadline' => today()->addDays(19)->toDateString(),
            ]);
        });
        $this->expectChanged($owner, $plan, 'Task title', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update(['title' => 'UPDATED_DNS_TASK']);
        });
        $this->expectChanged($owner, $plan, 'Task progress', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update(['progress_percent' => 88]);
        });
        $this->expectChanged($owner, $plan, 'Task updated_at', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update([
                'updated_at' => now()->addMinutes(5),
            ]);
        });

        $this->expectUnchanged($owner, $plan, 'Task description-only raw SQL', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update([
                'description' => 'PRIVATE_DESCRIPTION_ONLY',
            ]);
        });
        $this->expectUnchanged($owner, $plan, 'Task unchanged SQL title', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update([
                'title' => 'UPDATED_DNS_TASK',
            ]);
        });

        $this->expectChanged($owner, $plan, 'Task done transition', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update(['status' => 'done']);
        });
        $this->expectChanged($owner, $plan, 'Task todo reentry', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->update(['status' => 'todo']);
        });
        $this->expectChanged($owner, $plan, 'Active task delete', function () use ($task): void {
            DB::table('tasks')->where('id', $task->id)->delete();
        });
    }

    public function test_native_practice_application_revision_fields_and_revocation_are_caught(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $attempt = $this->attempt($owner, $plan, $task);

        $this->expectUnchanged($owner, $plan, 'Unapplied score correction', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['score_percent' => 14]);
        });
        $this->expectChanged($owner, $plan, 'Apply native assessment', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['applied_at' => now()]);
        });
        $this->expectChanged($owner, $plan, 'Native score', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['score_percent' => 86]);
        });
        $this->expectChanged($owner, $plan, 'Native weakness JSON', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['weaknesses' => json_encode(['NETWORK'], JSON_THROW_ON_ERROR)]);
        });
        $this->expectChanged($owner, $plan, 'Native assessment JSON', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['assessment' => json_encode(['corrected' => true], JSON_THROW_ON_ERROR)]);
        });
        $this->expectChanged($owner, $plan, 'Native applied_at revocation', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['applied_at' => null]);
        });
        $this->expectChanged($owner, $plan, 'Native reapplication', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['applied_at' => now()->addMinute()]);
        });
        $this->expectChanged($owner, $plan, 'Native row deletion', function () use ($attempt): void {
            DB::table('study_practice_attempts')->where('id', $attempt->id)->delete();
        });
    }

    public function test_external_result_approval_corrections_revocation_and_deletion_are_caught(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => hash('sha256', 'diff-fixture-'.$plan->id),
            'status' => 'linked',
            'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => hash('sha256', 'diff-client-'.$plan->id),
            'scope' => 'study_progress',
            'status' => 'active',
            'consented_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $proposal = McpStudyResultProposal::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'grant_id' => $grant->id,
            'idempotency_key' => hash('sha256', 'diff-idempotent-'.$plan->id),
            'payload_hash' => hash('sha256', 'diff-payload-'.$plan->id),
            'question_count' => 10, 'correct_count' => 7,
            'weakness_topics' => ['SQL'],
            'status' => McpStudyResultProposal::PENDING,
            'expires_at' => now()->addDays(3),
        ]);

        $this->expectUnchanged($owner, $plan, 'Unapproved external correction', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)
                ->update(['correct_count' => 5]);
        });
        $this->expectChanged($owner, $plan, 'Owner approved external result', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)
                ->update(['status' => McpStudyResultProposal::APPROVED, 'decided_at' => now()]);
        });
        $this->expectChanged($owner, $plan, 'Approved external score correction', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)
                ->update(['correct_count' => 4]);
        });
        $this->expectChanged($owner, $plan, 'Approved external topic correction', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)
                ->update(['weakness_topics' => json_encode(['NETWORK'], JSON_THROW_ON_ERROR)]);
        });
        $this->expectChanged($owner, $plan, 'External rejected after approval', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)
                ->update(['status' => McpStudyResultProposal::REJECTED]);
        });
        $this->expectChanged($owner, $plan, 'External reapproved', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)
                ->update(['status' => McpStudyResultProposal::APPROVED]);
        });
        $this->expectChanged($owner, $plan, 'Approved external deletion', function () use ($proposal): void {
            DB::table('mcp_study_result_proposals')->where('id', $proposal->id)->delete();
        });
    }

    public function test_bulk_source_updates_increment_per_row_and_abort_transaction_together(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        for ($i = 0; $i < 12; $i++) {
            $this->task($plan);
        }
        $before = $this->capture($owner, $plan);
        $base = $this->epoch($plan);

        DB::table('tasks')->where('plan_id', $plan->id)
            ->update(['progress_percent' => 60]);
        $this->assertNotSame($before, $this->capture($owner, $plan));
        $this->assertSame($base + 12, $this->epoch($plan));

        $changed = $this->capture($owner, $plan);
        try {
            DB::transaction(function () use ($plan): void {
                DB::table('tasks')->where('plan_id', $plan->id)
                    ->update(['progress_percent' => 70]);
                throw new RuntimeException('synthetic-bulk-rollback');
            });
            $this->fail('Expected a failed SQL transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic-bulk-rollback', $e->getMessage());
        }
        $this->assertSame($base + 12, $this->epoch($plan));
        $this->assertSame($changed, $this->capture($owner, $plan));
    }

    public function test_eligibility_revocation_is_denied_by_capture_even_though_probe_only_counts_sql(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->task($plan);
        $before = $this->capture($owner, $plan);
        $base = $this->epoch($plan);
        DB::table('plans')->where('id', $plan->id)->update(['is_public' => true]);
        $this->assertGreaterThan($base, $this->epoch($plan));
        $this->assertNull(app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id));
        $this->assertFalse(app(LearningContextSourceCaptureService::class)
            ->isCurrent($owner, $plan->id, $before));
    }
}
