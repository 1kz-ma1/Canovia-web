<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionThread;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionContextService;
use App\Services\GoalContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaCompanionContextualEntryV4115Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_task_screen_entry_opens_task_context_without_manual_scope_selection(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $sourcePath = '/tasks/'.$task->id.'/edit';
        $response = $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'task_id' => $task->id,
                'source_path' => $sourcePath,
                'source_route' => 'tasks.edit',
            ]);

        $thread = CompanionThread::firstOrFail();

        $response->assertRedirect(route('companion.show', $thread));
        $this->assertSame($user->id, $thread->user_id);
        $this->assertSame($plan->id, $thread->plan_id);
        $this->assertSame($task->id, $thread->task_id);
        $this->assertSame('task', data_get($thread->context_scope, 'scope'));
        $this->assertSame('task', data_get($thread->context_scope, 'entry_type'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'entry_key'));
        $this->assertSame($sourcePath, data_get($thread->context_scope, 'source_path'));
        $this->assertSame('tasks.edit', data_get($thread->context_scope, 'source_route'));

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('CONTEXT INHERITED')
            ->assertSee('Task · '.$task->title)
            ->assertSee('value="'.$sourcePath.'"', false);
    }

    public function test_guided_execution_reuses_task_thread_and_exposes_latest_evidence(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'task_id' => $task->id,
                'source_path' => '/tasks/'.$task->id.'/edit',
                'source_route' => 'tasks.edit',
            ])
            ->assertRedirect();

        $thread = CompanionThread::firstOrFail();

        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'guided_execution_reflected',
            'external_key' => 'contextual-entry-evidence-'.$task->id,
            'confidence' => 0.7,
            'occurred_at' => now(),
            'metadata' => [
                'intent' => '購入時期を先に確認する',
                'actual_outcome' => '見積提示前に購入時期を確認できた',
                'next_adjustment' => '次回は予算も序盤で確認する',
            ],
        ]);

        $guidedPath = '/plans/'.$plan->id.'/tasks/'.$task->id.'/guided-execution';
        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'guided_execution',
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'source_path' => $guidedPath,
                'source_route' => 'plans.tasks.guided_execution.show',
            ])
            ->assertRedirect(route('companion.show', $thread));

        $this->assertDatabaseCount('companion_threads', 1);

        $thread->refresh();
        $this->assertSame('guided_execution', data_get($thread->context_scope, 'entry_type'));
        $this->assertSame('task:'.$task->id, data_get($thread->context_scope, 'entry_key'));
        $this->assertSame($guidedPath, data_get($thread->context_scope, 'source_path'));

        $snapshot = app(CompanionContextService::class)->snapshot(
            $user,
            $plan,
            $task,
            $thread->context_scope,
        );

        $this->assertSame('guided_execution', data_get($snapshot, 'entry.type'));
        $this->assertSame('guided_execution_reflected', data_get($snapshot, 'entry.latest_evidence.type'));
        $this->assertStringContainsString(
            '見積提示前に購入時期を確認できた',
            (string) data_get($snapshot, 'entry.latest_evidence.summary'),
        );

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('実行・振り返り · '.$task->title)
            ->assertSee('最新Evidence')
            ->assertSee('見積提示前に購入時期を確認できた');
    }

    public function test_plan_entry_reuses_legacy_plan_thread_and_refreshes_origin_metadata(): void
    {
        [$user, $plan] = $this->scenario();
        $this->grantPremium($user);

        $legacy = CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => null,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'plan'],
        ]);

        $sourcePath = '/roadmap?plan_id='.$plan->id;
        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'plan',
                'plan_id' => $plan->id,
                'source_path' => $sourcePath,
                'source_route' => 'roadmap.index',
            ])
            ->assertRedirect(route('companion.show', $legacy));

        $this->assertDatabaseCount('companion_threads', 1);
        $legacy->refresh();

        $this->assertSame('plan', data_get($legacy->context_scope, 'entry_type'));
        $this->assertSame('plan:'.$plan->id, data_get($legacy->context_scope, 'entry_key'));
        $this->assertSame($sourcePath, data_get($legacy->context_scope, 'source_path'));
    }

    public function test_inbox_item_gets_its_own_context_even_when_it_is_linked_to_a_plan(): void
    {
        [$user, $plan] = $this->scenario();
        $this->grantPremium($user);

        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'plan',
                'plan_id' => $plan->id,
                'source_path' => '/plans/'.$plan->id,
                'source_route' => 'plans.show',
            ])
            ->assertRedirect();

        $planThread = CompanionThread::firstOrFail();

        $item = InboxItem::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => '商談メモ',
            'content' => '購入時期は来月。予算の確認がまだできていない。',
            'metadata' => [],
        ]);

        $sourcePath = '/inbox#inbox-item-'.$item->id;
        $response = $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'inbox_item',
                'inbox_item_id' => $item->id,
                'plan_id' => $plan->id,
                'source_path' => $sourcePath,
                'source_route' => 'inbox.index',
            ]);

        $inboxThread = CompanionThread::query()
            ->where('id', '!=', $planThread->id)
            ->firstOrFail();

        $response->assertRedirect(route('companion.show', $inboxThread));
        $this->assertDatabaseCount('companion_threads', 2);
        $this->assertSame($plan->id, $inboxThread->plan_id);
        $this->assertNull($inboxThread->task_id);
        $this->assertSame('inbox_item', data_get($inboxThread->context_scope, 'entry_type'));
        $this->assertSame('inbox:'.$item->id, data_get($inboxThread->context_scope, 'entry_key'));
        $this->assertSame($item->id, data_get($inboxThread->context_scope, 'inbox_item_id'));

        $snapshot = app(CompanionContextService::class)->snapshot(
            $user,
            $plan,
            null,
            $inboxThread->context_scope,
        );

        $this->assertSame($item->id, data_get($snapshot, 'entry.inbox_item.id'));
        $this->assertSame($item->content, data_get($snapshot, 'entry.inbox_item.content'));

        $this->actingAs($user)
            ->get(route('companion.show', $inboxThread))
            ->assertOk()
            ->assertSee('Inbox · 商談メモ')
            ->assertSee('購入時期は来月。予算の確認がまだできていない。');
    }

    public function test_contextual_entry_rejects_context_owned_by_another_user(): void
    {
        [$owner, $plan, $task] = $this->scenario();
        $other = User::factory()->create();
        $this->grantPremium($owner);
        $this->grantPremium($other);

        $item = InboxItem::query()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => 'owner only',
            'content' => 'private context',
            'metadata' => [],
        ]);

        $this->actingAs($other)
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'task_id' => $task->id,
            ])
            ->assertForbidden();

        $this->actingAs($other)
            ->post(route('companion.entry'), [
                'entry_type' => 'inbox_item',
                'inbox_item_id' => $item->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('companion_threads', 0);
    }

    public function test_free_user_contextual_launcher_falls_back_to_companion_value_screen(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'task_id' => $task->id,
                'source_path' => '/tasks/'.$task->id.'/edit',
                'source_route' => 'tasks.edit',
            ])
            ->assertRedirect(route('companion.index'));

        $this->assertDatabaseCount('companion_threads', 0);
    }

    public function test_layout_launcher_uses_current_task_and_selected_roadmap_plan_context(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $this->actingAs($user)
            ->get(route('tasks.edit', $task))
            ->assertOk()
            ->assertSee('action="'.route('companion.entry').'"', false)
            ->assertSee('name="entry_type" value="task"', false)
            ->assertSee('name="task_id" value="'.$task->id.'"', false);

        $this->actingAs($user)
            ->get(route('roadmap.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('name="entry_type" value="plan"', false)
            ->assertSee('name="plan_id" value="'.$plan->id.'"', false);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '車を月10台販売する',
            'description' => '販売営業の改善',
            'category' => '仕事',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '顧客と商談する',
            'description' => '購入時期とニーズを確認する',
            'next_action_note' => '見積提示まで進める',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        $goalContexts = app(GoalContextService::class);
        $context = $goalContexts->ensureForPlan($plan, $user->id);
        $context->update(['current_state_summary' => '今月は4台販売']);

        return [$user, $plan, $task];
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
