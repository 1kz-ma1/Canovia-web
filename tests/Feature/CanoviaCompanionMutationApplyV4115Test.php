<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\FutureMemo;
use App\Models\GoalContextFact;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\GoalContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaCompanionMutationApplyV4115Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_human_confirmed_task_update_applies_safe_fields_and_blocks_progress_or_completion(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($user, $thread, $plan, $task, 'update_task', [
            'next_action_note' => '商談の序盤で購入時期を確認する',
            'priority' => 1,
            'progress_percent' => 95,
            'status' => 'done',
        ]);

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('反映される内容')
            ->assertSee('次のAction')
            ->assertSee('反映しない項目')
            ->assertSee('進捗')
            ->assertSee('状態')
            ->assertSee('この内容を反映する');

        $requestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => $requestId,
            ])
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasNoErrors();

        $task->refresh();
        $candidate->refresh();

        $this->assertSame('商談の序盤で購入時期を確認する', $task->next_action_note);
        $this->assertSame(1, $task->priority);
        $this->assertSame(20, $task->progress_percent);
        $this->assertSame('doing', $task->status);

        $this->assertSame(CompanionMutationCandidate::STATUS_APPLIED, $candidate->status);
        $this->assertSame($requestId, $candidate->apply_request_id);
        $this->assertSame('task', $candidate->applied_target_type);
        $this->assertSame($task->id, $candidate->applied_target_id);
        $this->assertSame(
            ['progress_percent', 'status'],
            data_get($candidate->metadata, 'apply_audit.blocked_fields'),
        );
        $this->assertSame(
            '商談の序盤で購入時期を確認する',
            data_get($candidate->metadata, 'apply_audit.after.next_action_note'),
        );
    }

    public function test_create_task_apply_is_idempotent_and_never_creates_progress_claim(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($user, $thread, $plan, null, 'create_task', [
            'title' => '見込み客へ翌日フォローする',
            'description' => '商談後24時間以内に連絡する',
            'estimated_minutes' => 30,
            'priority' => 2,
            'activation_cost' => 2,
            'progress_percent' => 100,
            'status' => 'done',
        ]);

        $requestId = (string) Str::uuid();
        $payload = ['apply_request_id' => $requestId];

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $created = Task::query()
            ->where('plan_id', $plan->id)
            ->where('title', '見込み客へ翌日フォローする')
            ->get();

        $this->assertCount(1, $created);
        $this->assertSame(0, $created->first()->progress_percent);
        $this->assertSame('todo', $created->first()->status);
        $this->assertSame(30, $created->first()->remaining_minutes);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertSame($created->first()->id, $candidate->fresh()->applied_target_id);
    }

    public function test_plan_update_is_owner_only_syncs_goal_title_and_ignores_public_setting(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($user, $thread, $plan, null, 'update_plan', [
            'title' => '車を月12台販売する',
            'priority' => 1,
            'deadline' => today()->addMonths(2)->toDateString(),
            'is_public' => true,
        ]);

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan->refresh();
        $context = $plan->goalContext()->firstOrFail();

        $this->assertSame('車を月12台販売する', $plan->title);
        $this->assertSame(1, $plan->priority);
        $this->assertFalse($plan->is_public);
        $this->assertSame('車を月12台販売する', $context->desired_state);
        $this->assertSame(
            ['is_public'],
            data_get($candidate->fresh()->metadata, 'apply_audit.blocked_fields'),
        );
    }

    public function test_goal_fact_requires_human_confirmation_and_updates_current_state_summary(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $context = $plan->goalContext()->firstOrFail();
        $beforeCount = $context->facts()->where('state', 'confirmed')->count();

        $candidate = $this->candidate($user, $thread, $plan, null, 'record_goal_fact', [
            'type' => 'current_state',
            'key' => 'monthly_sales_current',
            'label' => '現在の月間販売台数',
            'value' => ['text' => '今月は6台販売'],
            'importance' => 4,
        ]);

        $this->assertSame($beforeCount, $context->facts()->where('state', 'confirmed')->count());

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $context->refresh();
        $fact = GoalContextFact::query()
            ->where('goal_context_id', $context->id)
            ->where('key', 'monthly_sales_current')
            ->firstOrFail();

        $this->assertSame('confirmed', $fact->state);
        $this->assertSame('native_ai', $fact->source);
        $this->assertSame(1.0, $fact->confidence);
        $this->assertSame('canovia_companion_human_confirmed', data_get($fact->metadata, 'origin'));
        $this->assertSame($user->id, data_get($fact->metadata, 'confirmed_by_user_id'));
        $this->assertSame('今月は6台販売', $context->current_state_summary);
        $this->assertSame($beforeCount + 1, $context->facts()->where('state', 'confirmed')->count());
    }

    public function test_future_memo_and_inbox_candidates_use_existing_domain_shapes(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();

        $memoCandidate = $this->candidate($user, $thread, null, null, 'create_future_memo', [
            'kind' => 'want_to_do',
            'category' => 'career',
            'content' => '来年は法人営業にも挑戦したい',
            'use_for_ai' => true,
        ]);

        $inboxCandidate = $this->candidate($user, $thread, $plan, null, 'create_inbox_item', [
            'title' => '商談で気づいたこと',
            'content' => '購入時期を序盤で確認すると提案の順番を変えられる。',
        ]);

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $memoCandidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $inboxCandidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $memo = FutureMemo::firstOrFail();
        $item = InboxItem::firstOrFail();

        $this->assertSame($user->id, $memo->user_id);
        $this->assertSame('want_to_do', $memo->kind);
        $this->assertSame('career', $memo->category);
        $this->assertTrue($memo->use_for_ai);

        $this->assertSame($user->id, $item->user_id);
        $this->assertSame($plan->id, $item->plan_id);
        $this->assertSame('text', $item->source_type);
        $this->assertSame('new', $item->status);
        $this->assertSame('canovia_companion_human_confirmed', data_get($item->metadata, 'origin'));
    }

    public function test_invalid_plan_dates_roll_back_without_marking_candidate_applied(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($user, $thread, $plan, null, 'update_plan', [
            'start_date' => '2026-12-10',
            'deadline' => '2026-12-01',
        ]);

        $beforeStart = $plan->start_date?->toDateString();
        $beforeDeadline = $plan->deadline?->toDateString();

        $this->actingAs($user)
            ->from(route('companion.show', $thread))
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasErrors('candidate');

        $candidate->refresh();
        $plan->refresh();

        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->status);
        $this->assertNull($candidate->apply_request_id);
        $this->assertNull($candidate->applied_at);
        $this->assertSame($beforeStart, $plan->start_date?->toDateString());
        $this->assertSame($beforeDeadline, $plan->deadline?->toDateString());
    }

    public function test_free_user_cannot_one_click_apply_existing_candidate(): void
    {
        [$premiumOwner, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($premiumOwner, $thread, $plan, $task, 'update_task', [
            'next_action_note' => '変更候補',
        ]);

        UserProductGrant::query()
            ->where('user_id', $premiumOwner->id)
            ->delete();

        $this->actingAs($premiumOwner)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertSame('見積提示まで進める', $task->fresh()->next_action_note);
    }

    public function test_apply_works_without_native_provider_when_candidate_already_exists(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        config(['native_ai.driver' => 'disabled']);

        $candidate = $this->candidate($user, $thread, $plan, $task, 'update_task', [
            'next_action_note' => '既存Candidateを人が反映する',
        ]);

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('既存Candidateを人が反映する', $task->fresh()->next_action_note);
        $this->assertSame(CompanionMutationCandidate::STATUS_APPLIED, $candidate->fresh()->status);
    }

    public function test_dismissed_candidate_cannot_be_applied(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($user, $thread, $plan, $task, 'update_task', [
            'next_action_note' => '反映されない',
        ]);

        $this->actingAs($user)
            ->post(route('companion.candidates.dismiss', [$thread, $candidate]))
            ->assertRedirect();

        $this->actingAs($user)
            ->from(route('companion.show', $thread))
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasErrors('candidate');

        $this->assertSame('見積提示まで進める', $task->fresh()->next_action_note);
        $this->assertSame(CompanionMutationCandidate::STATUS_DISMISSED, $candidate->fresh()->status);
    }

    public function test_other_user_cannot_apply_candidate(): void
    {
        [$owner, $plan, $task, $thread] = $this->scenario();
        $candidate = $this->candidate($owner, $thread, $plan, $task, 'update_task', [
            'next_action_note' => 'owner only',
        ]);

        $other = User::factory()->create();
        $this->grantPremium($other);

        $this->actingAs($other)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertSame('見積提示まで進める', $task->fresh()->next_action_note);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        $this->grantPremium($user);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '車を月10台販売する',
            'description' => '販売営業の改善',
            'category' => '仕事',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '顧客と商談する',
            'description' => '購入時期とニーズを確認する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 3,
            'activation_cost' => 2,
            'next_action_note' => '見積提示まで進める',
            'sort_order' => 1,
        ]);

        $goals = app(GoalContextService::class);
        $context = $goals->ensureForPlan($plan, $user->id);
        $context->update(['current_state_summary' => '今月は4台販売']);

        $thread = CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);

        return [$user, $plan, $task, $thread];
    }

    private function candidate(
        User $user,
        CompanionThread $thread,
        ?Plan $plan,
        ?Task $task,
        string $type,
        array $payload,
    ): CompanionMutationCandidate {
        $message = CompanionMessage::query()->create([
            'companion_thread_id' => $thread->id,
            'role' => 'assistant',
            'content' => '変更候補を確認してください。',
        ]);

        return CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $message->id,
            'user_id' => $user->id,
            'plan_id' => $plan?->id,
            'task_id' => $task?->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => $type,
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => 'テスト変更候補',
            'summary' => '人が確認してから反映する',
            'payload' => $payload,
            'metadata' => ['source' => 'canovia_companion'],
        ]);
    }

    private function grantPremium(User $user): void
    {
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
    }
}
