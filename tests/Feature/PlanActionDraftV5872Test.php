<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanActionDraftRevision;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionDraftV5872Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    private function plan(User $owner, bool $team = false): Plan
    {
        return Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'APの実力を上げる',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(40),
            'is_collaborative' => $team, 'is_public' => false,
        ]);
    }

    private function log(Plan $plan, string $title, string $outcome, string $date = '2026-10-08'): WorkLog
    {
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => $title,
            'estimated_minutes' => 20, 'remaining_minutes' => 15,
            'progress_percent' => 25, 'status' => 'doing', 'priority' => 3,
            'activation_cost' => 3, 'sort_order' => 0,
        ]);
        return WorkLog::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'task_title_snapshot' => $title, 'worked_on' => $date,
            'actual_minutes' => 5, 'progress_delta_percent' => 0,
            'progress_before_percent' => 25, 'progress_after_percent' => 25,
            'remaining_minutes_before' => 15, 'remaining_minutes_after' => 15,
            'outcome' => $outcome,
        ]);
    }

    private function hidden(string $html, string $field): string
    {
        $this->assertSame(1, preg_match('/name="'.preg_quote($field, '/').'" value="([^"]+)"/', $html, $match));
        return $match[1];
    }

    public function test_multiple_work_logs_create_sourced_proposal_without_modifying_existing_tasks(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $first = $this->log($plan, 'ネットワークを練習', 'サブネットの計算で失点', '2026-10-06');
        $second = $this->log($plan, 'DBを練習', 'SQLの結合で詰まる', '2026-10-07');
        $rid = (string) Str::uuid();
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('data-multi-evidence-compose', false);
        $this->actingAs($owner)->post(route('plans.action_drafts.compose', $plan), [
            'request_id' => $rid, 'work_log_ids' => [$second->id, $first->id],
        ])->assertRedirect();

        $draft = PlanActionDraft::where('request_id', $rid)->sole();
        $this->assertSame('multi_work_log', $draft->source_kind);
        $this->assertSame(1, $draft->revision_no);
        $this->assertSame('proposed', $draft->status);
        $this->assertCount(2, $draft->evidence_snapshots);
        $this->assertSame([$first->id, $second->id], array_column($draft->evidence_snapshots, 'work_log_id'));
        $this->assertStringContainsString('2件', $draft->suggested_next_action);
        $this->assertEqualsCanonicalizing([25, 25], Task::pluck('progress_percent')->all());

        $this->actingAs($owner)->post(route('plans.action_drafts.compose', $plan), [
            'request_id' => $rid, 'work_log_ids' => [$first->id, $second->id],
        ])->assertRedirect();
        $this->assertDatabaseCount('plan_action_drafts', 1);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_cross_plan_evidence_and_invalid_selection_never_create_proposals(): void
    {
        $owner = User::factory()->create();
        $one = $this->plan($owner);
        $two = $this->plan($owner);
        $log1 = $this->log($one, 'A', '分かった');
        $log2 = $this->log($two, 'B', 'できなかった');
        $this->actingAs($owner)->post(route('plans.action_drafts.compose', $one), [
            'request_id' => (string) Str::uuid(), 'work_log_ids' => [$log1->id, $log2->id],
        ])->assertNotFound();
        $this->actingAs($owner)->post(route('plans.action_drafts.compose', $one), [
            'request_id' => (string) Str::uuid(), 'work_log_ids' => [$log1->id, $log1->id],
        ])->assertSessionHasErrors('work_log_ids.1');
        $this->assertDatabaseCount('plan_action_drafts', 0);
    }

    public function test_diff_preview_is_read_only_and_explicit_application_creates_revision(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $source = $this->log($plan, 'ネットワークを解く', 'IP計算で失点');
        $another = $this->log($plan, 'DBを解く', '結合に迷った');
        $rid = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), [
            'request_id' => $rid, 'source_work_log_id' => $source->id,
        ])->assertRedirect();
        $draft = PlanActionDraft::where('request_id', $rid)->sole();
        $old = $draft->suggested_next_action;

        $preview = $this->actingAs($owner)->post(
            route('plans.action_drafts.refresh.preview', [$plan, $draft]),
            ['additional_work_log_ids' => [$another->id]],
        )->assertOk()->assertSee('変更前と変更後を確認')
            ->assertSee('2件')->assertSee('DBを解く');

        $this->assertNull($draft->fresh()->evidence_snapshots);
        $this->assertDatabaseCount('plan_action_draft_revisions', 0);
        $this->assertDatabaseCount('tasks', 2);

        $body = $preview->getContent();
        $payload = [
            'additional_work_log_ids' => [$another->id],
            'expected_revision' => 1,
            'candidate_fingerprint' => $this->hidden($body, 'candidate_fingerprint'),
            'source_fingerprint' => $this->hidden($body, 'source_fingerprint'),
        ];
        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), $payload)
            ->assertRedirect(route('plans.action_drafts.index', $plan));
        $revised = $draft->fresh();
        $this->assertSame(2, $revised->revision_no);
        $this->assertSame('multi_work_log', $revised->source_kind);
        $this->assertCount(2, $revised->evidence_snapshots);
        $this->assertStringContainsString('2件', $revised->suggested_next_action);
        $this->assertSame($old, PlanActionDraftRevision::sole()->before_action);
        $this->assertSame($revised->suggested_next_action, PlanActionDraftRevision::sole()->after_action);
        $this->assertCount(1, PlanActionDraftRevision::sole()->added_evidence_snapshots);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertEqualsCanonicalizing([25, 25], Task::pluck('progress_percent')->all());

        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), $payload)->assertStatus(409);
        $this->assertDatabaseCount('plan_action_draft_revisions', 1);
    }

    public function test_user_edits_and_work_log_changes_invalidate_stale_previews(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $a = $this->log($plan, '基礎', '基本はできた');
        $b = $this->log($plan, '応用', '応用でつまずいた');
        $rid = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), [
            'request_id' => $rid, 'completed_action' => '自習した',
            'observed_outcome' => '練習を始めた',
        ])->assertRedirect();
        $draft = PlanActionDraft::where('request_id', $rid)->sole();
        $preview = $this->actingAs($owner)->post(
            route('plans.action_drafts.refresh.preview', [$plan, $draft]),
            ['additional_work_log_ids' => [$a->id]],
        )->assertOk();
        $payload = [
            'expected_revision' => 1, 'additional_work_log_ids' => [$a->id],
            'candidate_fingerprint' => $this->hidden($preview->getContent(), 'candidate_fingerprint'),
            'source_fingerprint' => $this->hidden($preview->getContent(), 'source_fingerprint'),
        ];
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '本人が考えた次の行動',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), $payload)->assertStatus(409);
        $this->assertSame('本人が考えた次の行動', $draft->fresh()->suggested_next_action);

        $preview2 = $this->actingAs($owner)->post(
            route('plans.action_drafts.refresh.preview', [$plan, $draft]),
            ['additional_work_log_ids' => [$b->id]],
        )->assertOk();
        $payload2 = [
            'expected_revision' => 1, 'additional_work_log_ids' => [$b->id],
            'candidate_fingerprint' => $this->hidden($preview2->getContent(), 'candidate_fingerprint'),
            'source_fingerprint' => $this->hidden($preview2->getContent(), 'source_fingerprint'),
        ];
        $b->update(['outcome' => '見直したら結果が変わった']);
        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), $payload2)->assertStatus(409);
        $this->assertDatabaseCount('plan_action_draft_revisions', 0);
    }

    public function test_editor_viewer_and_unrelated_users_cannot_compose_or_refresh(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, true);
        $a = $this->log($plan, 'A', '成功');
        $b = $this->log($plan, 'B', '失敗');
        $this->actingAs($owner)->post(route('plans.action_drafts.compose', $plan), [
            'request_id' => (string) Str::uuid(),
            'work_log_ids' => [$a->id, $b->id],
        ])->assertRedirect();
        $draft = PlanActionDraft::sole();

        foreach ([PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER] as $role) {
            $member = User::factory()->create();
            PlanMember::create([
                'plan_id' => $plan->id, 'user_id' => $member->id, 'role' => $role,
                'invited_by_user_id' => $owner->id, 'joined_at' => now(),
            ]);
            $this->actingAs($member)->post(route('plans.action_drafts.compose', $plan), [
                'request_id' => (string) Str::uuid(), 'work_log_ids' => [$a->id, $b->id],
            ])->assertForbidden();
            $this->actingAs($member)->post(route('plans.action_drafts.refresh.preview', [$plan, $draft]), [
                'additional_work_log_ids' => [$a->id],
            ])->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->post(
            route('plans.action_drafts.refresh.apply', [$plan, $draft]),
            ['additional_work_log_ids' => [$a->id]],
        )->assertForbidden();
        $this->assertDatabaseCount('plan_action_drafts', 1);
        $this->assertDatabaseCount('plan_action_draft_revisions', 0);
    }

    public function test_accepted_and_dismissed_drafts_cannot_be_regenerated(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $log = $this->log($plan, '解いた', '間違えた');
        foreach (['accepted', 'dismissed'] as $status) {
            $draft = PlanActionDraft::create([
                'plan_id' => $plan->id, 'request_id' => (string) Str::uuid(),
                'source_kind' => 'self_report', 'completed_action' => '練習',
                'observed_outcome' => '失点', 'suggested_next_action' => '次をやる',
                'status' => $status,
            ]);
            $this->actingAs($owner)->post(route('plans.action_drafts.refresh.preview', [$plan, $draft]), [
                'additional_work_log_ids' => [$log->id],
            ])->assertStatus(409);
        }
    }
}
