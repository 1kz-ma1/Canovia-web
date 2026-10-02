<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanionDeepIntegrationV515Test extends TestCase
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

    public function test_palette_entry_returns_thread_fragment_while_normal_entry_keeps_redirect_fallback(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $palette = $this->actingAs($user)
            ->withHeader('X-Canovia-Companion-Surface', 'palette')
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'source_path' => '/plans/'.$plan->id,
                'source_route' => 'plans.show',
            ]);

        $thread = CompanionThread::firstOrFail();

        $palette
            ->assertOk()
            ->assertJsonPath('thread_id', $thread->id)
            ->assertJsonPath('handoff_url', null)
            ->assertSee('data-companion-palette-thread', false)
            ->assertSee('data-companion-palette-compose', false)
            ->assertSee($task->title);

        $normal = $this->actingAs($user)
            ->post(route('companion.entry'), [
                'entry_type' => 'task',
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'source_path' => '/plans/'.$plan->id,
                'source_route' => 'plans.show',
            ]);

        $normal->assertRedirect(route('companion.show', $thread));
        $this->assertDatabaseCount('companion_threads', 1);
    }

    public function test_palette_message_send_returns_updated_fragment_and_keeps_candidate_unapplied(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '次のActionを具体化する候補があります。',
                    'candidates' => [[
                        'type' => 'update_task',
                        'title' => '次のActionを具体化',
                        'summary' => '人が確認するまで未反映です。',
                        'payload_json' => json_encode([
                            'next_action_note' => '顧客へ次回日程を確認する',
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]),
                200,
            ),
        ]);

        $before = $task->next_action_note;

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Companion-Surface', 'palette')
            ->post(route('companion.messages.store', $thread), [
                'request_id' => (string) Str::uuid(),
                'source_path' => '/plans/'.$plan->id,
                'content' => '次の一歩を整理して',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('thread_id', $thread->id)
            ->assertSee('次のActionを具体化する候補があります。')
            ->assertSee('data-companion-palette-candidate', false)
            ->assertSee('反映する');

        $candidate = CompanionMutationCandidate::firstOrFail();
        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->status);
        $this->assertSame($before, $task->fresh()->next_action_note);
        Http::assertSentCount(1);
    }

    public function test_palette_candidate_apply_and_dismiss_reuse_existing_human_confirm_rules(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        $assistant = CompanionMessage::create([
            'companion_thread_id' => $thread->id,
            'role' => 'assistant',
            'content' => '変更候補があります。',
        ]);

        $applyCandidate = $this->candidate(
            $user,
            $plan,
            $task,
            $thread,
            $assistant,
            ['next_action_note' => 'Paletteから反映したAction'],
        );

        $apply = $this->actingAs($user)
            ->withHeader('X-Canovia-Companion-Surface', 'palette')
            ->post(route('companion.candidates.apply', [$thread, $applyCandidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ]);

        $apply
            ->assertOk()
            ->assertJsonPath('thread_id', $thread->id)
            ->assertSee('反映済み');

        $this->assertSame(
            CompanionMutationCandidate::STATUS_APPLIED,
            $applyCandidate->fresh()->status,
        );
        $this->assertSame(
            'Paletteから反映したAction',
            $task->fresh()->next_action_note,
        );

        $dismissCandidate = $this->candidate(
            $user,
            $plan,
            $task,
            $thread,
            $assistant,
            ['next_action_note' => '見送る候補'],
        );

        $dismiss = $this->actingAs($user)
            ->withHeader('X-Canovia-Companion-Surface', 'palette')
            ->post(route('companion.candidates.dismiss', [$thread, $dismissCandidate]));

        $dismiss
            ->assertOk()
            ->assertJsonPath('thread_id', $thread->id)
            ->assertSee('見送り');

        $this->assertSame(
            CompanionMutationCandidate::STATUS_DISMISSED,
            $dismissCandidate->fresh()->status,
        );
        $this->assertSame(
            'Paletteから反映したAction',
            $task->fresh()->next_action_note,
        );
    }

    public function test_execution_candidate_returns_handoff_url_without_forced_redirect(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        $assistant = CompanionMessage::create([
            'companion_thread_id' => $thread->id,
            'role' => 'assistant',
            'content' => '実行整理へ渡せます。',
        ]);

        $candidate = CompanionMutationCandidate::create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'prepare_execution_request',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => '実行リクエスト',
            'summary' => 'Execution Orchestrationへ渡す候補',
            'payload' => [
                'instruction' => 'このTaskの次の実装手順を整理する',
                'actor_type' => 'human_ai',
                'available_minutes' => 60,
            ],
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Companion-Surface', 'palette')
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'handoff_url',
                route('plans.tasks.execution_orchestration.show', [$plan, $task]),
            );

        $this->assertSame(
            CompanionMutationCandidate::STATUS_APPLIED,
            $candidate->fresh()->status,
        );
    }

    public function test_palette_client_contract_uses_async_fetch_without_replacing_primary_navigation(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $palette = file_get_contents(resource_path('views/layouts/partials/companion-palette.blade.php'));
        $thread = file_get_contents(resource_path('views/companion/partials/palette-thread.blade.php'));

        $this->assertStringContainsString('X-Canovia-Companion-Surface', $app);
        $this->assertStringContainsString('fetch(form.action', $app);
        $this->assertStringContainsString('data-companion-palette-session', $palette);
        $this->assertStringContainsString('data-companion-palette-async-form', $palette);
        $this->assertStringContainsString('data-companion-palette-messages', $thread);
        $this->assertStringContainsString('data-companion-palette-candidate', $thread);
        $this->assertStringContainsString('data-companion-palette-shortcuts', $thread);
        $this->assertStringNotContainsString('name="surface"', $palette);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V51.5 Companion Plan',
            'description' => 'Palette integration test',
            'category' => '開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => 'CompanionをPaletteへ統合する',
            'description' => '現在画面を離れず相談する',
            'next_action_note' => '既存Companionを再利用する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 120,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function thread(User $user, Plan $plan, Task $task): CompanionThread
    {
        return CompanionThread::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => [
                'scope' => 'task',
                'entry_type' => 'task',
                'entry_key' => 'task:'.$task->id,
                'source_path' => '/plans/'.$plan->id,
                'source_route' => 'plans.show',
            ],
        ]);
    }

    private function candidate(
        User $user,
        Plan $plan,
        Task $task,
        CompanionThread $thread,
        CompanionMessage $message,
        array $payload,
    ): CompanionMutationCandidate {
        return CompanionMutationCandidate::create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $message->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => 'Task変更候補',
            'summary' => '人の確認後に反映する',
            'payload' => $payload,
        ]);
    }

    private function grantPremium(User $user): void
    {
        UserProductGrant::create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
    }

    private function responseBody(array $data): array
    {
        return [
            'id' => 'resp_'.Str::random(10),
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ];
    }
}
