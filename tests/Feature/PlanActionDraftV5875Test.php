<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Services\PlanActionDraftStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanActionDraftV5875Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled']);
    }

    private function plan(User $owner, string $category = '資格学習', bool $collaborative = false): Plan
    {
        return Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '自習を進める',
            'category' => $category, 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
            'is_public' => false, 'is_collaborative' => $collaborative,
        ]);
    }

    private function draft(Plan $plan, string $action = '仕訳の弱点を3問復習する'): PlanActionDraft
    {
        return PlanActionDraft::create([
            'plan_id' => $plan->id, 'request_id' => (string) Str::uuid(),
            'source_kind' => 'self_report', 'completed_action' => '仕訳を解いた',
            'observed_outcome' => '間違えた問題があった',
            'suggested_next_action' => $action, 'status' => 'proposed',
        ]);
    }

    private function prepare(User $owner, Plan $plan, PlanActionDraft $draft): void
    {
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]))
            ->assertRedirect();
    }

    public function test_owner_text_edit_invalidates_existing_steps_without_changing_tasks(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $stepService = app(PlanActionDraftStepService::class);
        $previousFingerprint = $stepService->fingerprint($draft->fresh()->steps);

        $before = hash('sha256', $draft->suggested_next_action);
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '復習の順番を決めて取り組む',
            'expected_revision' => 1,
            'expected_candidate_fingerprint' => $before,
        ])->assertRedirect();

        $fresh = $draft->fresh();
        $this->assertSame(1, $fresh->revision_no); // evidence revision unchanged
        $this->assertFalse($stepService->isCurrent($plan, $fresh, $fresh->steps));
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('再作成するまで承認できません');
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $previousFingerprint,
        ])->assertStatus(409);
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'step_titles' =>
                $draft->fresh()->steps->mapWithKeys(fn ($step) => [$step->id => '不正な更新'])->all(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]), [
            'rebuild' => 1,
        ])->assertRedirect();
        $current = $draft->fresh();
        $this->assertTrue($stepService->isCurrent($plan, $current, $current->steps));
        $this->assertSame('復習の順番を決めて取り組む', $current->steps[0]->title);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $stepService->fingerprint($current->steps),
        ])->assertRedirect();
        $this->assertDatabaseCount('tasks', 3);
        $this->assertDatabaseCount('task_dependencies', 2);
    }

    public function test_owner_domain_correction_invalidates_stale_domain_steps(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '資格学習');
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $steps = $draft->fresh()->steps;
        $this->assertStringContainsString('演習や復習', $steps[1]->title);
        $original = app(PlanActionDraftStepService::class)->fingerprint($steps);

        $plan->update(['workspace_domain_override' => 'development']);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $original,
        ])->assertStatus(409);
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]), [
            'rebuild' => 1,
        ])->assertRedirect();
        $this->assertStringContainsString('開発項目', $draft->fresh()->steps[1]->title);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_legacy_null_fingerprint_requires_rebuild_not_silent_accept(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $this->prepare($owner, $plan, $draft);
        $steps = $draft->fresh()->steps;
        $old = app(PlanActionDraftStepService::class)->fingerprint($steps);
        $steps[1]->update(['proposal_fingerprint' => null]);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $old,
        ])->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);
        $this->actingAs($owner)->post(route('plans.action_drafts.steps.prepare', [$plan, $draft]), [
            'rebuild' => 1,
        ])->assertRedirect();
        $this->assertTrue(app(PlanActionDraftStepService::class)
            ->isCurrent($plan, $draft->fresh(), $draft->fresh()->steps));
    }

    public function test_stale_review_forms_do_not_overwrite_newer_candidate_or_step_edits(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $base = hash('sha256', $draft->suggested_next_action);
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '最初の人が修正',
            'expected_revision' => 1, 'expected_candidate_fingerprint' => $base,
        ])->assertRedirect();
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '後から古い画面で上書き',
            'expected_revision' => 1, 'expected_candidate_fingerprint' => $base,
        ])->assertStatus(409);
        $this->assertSame('最初の人が修正', $draft->fresh()->suggested_next_action);

        $this->prepare($owner, $plan, $draft);
        $steps = $draft->fresh()->steps;
        $fingerprint = app(PlanActionDraftStepService::class)->fingerprint($steps);
        $titles = $steps->mapWithKeys(fn ($step) => [$step->id => '編集 '.$step->sort_order])->all();
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'expected_steps_fingerprint' => $fingerprint,
            'step_titles' => $titles,
        ])->assertRedirect();
        $this->actingAs($owner)->patch(route('plans.action_drafts.steps.update', [$plan, $draft]), [
            'evidence_revision' => 1, 'expected_steps_fingerprint' => $fingerprint,
            'step_titles' => $steps->mapWithKeys(fn ($step) => [$step->id => '上書き'])->all(),
        ])->assertStatus(409);
        $this->assertSame(array_values($titles), $draft->fresh()->steps->pluck('title')->all());
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'bundle', 'steps_fingerprint' => $fingerprint,
        ])->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_duplicate_warning_is_observation_not_automatic_rejection(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '資格学習', true);
        $draft = $this->draft($plan);
        Task::create([
            'plan_id' => $plan->id, 'title' => $draft->suggested_next_action,
            'estimated_minutes' => 20, 'remaining_minutes' => 10,
            'progress_percent' => 50, 'status' => 'doing', 'priority' => 2,
            'activation_cost' => 3, 'sort_order' => 0,
        ]);
        $this->actingAs($owner)->get(route('plans.action_drafts.index', $plan))
            ->assertOk()->assertSee('data-draft-quality', false)
            ->assertSee('同じ名前の未完了Taskがすでにあります')
            ->assertSee('自己申告のみ');
        $this->assertDatabaseCount('tasks', 1);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'single', 'expected_revision' => 1,
            'expected_candidate_fingerprint' => hash('sha256', $draft->suggested_next_action),
        ])->assertRedirect();
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_stale_single_acceptance_form_rejects_changed_action_and_preserves_legacy_mode(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $draft = $this->draft($plan);
        $old = hash('sha256', $draft->suggested_next_action);
        $this->actingAs($owner)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
            'suggested_next_action' => '新しい行動', 'expected_candidate_fingerprint' => $old,
        ])->assertRedirect();
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]), [
            'accept_mode' => 'single', 'expected_revision' => 1,
            'expected_candidate_fingerprint' => $old,
        ])->assertStatus(409);
        $this->assertDatabaseCount('tasks', 0);
        $this->actingAs($owner)->post(route('plans.action_drafts.accept', [$plan, $draft]))
            ->assertRedirect();
        $this->assertSame('新しい行動', Task::sole()->title);
    }

    public function test_non_owner_cannot_use_new_review_guards_to_change_shared_draft(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '資格学習', true);
        $draft = $this->draft($plan);
        foreach ([PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER] as $role) {
            $member = User::factory()->create();
            PlanMember::create([
                'plan_id' => $plan->id, 'user_id' => $member->id,
                'role' => $role, 'invited_by_user_id' => $owner->id, 'joined_at' => now(),
            ]);
            $this->actingAs($member)->patch(route('plans.action_drafts.update', [$plan, $draft]), [
                'suggested_next_action' => '勝手に更新',
                'expected_candidate_fingerprint' => hash('sha256', $draft->suggested_next_action),
            ])->assertForbidden();
        }
        $this->assertSame('仕訳の弱点を3問復習する', $draft->fresh()->suggested_next_action);
    }
}
