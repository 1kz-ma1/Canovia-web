<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionContextService;
use App\Services\CompanionContinuityService;
use App\Services\GoalContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaCompanionContinuityV4115Test extends TestCase
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

    public function test_pending_candidate_is_shown_as_continuity_without_generating_messages(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->grantPremium($user);

        $assistant = $thread->messages()->create([
            'role' => 'assistant',
            'content' => '変更候補があります。',
        ]);
        $candidate = $this->candidate(
            $user,
            $thread,
            $assistant,
            $plan,
            $task,
            ['next_action_note' => '商談序盤で予算も確認する'],
        );

        $beforeMessages = CompanionMessage::count();

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('CONTINUITY', false)
            ->assertSee('確認待ちの変更候補があります')
            ->assertSee($candidate->title)
            ->assertSee('href="#companion-candidate-'.$candidate->id.'"', false);

        $this->assertSame($beforeMessages, CompanionMessage::count());

        $this->actingAs($user)
            ->get(route('companion.index'))
            ->assertOk()
            ->assertSee('確認待ち 1');
    }

    public function test_reviewed_candidate_disappears_from_continuity(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->grantPremium($user);

        $assistant = $thread->messages()->create([
            'role' => 'assistant',
            'content' => '変更候補があります。',
        ]);
        $candidate = $this->candidate(
            $user,
            $thread,
            $assistant,
            $plan,
            $task,
            ['next_action_note' => '商談序盤で予算も確認する'],
        );

        $this->actingAs($user)
            ->post(route('companion.candidates.dismiss', [$thread, $candidate]))
            ->assertRedirect(route('companion.show', $thread));

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertDontSee('確認待ちの変更候補があります');
    }

    public function test_evidence_after_last_assistant_creates_followup_until_next_assistant_reply(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->grantPremium($user);

        $base = now();

        Carbon::setTestNow($base->copy()->subMinutes(10));
        $thread->messages()->create([
            'role' => 'assistant',
            'content' => 'この方針で進めてみましょう。',
        ]);

        Carbon::setTestNow($base->copy()->subMinutes(5));
        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'guided_execution_reflected',
            'external_key' => 'continuity-evidence-'.$task->id,
            'confidence' => 0.7,
            'occurred_at' => now(),
            'metadata' => [
                'intent' => '購入時期を先に確認する',
                'actual_outcome' => '購入時期を確認できた',
                'next_adjustment' => '次回は予算も先に確認する',
            ],
        ]);

        Carbon::setTestNow($base);

        $snapshot = app(CompanionContextService::class)->snapshot($user, $plan, $task);
        $signals = app(CompanionContinuityService::class)->signals($thread, $snapshot);

        $this->assertContains(
            CompanionContinuityService::KIND_NEW_EVIDENCE,
            collect($signals)->pluck('kind')->all(),
        );
        $this->assertStringContainsString(
            '購入時期を確認できた',
            (string) collect($signals)->firstWhere('kind', CompanionContinuityService::KIND_NEW_EVIDENCE)['summary'],
        );

        $thread->messages()->create([
            'role' => 'assistant',
            'content' => '新しいEvidenceを踏まえて整理しました。',
        ]);

        $signalsAfterReply = app(CompanionContinuityService::class)->signals($thread, $snapshot);

        $this->assertNotContains(
            CompanionContinuityService::KIND_NEW_EVIDENCE,
            collect($signalsAfterReply)->pluck('kind')->all(),
        );

        Carbon::setTestNow();
    }

    public function test_known_unknown_disappears_when_same_fact_key_is_confirmed(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->grantPremium($user);

        $goals = app(GoalContextService::class);
        $goal = $goals->ensureForPlan($plan, $user->id);

        $goals->recordFact(
            $goal,
            type: 'unknown',
            label: '現在の商談成約率',
            value: ['text' => '未確認'],
            source: 'system',
            state: 'unknown',
            key: 'sales_conversion_rate',
            importance: 5,
        );

        $snapshot = app(CompanionContextService::class)->snapshot($user, $plan, $task);
        $signals = app(CompanionContinuityService::class)->signals($thread, $snapshot);

        $this->assertSame(
            '現在の商談成約率',
            collect($signals)->firstWhere('kind', CompanionContinuityService::KIND_KNOWN_UNKNOWN)['summary'],
        );

        $goals->recordFact(
            $goal,
            type: 'current_state',
            label: '現在の商談成約率',
            value: ['text' => '30%'],
            source: 'user_answer',
            state: 'confirmed',
            key: 'sales_conversion_rate',
            importance: 5,
        );

        $snapshotAfterConfirm = app(CompanionContextService::class)->snapshot($user, $plan->fresh(), $task->fresh());
        $signalsAfterConfirm = app(CompanionContinuityService::class)->signals($thread, $snapshotAfterConfirm);

        $this->assertNotContains(
            CompanionContinuityService::KIND_KNOWN_UNKNOWN,
            collect($signalsAfterConfirm)->pluck('kind')->all(),
        );
    }

    public function test_missing_next_action_is_continuity_signal_until_task_is_updated(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->grantPremium($user);

        $task->update(['next_action_note' => null]);

        $snapshot = app(CompanionContextService::class)->snapshot($user, $plan, $task->fresh());
        $signals = app(CompanionContinuityService::class)->signals($thread, $snapshot);

        $this->assertContains(
            CompanionContinuityService::KIND_NEXT_ACTION,
            collect($signals)->pluck('kind')->all(),
        );

        $task->update(['next_action_note' => '商談の冒頭で購入時期を聞く']);

        $snapshotAfterUpdate = app(CompanionContextService::class)->snapshot($user, $plan, $task->fresh());
        $signalsAfterUpdate = app(CompanionContinuityService::class)->signals($thread, $snapshotAfterUpdate);

        $this->assertNotContains(
            CompanionContinuityService::KIND_NEXT_ACTION,
            collect($signalsAfterUpdate)->pluck('kind')->all(),
        );
    }

    public function test_native_prompt_receives_continuity_and_duplicate_pending_candidate_is_not_created(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->grantPremium($user);

        $assistant = $thread->messages()->create([
            'role' => 'assistant',
            'content' => '次のAction候補があります。',
        ]);
        $payload = ['next_action_note' => '商談序盤で予算を確認する'];
        $existing = $this->candidate($user, $thread, $assistant, $plan, $task, $payload);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'reply' => '既存の確認待ち候補があるため、重ねて変更は作りません。',
                    'candidates' => [[
                        'type' => 'update_task',
                        'title' => '同じ次Action候補',
                        'summary' => '既存候補と同じ変更です。',
                        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('companion.messages.store', $thread), [
                'request_id' => (string) Str::uuid(),
                'source_path' => '/plans/'.$plan->id,
                'content' => '続きから整理して',
            ])
            ->assertRedirect(route('companion.show', $thread))
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request) use ($existing) {
            $input = (string) ($request->data()['input'] ?? '');

            return str_contains($input, 'pending_candidate')
                && str_contains($input, $existing->title)
                && str_contains($input, '同じ変更候補を重複生成しない');
        });

        $this->assertDatabaseCount('companion_mutation_candidates', 1);
        $this->assertSame($existing->id, CompanionMutationCandidate::firstOrFail()->id);
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

        app(GoalContextService::class)->ensureForPlan($plan, $user->id);

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
        CompanionMessage $assistant,
        Plan $plan,
        Task $task,
        array $payload,
    ): CompanionMutationCandidate {
        return CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => '次のActionを調整',
            'summary' => '次の商談で確認する項目を明確にする',
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
