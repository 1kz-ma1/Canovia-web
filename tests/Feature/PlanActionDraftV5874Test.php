<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\PlanActionDraftStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionDraftV5874Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array', 'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    private function plan(User $owner, string $category = '資格学習', bool $team = false): Plan
    {
        return Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '実際の行動から進める',
            'category' => $category, 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(40),
            'is_public' => false, 'is_collaborative' => $team,
        ]);
    }

    private function draft(Plan $plan, string $status = PlanActionDraft::PROPOSED): PlanActionDraft
    {
        return PlanActionDraft::create([
            'plan_id' => $plan->id, 'request_id' => (string) Str::uuid(),
            'source_kind' => 'self_report', 'completed_action' => '仕訳を練習',
            'observed_outcome' => '借貸で迷った',
            'suggested_next_action' => '借貸の問題を3問見直す',
            'status' => $status,
        ]);
    }

    private function existingTask(Plan $plan): Task
    {
        return $plan->tasks()->create([
            'title' => '既存の重要Task', 'estimated_minutes' => 45,
            'remaining_minutes' => 30, 'progress_percent' => 35,
            'status' => 'doing', 'priority' => 1, 'activation_cost' => 2,
            'sort_order' => 17,
        ]);
    }

    private function prepare(User $owner, Plan $plan, PlanActionDraft $draft): void
    {
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))
            ->assertRedirect(route('plans.action_drafts.index', $plan));
    }

    private function fingerprint(PlanActionDraft $draft): string
    {
        return app(PlanActionDraftStepService::class)->fingerprint($draft->fresh()->steps);
    }

    public function test_prepare_edit_and_accept_creates_exactly_three_sequential_tasks_atomically(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '資格学習', true);
        $old = $this->existingTask($plan);
        $draft = $this->draft($plan);

        $this->prepare($owner, $plan, $draft);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_dependencies', 0);

        $steps = $draft->fresh()->steps;
        $this->assertCount(3, $steps);
        $this->assertSame([1, 2, 3], $steps->pluck('sort_order')->all());
        $this->assertSame('借貸の問題を3問見直す', $steps[0]->title);
        $this->assertStringContainsString('演習や復習', $steps[1]->title);
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('3段階のステップ案')
            ->assertSee('3つのTaskと前提関係を確認して承認');

        // A retry of the prepare action never discards the owner's work.
        $titles = $steps->mapWithKeys(fn ($step) => [
            $step->id => '本人が確認したSTEP '.$step->sort_order,
        ])->all();
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'step_titles' => $titles,
        ])->assertRedirect();
        $this->prepare($owner, $plan, $draft);
        $this->assertSame(array_values($titles), $draft->fresh()->steps->pluck('title')->all());
        $this->assertDatabaseCount('tasks', 1);

        $payload = ['accept_mode' => 'bundle', 'steps_fingerprint' => $this->fingerprint($draft)];
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), $payload)
            ->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('tasks', 4);
        $this->assertDatabaseCount('task_dependencies', 2);
        $new = $plan->tasks()->where('title', 'like', '本人が確認したSTEP %')
            ->orderBy('sort_order')->get();
        $this->assertCount(3, $new);
        $this->assertSame([18, 19, 20], $new->pluck('sort_order')->all());
        $this->assertSame([], $new[0]->dependencyIds());
        $this->assertSame([$new[0]->id], $new[1]->dependencyIds());
        $this->assertSame([$new[1]->id], $new[2]->dependencyIds());
        $this->assertSame('todo', $new[2]->status);
        $this->assertSame(0, $new[2]->progress_percent);
        $this->assertSame(0, $new[2]->estimated_minutes);
        $this->assertSame(35, $old->fresh()->progress_percent);
        $this->assertSame(30, $old->fresh()->remaining_minutes);
        $this->assertSame(1, $old->fresh()->priority);
        $this->assertSame('accepted', $draft->fresh()->status);
        $this->assertSame($new[0]->id, $draft->fresh()->accepted_task_id);
        $this->assertSame($new->pluck('id')->all(), $draft->fresh()->steps->pluck('accepted_task_id')->all());
        $this->assertDatabaseCount('plan_activity_logs', 3);
    }

    public function test_stale_bundle_requires_explicit_rebuild_after_evidence_refresh(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $old = $this->existingTask($plan);
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $before = $this->fingerprint($draft);

        $log = WorkLog::create([
            'plan_id' => $plan->id, 'task_id' => $old->id,
            'task_title_snapshot' => '過去問を解く', 'worked_on' => today(),
            'actual_minutes' => 30, 'progress_delta_percent' => 0,
            'progress_before_percent' => 35, 'progress_after_percent' => 35,
            'remaining_minutes_before' => 30, 'remaining_minutes_after' => 30,
            'outcome' => 'ネットワークで迷った',
        ]);
        $preview = $this->actingAs($owner)->post(
            route('plans.action_drafts.refresh.preview', [$plan, $draft]),
            ['additional_work_log_ids' => [$log->id]],
        )->assertOk();
        $body = $preview->getContent();
        preg_match('/name="source_fingerprint" value="([^"]+)"/', $body, $src);
        preg_match('/name="candidate_fingerprint" value="([^"]+)"/', $body, $candidate);
        $this->assertNotEmpty($src[1] ?? null);
        $this->assertNotEmpty($candidate[1] ?? null);

        $this->actingAs($owner)->post(route('plans.action_drafts.refresh.apply', [$plan, $draft]), [
            'expected_revision' => 1,
            'source_fingerprint' => $src[1],
            'candidate_fingerprint' => $candidate[1],
            'additional_work_log_ids' => [$log->id],
        ])->assertRedirect();
        $this->assertSame(2, $draft->fresh()->revision_no);
        $this->assertSame(3, $draft->fresh()->steps->count());
        $this->assertDatabaseCount('tasks', 1);
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('再作成するまで承認できません');

        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $before,
        ])->assertStatus(409);
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))
            ->assertStatus(409);
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]), [
            'rebuild' => 1,
        ])->assertRedirect();
        $this->assertTrue($draft->fresh()->steps->every(fn ($step) => (int) $step->evidence_revision === 2));
        $this->assertStringContainsString('2件', $draft->fresh()->steps[0]->title);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_form_fingerprint_catches_stale_edit_and_single_mode_mismatch(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $before = $this->fingerprint($draft);
        $steps = $draft->fresh()->steps;
        $titles = $steps->mapWithKeys(fn ($step) => [
            $step->id => '修正したSTEP '.$step->sort_order,
        ])->all();
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'step_titles' => $titles,
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $before,
        ])->assertStatus(409);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'single',
        ])->assertStatus(409);
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'step_titles' => ['999999' => '攻撃', $steps[1]->id => '2', $steps[2]->id => '3'],
        ])->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertSame('proposed', $draft->fresh()->status);
    }

    public function test_owner_permissions_cross_plan_and_terminal_states_protect_bundle_mutations(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '個人開発', true);
        $other = $this->plan($owner);
        $draft = $this->draft($plan);
        foreach ([PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER] as $role) {
            $member = User::factory()->create();
            PlanMember::create([
                'plan_id' => $plan->id, 'user_id' => $member->id,
                'role' => $role, 'invited_by_user_id' => $owner->id, 'joined_at' => now(),
            ]);
            $this->actingAs($member)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))
                ->assertForbidden();
        }
        $this->actingAs(User::factory()->create())
            ->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))->assertForbidden();
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$other, $draft]))
            ->assertNotFound();
        $this->prepare($owner, $plan, $draft);
        $this->assertStringContainsString('開発項目', $draft->fresh()->steps[1]->title);
        $this->actingAs($owner)->post(route('plans.action_drafts.dismiss', [$plan, $draft]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))
            ->assertStatus(409);
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'step_titles' => $draft->fresh()->steps->mapWithKeys(fn ($s) => [
                $s->id => '変更',
            ])->all(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_legacy_single_task_acceptance_still_creates_only_one_task(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '就活・キャリア');
        $draft = $this->draft($plan);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))
            ->assertRedirect();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('plan_action_draft_steps', 0);
        $this->assertDatabaseCount('task_dependencies', 0);
        $this->assertSame('借貸の問題を3問見直す', Task::sole()->title);
    }

    public function test_deleted_accepted_task_does_not_reopen_a_completed_bundle(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '就活・キャリア');
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $this->assertStringContainsString('職種や企業', $draft->fresh()->steps[1]->title);
        $payload = ['accept_mode' => 'bundle', 'steps_fingerprint' => $this->fingerprint($draft)];
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), $payload)
            ->assertRedirect();
        $firstId = $draft->fresh()->accepted_task_id;
        $this->assertNotNull($firstId);
        Task::findOrFail($firstId)->delete();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), $payload)
            ->assertRedirect();
        $this->assertDatabaseCount('tasks', 2);
        $this->assertSame('accepted', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->accepted_task_id);
    }
}
