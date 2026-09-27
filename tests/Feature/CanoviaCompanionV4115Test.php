<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\GoalContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaCompanionV4115Test extends TestCase
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

    public function test_free_user_can_see_companion_value_but_cannot_create_native_thread(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('companion.index'))
            ->assertOk()
            ->assertSee('CANOVIA COMPANION')
            ->assertSee('Premium Core')
            ->assertSee('FreeでもGoal Discovery')
            ->assertDontSee(route('companion.threads.store'), false);

        $this->actingAs($user)
            ->post(route('companion.threads.store'), ['scope' => 'global'])
            ->assertForbidden();

        $this->assertDatabaseCount('companion_threads', 0);
        Http::assertNothingSent();
    }

    public function test_premium_user_can_create_task_scoped_thread_and_context_uses_existing_canovia_data(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $response = $this->actingAs($user)
            ->post(route('companion.threads.store'), [
                'scope' => 'task:'.$task->id,
            ]);

        $thread = CompanionThread::firstOrFail();

        $response->assertRedirect(route('companion.show', $thread));
        $this->assertSame($user->id, $thread->user_id);
        $this->assertSame($plan->id, $thread->plan_id);
        $this->assertSame($task->id, $thread->task_id);
        $this->assertSame('task', data_get($thread->context_scope, 'scope'));

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee($plan->title)
            ->assertSee($task->title)
            ->assertSee('TASK')
            ->assertSee('EVIDENCE')
            ->assertSee('変更が必要でもAIは直接反映せず');
    }

    public function test_native_reply_creates_unapplied_candidate_and_ai_ids_are_discarded(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '今回の結果を見ると、次の行動を少し具体化する候補があります。',
                    'candidates' => [[
                        'type' => 'update_task',
                        'title' => '次の商談で購入時期を先に確認する',
                        'summary' => '今回のEvidenceを踏まえた変更候補です。',
                        'payload_json' => json_encode([
                            'id' => 99999,
                            'user_id' => 99999,
                            'plan_id' => 99999,
                            'task_id' => 99999,
                            'next_action_note' => '商談序盤で購入希望時期を確認する',
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]),
                200,
            ),
        ]);

        $originalNote = $task->next_action_note;
        $requestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), [
                'request_id' => $requestId,
                'source_path' => '/plans/'.$plan->id,
                'content' => 'さっきの商談結果から次どうするのがよさそう？',
            ])
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasNoErrors();

        $messages = CompanionMessage::orderBy('id')->get();
        $candidate = CompanionMutationCandidate::firstOrFail();
        $run = NativeAiRun::firstOrFail();

        $this->assertCount(2, $messages);
        $this->assertSame(['user', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->status);
        $this->assertSame($plan->id, $candidate->plan_id);
        $this->assertSame($task->id, $candidate->task_id);
        $this->assertArrayNotHasKey('id', $candidate->payload);
        $this->assertArrayNotHasKey('user_id', $candidate->payload);
        $this->assertArrayNotHasKey('plan_id', $candidate->payload);
        $this->assertArrayNotHasKey('task_id', $candidate->payload);
        $this->assertSame('商談序盤で購入希望時期を確認する', data_get($candidate->payload, 'next_action_note'));

        $this->assertSame($originalNote, $task->fresh()->next_action_note);
        $this->assertSame(FeatureKey::CanoviaCompanion->value, $run->feature_key);
        $this->assertSame('canovia_companion_reply', $run->purpose);

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('MUTATION CANDIDATES', false)
            ->assertSee('未反映')
            ->assertSee('Step 1では候補を保存するだけ');

        $this->assertFalse(Route::has('companion.candidates.apply'));
        Http::assertSentCount(1);
    }

    public function test_companion_prompt_contains_selected_context_recent_evidence_and_current_screen(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '現在地と直近Evidenceを前提に整理します。',
                    'candidates' => [],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), [
                'request_id' => (string) Str::uuid(),
                'source_path' => '/plans/'.$plan->id.'/guided-execution',
                'content' => '今の状況をどう見る？',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) use ($plan, $task) {
            $payload = $request->data();
            $input = (string) ($payload['input'] ?? '');

            return str_contains($input, $plan->title)
                && str_contains($input, $task->title)
                && str_contains($input, '月10台販売する')
                && str_contains($input, '見積提示まで進んだ')
                && str_contains($input, '/plans/'.$plan->id.'/guided-execution')
                && str_contains($input, 'DB変更は一切できない');
        });
    }

    public function test_same_request_id_is_idempotent_and_does_not_call_native_ai_twice(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '同じ送信は一度だけ処理します。',
                    'candidates' => [[
                        'type' => 'update_task',
                        'title' => '次のAction候補',
                        'summary' => '未反映候補',
                        'payload_json' => '{"next_action_note":"購入時期を確認する"}',
                    ]],
                ]),
                200,
            ),
        ]);

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'content' => '次どうする？',
        ];

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), $payload)
            ->assertRedirect();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('companion_messages', 2);
        $this->assertDatabaseCount('companion_mutation_candidates', 1);
        $this->assertDatabaseCount('native_ai_runs', 1);
    }

    public function test_candidate_requiring_task_is_dropped_when_context_is_global(): void
    {
        $user = User::factory()->create();
        $this->grantPremium($user);
        $thread = CompanionThread::create([
            'user_id' => $user->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'global'],
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '全体相談として整理します。',
                    'candidates' => [
                        [
                            'type' => 'update_task',
                            'title' => '対象不明のTask更新',
                            'summary' => 'Task contextがないので保存されない',
                            'payload_json' => '{"next_action_note":"変更"}',
                        ],
                        [
                            'type' => 'create_future_memo',
                            'title' => '将来検討する',
                            'summary' => '全体Contextでも保存できる候補',
                            'payload_json' => '{"content":"将来やりたいこと"}',
                        ],
                    ],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), [
                'request_id' => (string) Str::uuid(),
                'content' => '思いつきを整理して',
            ])
            ->assertRedirect();

        $candidates = CompanionMutationCandidate::all();

        $this->assertCount(1, $candidates);
        $this->assertSame('create_future_memo', $candidates->first()->type);
        $this->assertNull($candidates->first()->plan_id);
        $this->assertNull($candidates->first()->task_id);
    }

    public function test_failed_native_call_keeps_one_user_message_and_same_request_can_retry(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push(['error' => ['code' => 'temporary']], 500)
                ->push($this->responseBody([
                    'reply' => '再試行で応答できました。',
                    'candidates' => [],
                ]), 200),
        ]);

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'content' => '今の優先順位を整理して',
        ];

        $this->actingAs($user)
            ->from(route('companion.show', $thread))
            ->post(route('companion.messages.store', $thread), $payload)
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasErrors('content')
            ->assertSessionHasInput('request_id', $requestId);

        $this->assertDatabaseCount('companion_messages', 1);
        $this->assertDatabaseCount('native_ai_runs', 1);

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), $payload)
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('companion_messages', 2);
        $this->assertDatabaseCount('native_ai_runs', 2);
        Http::assertSentCount(2);
    }

    public function test_dismiss_is_human_review_only_and_does_not_mutate_target(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);
        $thread = $this->thread($user, $plan, $task);
        $assistant = $thread->messages()->create([
            'role' => 'assistant',
            'content' => '候補があります。',
        ]);
        $candidate = CompanionMutationCandidate::create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => '変更候補',
            'summary' => 'まだ反映しない',
            'payload' => ['next_action_note' => '変更後候補'],
        ]);

        $before = $task->next_action_note;

        $this->actingAs($user)
            ->post(route('companion.candidates.dismiss', [$thread, $candidate]))
            ->assertRedirect(route('companion.show', $thread));

        $this->assertSame(CompanionMutationCandidate::STATUS_DISMISSED, $candidate->fresh()->status);
        $this->assertNotNull($candidate->fresh()->reviewed_at);
        $this->assertSame($before, $task->fresh()->next_action_note);
    }

    public function test_other_user_cannot_read_thread_or_dismiss_candidate(): void
    {
        [$owner, $plan, $task] = $this->scenario();
        $this->grantPremium($owner);
        $thread = $this->thread($owner, $plan, $task);
        $assistant = $thread->messages()->create(['role' => 'assistant', 'content' => 'owner only']);
        $candidate = CompanionMutationCandidate::create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => 'owner candidate',
            'payload' => [],
        ]);

        $other = User::factory()->create();
        $this->grantPremium($other);

        $this->actingAs($other)
            ->get(route('companion.show', $thread))
            ->assertNotFound();

        $this->actingAs($other)
            ->post(route('companion.candidates.dismiss', [$thread, $candidate]))
            ->assertNotFound();

        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
    }

    public function test_companion_feature_is_separate_premium_capability(): void
    {
        $free = User::factory()->create();
        $premium = User::factory()->create();
        $this->grantPremium($premium);

        $access = app(\App\Services\FeatureAccessService::class);

        $this->assertFalse($access->canUse($free, FeatureKey::CanoviaCompanion));
        $this->assertTrue($access->canUse($premium, FeatureKey::CanoviaCompanion));
        $this->assertTrue($access->canUse($premium, FeatureKey::AutomaticAiExecution));
        $this->assertContains(
            FeatureKey::CanoviaCompanion->value,
            config('economy.products.'.ProductKey::PremiumCore->value.'.feature_keys'),
        );
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
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
        $task = Task::create([
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

        $goals = app(GoalContextService::class);
        $context = $goals->ensureForPlan($plan, $user->id);
        $context->update(['current_state_summary' => '今月は4台販売']);
        $goals->recordFact(
            $context,
            type: 'current_state',
            label: '現在地',
            value: ['text' => '今月は4台販売'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'current_state_summary',
        );
        $goals->recordFact(
            $context,
            type: 'signal',
            label: '達成基準',
            value: ['text' => '月10台販売する'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'success_signal',
        );

        TaskEvidence::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'guided_execution_reflected',
            'external_key' => 'companion-test-evidence-'.$task->id,
            'confidence' => 0.7,
            'occurred_at' => now(),
            'metadata' => [
                'intent' => '購入時期を見積前に確認する',
                'actual_outcome' => '見積提示まで進んだが購入は来月だった',
                'next_adjustment' => '商談序盤で購入時期を確認する',
            ],
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
            'context_scope' => ['scope' => 'task'],
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
