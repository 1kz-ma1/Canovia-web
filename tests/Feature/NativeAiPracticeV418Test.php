<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NativeAiPracticeV418Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
            'native_ai.study_practice.capacity.standard.generation_max_output_tokens' => 8000,
            'native_ai.study_practice.capacity.standard.assessment_max_output_tokens' => 6000,
        ]);
    }

    public function test_free_user_keeps_manual_handoff_and_cannot_call_native_prepare(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('演習準備プロンプトをコピー')
            ->assertDontSee(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), false);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('native_ai_runs', 0);
    }

    public function test_premium_user_can_generate_questions_natively_without_copy_paste(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->grantPremium($user);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody($this->questionEnvelope($plan, $task), 'resp_generation', 320, 640),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('Canovia内で演習を準備')
            ->assertSee('Canoviaで演習を始める')
            ->assertSee('外部AIを使う / Native AIが使えない場合');

        $prepareRequestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => $prepareRequestId,
            ])
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $session = StudyPracticeSession::where('prepare_request_id', $prepareRequestId)->firstOrFail();
        $run = NativeAiRun::firstOrFail();

        $this->assertSame('hybrid_ai', $session->question_provider);
        $this->assertSame('direct', $session->question_provider_mode);
        $this->assertSame(StudyPracticeSession::STATUS_READY, $session->status);
        $this->assertSame('q1', data_get($session->questions_snapshot, '0.id'));

        $this->assertSame('succeeded', $run->status);
        $this->assertSame('study_practice_generation', $run->purpose);
        $this->assertSame('standard', $run->capacity_tier);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame($session->id, $run->study_practice_session_id);
        $this->assertSame(320, $run->input_tokens);
        $this->assertSame(640, $run->output_tokens);
        $this->assertSame(960, $run->total_tokens);

        Http::assertSentCount(1);
    }

    public function test_ap_native_generation_rejects_mixed_answers_before_a_question_snapshot_is_committed(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $plan->update(['title' => 'AP 科目A']);
        $this->grantPremium($user);
        $payload = $this->questionEnvelope($plan, $task);
        $payload['questions'][0]['response_fields'][] = [
            'id' => 'salt', 'type' => 'single_choice', 'label' => '別問題の回答',
            'required' => true, 'choices' => [
                ['id' => 'A', 'label' => 'ソルトA'], ['id' => 'B', 'label' => 'ソルトB'],
            ],
        ];
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responseBody($payload, 'resp_mixed'), 200)]);
        $this->actingAs($user)->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
            'prepare_request_id' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHas('native_ai_fallback', true);
        $this->assertSame('failed', NativeAiRun::sole()->status);
        $this->assertFalse(StudyPracticeSession::query()->whereNotNull('questions_snapshot')->exists());
        $this->assertDatabaseCount('study_practice_attempts', 0);
        Http::assertSentCount(1);
    }

    public function test_native_generation_is_idempotent_for_same_prepare_request_id(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->grantPremium($user);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody($this->questionEnvelope($plan, $task), 'resp_once'),
                200,
            ),
        ]);

        $prepareRequestId = (string) Str::uuid();
        $payload = ['prepare_request_id' => $prepareRequestId];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), $payload)
            ->assertRedirect();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('native_ai_runs', 1);
        $this->assertDatabaseCount('study_practice_sessions', 1);
    }

    public function test_premium_native_flow_assesses_answers_and_persists_usage_history(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push($this->responseBody($this->questionEnvelope($plan, $task), 'resp_generation'), 200)
                ->push($this->responseBody($this->assessmentEnvelope($plan, $task), 'resp_assessment', 280, 420), 200),
        ]);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => [
                    'q1' => ['answer' => 'B', 'reasoning' => 'MXはメール配送先を示すため。'],
                ],
            ])
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $session = StudyPracticeSession::latest('id')->firstOrFail();
        $attempt = StudyPracticeAttempt::latest('id')->firstOrFail();
        $runs = NativeAiRun::orderBy('id')->get();

        $this->assertSame(StudyPracticeSession::STATUS_ASSESSED, $session->status);
        $this->assertSame('native_ai', $session->assessment_provider);
        $this->assertSame('direct', $session->assessment_provider_mode);
        $this->assertSame(90, $attempt->score_percent);
        $this->assertSame($session->id, $attempt->study_practice_session_id);

        $this->assertCount(2, $runs);
        $this->assertSame(['study_practice_generation', 'study_practice_assessment'], $runs->pluck('purpose')->all());
        $this->assertTrue($runs->every(fn (NativeAiRun $run) => $run->status === 'succeeded'));
        $this->assertSame($session->id, $runs->last()->study_practice_session_id);

        Http::assertSentCount(2);
    }

    public function test_native_calculation_question_adds_calculation_work_field_when_provider_returns_choice_only(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->grantPremium($user);

        $payload = $this->questionEnvelope($plan, $task);
        $payload['title'] = '性能計算';
        $payload['questions'][0] = [
            'id' => 'q1',
            'prompt' => '200MIPSのCPUで1件あたり200万命令を実行する。1秒間に処理できる件数として最も近いものを選んでください。',
            'work_input' => 'calculation',
            'response_fields' => [[
                'id' => 'answer',
                'type' => 'single_choice',
                'label' => '回答',
                'required' => true,
                'placeholder' => '',
                'choices' => [
                    ['id' => 'A', 'label' => '10件'],
                    ['id' => 'B', 'label' => '50件'],
                    ['id' => 'C', 'label' => '100件'],
                    ['id' => 'D', 'label' => '200件'],
                ],
            ]],
        ];

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody($payload, 'resp_calculation'),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = StudyPracticeSession::latest('id')->firstOrFail();
        $question = data_get($session->questions_snapshot, '0');

        $this->assertSame('calculation', data_get($question, 'work_input'));
        $this->assertSame(
            ['answer', 'calculation_work'],
            collect(data_get($question, 'response_fields', []))->pluck('id')->all(),
        );
        $this->assertSame('textarea', data_get($question, 'response_fields.1.type'));
        $this->assertSame('計算過程', data_get($question, 'response_fields.1.label'));
        $this->assertFalse((bool) data_get($question, 'response_fields.1.required'));
    }

    public function test_external_ai_import_uses_same_work_input_contract(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $payload = [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => '条件判断',
            'questions' => [[
                'id' => 'q1',
                'prompt' => '複数条件を比較して最も適切な選択肢を選んでください。',
                'work_input' => 'reasoning',
                'response_fields' => [[
                    'id' => 'answer',
                    'type' => 'single_choice',
                    'label' => '回答',
                    'required' => true,
                    'choices' => [
                        ['id' => 'A', 'label' => 'A'],
                        ['id' => 'B', 'label' => 'B'],
                    ],
                ]],
            ]],
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
                'questions_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $session = StudyPracticeSession::latest('id')->firstOrFail();
        $question = data_get($session->questions_snapshot, '0');

        $this->assertSame('reasoning', data_get($question, 'work_input'));
        $this->assertSame(
            ['answer', 'reasoning'],
            collect(data_get($question, 'response_fields', []))->pluck('id')->all(),
        );
        $this->assertSame('考え方・判断理由', data_get($question, 'response_fields.1.label'));
    }

    public function test_native_generation_failure_falls_back_to_manual_external_ai(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['code' => 'rate_limit_exceeded'],
            ], 429),
        ]);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertSessionHas('native_ai_fallback', true);

        $run = NativeAiRun::firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('openai_', (string) $run->error_code);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('演習準備プロンプトをコピー')
            ->assertSee('外部AIを使う / Native AIが使えない場合');
    }

    public function test_native_assessment_failure_falls_back_to_external_evaluation_prompt(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push($this->responseBody($this->questionEnvelope($plan, $task), 'resp_generation'), 200)
                ->push(['error' => ['code' => 'server_error']], 500),
        ]);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => [
                    'q1' => ['answer' => 'B', 'reasoning' => 'MXを選択。'],
                ],
            ])
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertSessionHas('native_ai_fallback', true);

        $session = StudyPracticeSession::latest('id')->firstOrFail();
        $runs = NativeAiRun::orderBy('id')->get();

        $this->assertSame(StudyPracticeSession::STATUS_ANSWERED, $session->status);
        $this->assertSame('external_ai', $session->assessment_provider);
        $this->assertSame('handoff', $session->assessment_provider_mode);
        $this->assertNotEmpty(data_get($session->assessment_payload, 'evaluation_prompt'));
        $this->assertSame('failed', $runs->last()->status);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    private function grantPremium(User $user): UserProductGrant
    {
        return UserProductGrant::create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    private function responseBody(array $payload, string $id, int $inputTokens = 100, int $outputTokens = 200): array
    {
        return [
            'id' => $id,
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];
    }

    private function questionEnvelope(Plan $plan, Task $task): array
    {
        return [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => 'DNS Native AI演習',
            'questions' => [[
                'id' => 'q1',
                'prompt' => 'メールサーバの配送先を示すDNSレコードを選んでください。',
                'work_input' => 'reasoning',
                'response_fields' => [
                    [
                        'id' => 'answer',
                        'type' => 'single_choice',
                        'label' => '回答',
                        'required' => true,
                        'placeholder' => '',
                        'choices' => [
                            ['id' => 'A', 'label' => 'Aレコード'],
                            ['id' => 'B', 'label' => 'MXレコード'],
                            ['id' => 'C', 'label' => 'CNAMEレコード'],
                            ['id' => 'D', 'label' => 'NSレコード'],
                        ],
                    ],
                    [
                        'id' => 'reasoning',
                        'type' => 'textarea',
                        'label' => '考え方',
                        'required' => false,
                        'placeholder' => '',
                        'choices' => [],
                    ],
                ],
            ]],
        ];
    }

    private function assessmentEnvelope(Plan $plan, Task $task): array
    {
        return [
            'schema_version' => '1.0',
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 90,
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'correct',
                'feedback' => 'MXレコードを正しく選べています。',
                'reasoning_feedback' => '役割も正しく説明できています。',
                'error_type' => 'none',
                'weakness_topics' => [],
                'misconceptions' => [],
            ]],
            'strengths' => ['DNSレコードの役割'],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 80,
            'evidence_summary' => 'DNSレコードの基本を説明できている。',
            'next_action' => 'DNSレコードの横断問題へ進む',
            'next_step' => [
                'kind' => 'continue_task',
                'label' => 'DNSレコードの横断問題へ進む',
                'reason' => '基本的な使い分けを理解できているため。',
                'focus_topics' => [],
                'question_count' => 0,
            ],
        ];
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP対策',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク弱点補強',
            'description' => '科目Aのネットワーク分野を補強する',
            'estimated_minutes' => 90,
            'remaining_minutes' => 90,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
