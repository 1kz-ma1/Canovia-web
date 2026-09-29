<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\ExecutionRequestHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionRequestHandoffV471Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_inbox_execution_request_requires_human_target_confirmation_and_hands_off_to_orchestration(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $item = $this->inboxItem($user, 'このValidationをAIと一緒に進めたい');

        $response = $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'execution_request',
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'execution_instruction' => 'このValidationをAIと一緒に進めたい',
                'execution_actor_type' => 'human_ai',
                'execution_available_minutes' => 30,
            ]);

        $response
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('processed', $item->status);
        $this->assertSame('execution_request', data_get($item->metadata, 'routing_confirmed.destination'));
        $this->assertSame($plan->id, data_get($item->metadata, 'routing_confirmed.plan_id'));
        $this->assertSame($task->id, data_get($item->metadata, 'routing_confirmed.task_id'));
        $this->assertSame('execution_request', data_get($item->metadata, 'execution_request.flow'));
        $this->assertSame('このValidationをAIと一緒に進めたい', data_get($item->metadata, 'execution_request.instruction'));
        $this->assertSame('human_ai', data_get($item->metadata, 'execution_request.actor_type'));

        $state = session(ExecutionRequestHandoffService::sessionKey($plan, $task));
        $this->assertIsArray($state);
        $this->assertSame('execution_request', data_get($state, 'execution_request.flow'));
        $this->assertSame('inbox_item', data_get($state, 'execution_request.source.type'));
        $this->assertSame($item->id, data_get($state, 'execution_request.source.id'));
        $this->assertSame($plan->id, data_get($state, 'execution_request.target_plan.id'));
        $this->assertSame($task->id, data_get($state, 'execution_request.target_task.id'));
        $this->assertSame('このValidationをAIと一緒に進めたい', data_get($state, 'execution_request.instruction'));
        $this->assertSame('human_ai', data_get($state, 'execution_request.actor_type'));
        $this->assertSame(30, data_get($state, 'execution_request.available_minutes'));
        $this->assertSame('confirmed', data_get($state, 'execution_request.confirmation.state'));
        $this->assertNull(data_get($state, 'packet'));

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('CONFIRMED EXECUTION REQUEST')
            ->assertSee('Inboxで確認した依頼を引き継いでいます')
            ->assertSee('このValidationをAIと一緒に進めたい')
            ->assertSee('人が確認済み');
    }

    public function test_external_execution_prompt_preserves_confirmed_inbox_intent(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $item = $this->inboxItem($user, '依存関係を壊さずテスト基盤を先に作りたい');

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'execution_request',
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'execution_instruction' => '依存関係を壊さずテスト基盤を先に作りたい',
                'execution_actor_type' => 'ai',
                'execution_available_minutes' => 45,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.prepare', [$plan, $task]), [
                'generation_mode' => 'external',
                'actor_type' => 'ai',
                'available_minutes' => 45,
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $state = session(ExecutionRequestHandoffService::sessionKey($plan, $task));
        $prompt = (string) data_get($state, 'handoff_prompt');

        $this->assertStringContainsString('CONFIRMED EXECUTION REQUEST', $prompt);
        $this->assertStringContainsString('"flow": "execution_request"', $prompt);
        $this->assertStringContainsString('依存関係を壊さずテスト基盤を先に作りたい', $prompt);
        $this->assertStringContainsString('Dependency / protected_scope / confirmed facts', $prompt);
    }

    public function test_inbox_execution_request_without_a_confirmed_task_is_rejected(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $item = $this->inboxItem($user, 'この作業を進めたい');

        $this->actingAs($user)
            ->from(route('inbox.index'))
            ->post(route('inbox.route', $item), [
                'destination' => 'execution_request',
                'plan_id' => $plan->id,
                'execution_instruction' => 'この作業を進めたい',
                'execution_actor_type' => 'human_ai',
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasErrors('task_id');

        $item->refresh();
        $this->assertSame('review', $item->status);
        $this->assertNull($item->processed_at);
        $this->assertNull(data_get($item->metadata, 'routing_confirmed'));
        $this->assertNull(session(ExecutionRequestHandoffService::sessionKey($plan, $task)));
    }

    public function test_companion_execution_candidate_only_hands_off_after_human_apply(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $thread = CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);

        $message = CompanionMessage::query()->create([
            'companion_thread_id' => $thread->id,
            'role' => 'assistant',
            'content' => '実行リクエスト候補があります。',
        ]);

        $candidate = CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $message->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'prepare_execution_request',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => 'ValidationをAIへ渡す',
            'summary' => 'Taskを変更せず実行整理へ渡す候補',
            'payload' => [
                'instruction' => '既存Contractを守ってValidationを進める',
                'actor_type' => 'ai',
                'available_minutes' => 25,
            ],
            'metadata' => ['source' => 'canovia_companion'],
        ]);

        $beforeTask = $task->only([
            'title',
            'description',
            'status',
            'progress_percent',
            'remaining_minutes',
        ]);

        $this->assertNull(session(ExecutionRequestHandoffService::sessionKey($plan, $task)));

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('実行リクエスト候補')
            ->assertSee('確認して実行整理へ進む');

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $candidate->refresh();
        $task->refresh();

        $this->assertSame(CompanionMutationCandidate::STATUS_APPLIED, $candidate->status);
        $this->assertSame('execution_request', $candidate->applied_target_type);
        $this->assertSame($task->id, $candidate->applied_target_id);
        $this->assertSame($beforeTask, $task->only(array_keys($beforeTask)));

        $state = session(ExecutionRequestHandoffService::sessionKey($plan, $task));
        $this->assertSame('companion_candidate', data_get($state, 'execution_request.source.type'));
        $this->assertSame($candidate->id, data_get($state, 'execution_request.source.id'));
        $this->assertSame('既存Contractを守ってValidationを進める', data_get($state, 'execution_request.instruction'));
        $this->assertSame('ai', data_get($state, 'execution_request.actor_type'));
        $this->assertSame(25, data_get($state, 'execution_request.available_minutes'));
        $this->assertNull(data_get($state, 'packet'));

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('Companionで確認した依頼を引き継いでいます')
            ->assertSee('既存Contractを守ってValidationを進める');
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => '全体Contextを保って複数担当へ実行を分配する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'C / Validation',
            'description' => 'Contractを守って横断Validationを行う',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => 10,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function inboxItem(User $user, string $content): InboxItem
    {
        return InboxItem::query()->create([
            'user_id' => $user->id,
            'actor_token' => null,
            'source_type' => 'text',
            'status' => 'review',
            'title' => mb_substr($content, 0, 80),
            'content' => $content,
            'metadata' => [
                'routing_suggestion' => [
                    'destination' => 'execution_request',
                    'reason' => 'これから進めたい依頼です。',
                    'confidence' => 90,
                ],
            ],
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
