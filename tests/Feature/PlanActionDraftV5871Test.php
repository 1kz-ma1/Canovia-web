<?php
namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionDraftV5871Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled']);
    }

    private function plan(User $owner, bool $collaborative = false): Plan
    {
        return Plan::create(['user_id' => $owner->id, 'title' => '次の一歩',
            'category' => '資格学習', 'is_collaborative' => $collaborative]);
    }

    private function proposal(Plan $plan, string $id): PlanActionDraft
    {
        return PlanActionDraft::where('plan_id', $plan->id)->where('request_id', $id)->sole();
    }

    public function test_manual_evidence_creates_inert_proposal_and_accept_once(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $requestId = (string) Str::uuid();
        $data = ['request_id' => $requestId,
            'completed_action' => '仕訳の練習をした',
            'observed_outcome' => '借貸の判断で二度間違えた'];
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('行動から育つ計画案');
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), $data)
            ->assertRedirect(route('plans.action_drafts.index', $plan));

        $draft = $this->proposal($plan, $requestId);
        $this->assertSame(PlanActionDraft::PROPOSED, $draft->status);
        $this->assertSame('self_report', $draft->source_kind);
        $this->assertSame('借貸の判断で二度間違えた', $draft->observed_outcome);
        $this->assertDatabaseCount('tasks', 0);
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), $data)->assertRedirect();
        $this->assertDatabaseCount('plan_action_drafts', 1);

        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '借貸の判断問題を3問解く',
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))
            ->assertRedirect();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame(PlanActionDraft::ACCEPTED, $draft->fresh()->status);
        $task = Task::sole();
        $this->assertSame('借貸の判断問題を3問解く', $task->title);
        $this->assertSame('todo', $task->status);
        $this->assertSame(0, $task->progress_percent);
        $this->assertSame($task->id, $draft->fresh()->accepted_task_id);
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '変更する',
        ])->assertStatus(409);
    }

    public function test_legacy_work_log_is_scoped_to_plan_and_snapshot_is_kept(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $otherPlan = $this->plan($owner);
        $task = Task::create(['plan_id' => $plan->id, 'title' => '先に進む', 'estimated_minutes' => 30,
            'remaining_minutes' => 30, 'progress_percent' => 25,
            'status' => 'doing', 'priority' => 3, 'activation_cost' => 3, 'sort_order' => 0]);
        $log = WorkLog::create(['plan_id' => $plan->id, 'task_id' => $task->id,
            'task_title_snapshot' => '科目Aの問題を解く', 'worked_on' => '2026-10-08',
            'actual_minutes' => 15, 'progress_delta_percent' => 0,
            'progress_before_percent' => 25, 'progress_after_percent' => 25,
            'remaining_minutes_before' => 30, 'remaining_minutes_after' => 30,
            'outcome' => 'ネットワークの計算が苦手だと分かった']);
        $bad = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $otherPlan), [
            'request_id' => $bad, 'source_work_log_id' => $log->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('plan_action_drafts', 0);

        $good = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), [
            'request_id' => $good, 'source_work_log_id' => $log->id,
        ])->assertRedirect();
        $draft = $this->proposal($plan, $good);
        $this->assertSame('work_log', $draft->source_kind);
        $this->assertSame($log->id, $draft->source_work_log_id);
        $this->assertSame('科目Aの問題を解く', $draft->completed_action);
        $this->assertSame('ネットワークの計算が苦手だと分かった', $draft->observed_outcome);
        $this->assertSame(25, $task->fresh()->progress_percent);
        $log->delete();
        $this->assertSame('ネットワークの計算が苦手だと分かった', $draft->fresh()->observed_outcome);
    }

    public function test_rejection_and_cross_plan_draft_do_not_mutate_tasks(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $otherPlan = $this->plan($owner);
        $id = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), [
            'request_id' => $id, 'completed_action' => '開発環境を作った', 'observed_outcome' => '初回起動した',
        ])->assertRedirect();
        $draft = $this->proposal($plan, $id);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$otherPlan, $draft]))->assertNotFound();
        $this->actingAs($owner)->post(route('plans.action_drafts.dismiss', [$plan, $draft]))->assertRedirect();
        $this->assertSame(PlanActionDraft::DISMISSED, $draft->fresh()->status);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_collaborative_editor_viewer_and_stranger_are_forbidden(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, true);
        $id = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.action_drafts.store', $plan), [
            'request_id' => $id, 'completed_action' => '議事録を書いた', 'observed_outcome' => '次の課題を把握した',
        ])->assertRedirect();
        $draft = $this->proposal($plan, $id);
        foreach ([PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER] as $role) {
            $member = User::factory()->create();
            PlanMember::create(['plan_id' => $plan->id, 'user_id' => $member->id,
                'role' => $role, 'invited_by_user_id' => $owner->id, 'joined_at' => now()]);
            $this->actingAs($member)->get(route('plans.action_drafts.index', $plan))->assertForbidden();
            $this->actingAs($member)->post(route('plans.action_drafts.accept', [$plan, $draft]))->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->post(route('plans.action_drafts.dismiss', [$plan, $draft]))
            ->assertForbidden();
        $this->assertSame(PlanActionDraft::PROPOSED, $draft->fresh()->status);
    }
}
