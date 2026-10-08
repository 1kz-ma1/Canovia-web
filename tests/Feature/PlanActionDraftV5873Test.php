<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanActionDraftRevision;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionDraftV5873Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array', 'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    private function plan(User $owner, string $category, ?string $domain = null, bool $collaborative = false): Plan
    {
        return Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '行動を見直す',
            'category' => $category, 'workspace_domain_override' => $domain,
            'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(40),
            'is_public' => false, 'is_collaborative' => $collaborative,
        ]);
    }

    private function task(Plan $plan): Task
    {
        return Task::create(['plan_id' => $plan->id, 'title' => '今の仕事',
            'estimated_minutes' => 30, 'remaining_minutes' => 15,
            'progress_percent' => 50, 'status' => 'doing',
            'priority' => 3, 'activation_cost' => 3, 'sort_order' => 0]);
    }

    private function observed(Plan $plan, Task $task, string $type, string $source = 'native', array $metadata = []): TaskEvidence
    {
        return TaskEvidence::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'source' => $source, 'type' => $type,
            'occurred_at' => now(), 'metadata' => $metadata,
        ]);
    }

    private function workLog(Plan $plan, Task $task): WorkLog
    {
        return WorkLog::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'task_title_snapshot' => '学習を記録', 'worked_on' => today(),
            'actual_minutes' => 15, 'progress_delta_percent' => 0,
            'progress_before_percent' => 50, 'progress_after_percent' => 50,
            'remaining_minutes_before' => 15, 'remaining_minutes_after' => 15,
            'outcome' => '本人が復習の必要性を記録',
        ]);
    }

    private function hidden(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/name="'.preg_quote($name, '/').'" value="([^"]+)"/', $html, $m));
        return $m[1];
    }

    public function test_study_evidence_creates_specific_unapplied_suggestion_and_accepts_once(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '資格学習');
        $task = $this->task($plan);
        $observation = $this->observed($plan, $task, 'study_practice_assessed',
            'native', ['score_percent' => 68, 'evidence_summary' => 'ネットワークの復習']);
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('data-typed-evidence-compose', false)
            ->assertSee('AI演習');
        $rid = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $plan), [
            'request_id' => $rid, 'task_evidence_ids' => [$observation->id],
        ])->assertRedirect();
        $draft = PlanActionDraft::where('request_id', $rid)->sole();
        $this->assertSame('task_evidence', $draft->source_kind);
        $this->assertSame('proposed', $draft->status);
        $this->assertSame(1, $draft->revision_no);
        $this->assertSame($observation->id, $draft->evidence_snapshots[0]['task_evidence_id']);
        $this->assertSame('native', $draft->evidence_snapshots[0]['evidence_source']);
        $this->assertStringContainsString('復習が必要な設問', $draft->suggested_next_action);
        $this->assertSame(50, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('tasks', 1);
        $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $plan), [
            'request_id' => $rid, 'task_evidence_ids' => [$observation->id],
        ])->assertRedirect();
        $this->assertDatabaseCount('plan_action_drafts', 1);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))->assertRedirect();
        $this->assertDatabaseCount('tasks', 2);
        $this->assertSame(50, $task->fresh()->progress_percent);
    }

    public function test_development_and_career_use_separate_explicit_type_rules(): void
    {
        $owner = User::factory()->create();
        $cases = [
            ['個人開発', 'pull_request_merged', 'github',
                ['pull_request_number' => 123], 'マージしたPR'],
            ['就活・キャリア', 'interview_review_completed', 'native',
                ['company_name' => 'Example', 'next_focus' => '自己紹介'], '面接の振り返り'],
        ];
        foreach ($cases as [$category, $type, $source, $metadata, $expected]) {
            $plan = $this->plan($owner, $category);
            $t = $this->task($plan);
            $obs = $this->observed($plan, $t, $type, $source, $metadata);
            $rid = (string) Str::uuid();
            $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $plan), [
                'request_id' => $rid, 'task_evidence_ids' => [$obs->id],
            ])->assertRedirect();
            $draft = PlanActionDraft::where('request_id', $rid)->sole();
            $this->assertStringContainsString($expected, $draft->suggested_next_action);
            $this->assertSame($source, $draft->evidence_snapshots[0]['evidence_source']);
            $this->assertSame(50, $t->fresh()->progress_percent);
        }
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_mixed_evidence_and_worklog_keep_provenance_and_never_mutate_tasks(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '資格学習');
        $task = $this->task($plan);
        $log = $this->workLog($plan, $task);
        $observed = $this->observed($plan, $task, 'study_recall_reviewed',
            'native', ['prompt' => 'DNS', 'rating' => 'hard']);
        $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $plan), [
            'request_id' => (string) Str::uuid(),
            'task_evidence_ids' => [$observed->id], 'work_log_ids' => [$log->id],
        ])->assertRedirect();
        $draft = PlanActionDraft::sole();
        $this->assertSame('mixed', $draft->source_kind);
        $this->assertSame(['work_log', 'task_evidence'], array_column($draft->evidence_snapshots, 'kind'));
        $this->assertSame($log->id, $draft->evidence_snapshots[0]['work_log_id']);
        $this->assertStringContainsString('Recall', $draft->suggested_next_action);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_wrong_domain_cross_plan_and_non_owner_evidence_are_hidden(): void
    {
        $owner = User::factory()->create();
        $study = $this->plan($owner, '資格学習', null, true);
        $other = $this->plan($owner, '資格学習');
        $dev = $this->plan($owner, '制作活動', 'development');
        $studyTask = $this->task($study);
        $cross = $this->observed($other, $this->task($other), 'study_practice_assessed');
        $wrong = $this->observed($study, $studyTask, 'pull_request_merged', 'github');
        $correct = $this->observed($study, $studyTask, 'study_recall_reviewed');
        $devObs = $this->observed($dev, $this->task($dev), 'pull_request_merged', 'github');
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $study))
            ->assertOk()->assertDontSee('GitHubマージ');
        $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $study), [
            'request_id' => (string) Str::uuid(), 'task_evidence_ids' => [$correct->id, $cross->id],
        ])->assertNotFound();
        $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $study), [
            'request_id' => (string) Str::uuid(), 'task_evidence_ids' => [$wrong->id],
        ])->assertNotFound();

        $this->actingAs($owner)->get(route('plans.action_drafts.index', $dev))
            ->assertOk()->assertSee('GitHubマージ');
        $this->actingAs($owner)->post(route('plans.action_drafts.compose_observed', $dev), [
            'request_id' => (string) Str::uuid(), 'task_evidence_ids' => [$devObs->id],
        ])->assertRedirect();
        $this->assertDatabaseCount('plan_action_drafts', 1);

        foreach ([PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER] as $role) {
            $member = User::factory()->create();
            PlanMember::create(['plan_id' => $study->id, 'user_id' => $member->id,
                'role' => $role, 'invited_by_user_id' => $owner->id, 'joined_at' => now()]);
            $this->actingAs($member)->get(route('plans.action_drafts.index', $study))->assertForbidden();
            $this->actingAs($member)->post(route('plans.action_drafts.compose_observed', $study), [
                'request_id' => (string) Str::uuid(), 'task_evidence_ids' => [$correct->id],
            ])->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->post(route('plans.action_drafts.compose_observed', $study), [
            'request_id' => (string) Str::uuid(), 'task_evidence_ids' => [$correct->id],
        ])->assertForbidden();
        $this->assertDatabaseCount('plan_action_drafts', 1);
    }

    public function test_typed_refresh_is_readonly_until_confirmation_and_blocks_stale_preview(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '個人開発');
        $task = $this->task($plan);
        $obs = $this->observed($plan, $task, 'pull_request_ci_observed', 'github',
            ['pull_request_number' => 33, 'ci_state' => 'failure']);
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), [
            'request_id' => (string) Str::uuid(),
            'completed_action' => 'PRを更新', 'observed_outcome' => 'CIを確認したい',
        ])->assertRedirect();
        $draft = PlanActionDraft::sole();
        $preview = $this->actingAs($owner)->post(route('plans.action_drafts.refresh.preview', [$plan, $draft]), [
            'additional_task_evidence_ids' => [$obs->id],
        ])->assertOk()->assertSee('追加する根拠')->assertSee('CIの記録');
        $this->assertNull($draft->fresh()->evidence_snapshots);
        $this->assertDatabaseCount('plan_action_draft_revisions', 0);
        $payload = [
            'expected_revision' => 1, 'additional_task_evidence_ids' => [$obs->id],
            'source_fingerprint' => $this->hidden($preview->getContent(), 'source_fingerprint'),
            'candidate_fingerprint' => $this->hidden($preview->getContent(), 'candidate_fingerprint'),
        ];
        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), $payload)
            ->assertRedirect();
        $changed = $draft->fresh();
        $this->assertSame(2, $changed->revision_no);
        $this->assertSame('mixed', $changed->source_kind);
        $this->assertStringContainsString('CIの記録', $changed->suggested_next_action);
        $this->assertSame('task_evidence', $changed->evidence_snapshots[1]['kind']);
        $this->assertSame($obs->id, PlanActionDraftRevision::sole()->added_evidence_snapshots[0]['task_evidence_id']);
        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), $payload)->assertStatus(409);
        $this->assertDatabaseCount('plan_action_draft_revisions', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame(50, $task->fresh()->progress_percent);
    }
}
