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
use App\Services\NativeAiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class StudyPracticeNativeCommitFenceG3Test extends TestCase
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
            'native_ai.providers.openai.model' => 'test-model',
        ]);
    }

    public function test_authorized_generation_and_assessment_keep_prior_learning_behavior(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task, true);

        $this->generate($user, $plan, $task)
            ->assertSessionHasNoErrors();
        $this->assess($user, $plan, $task)->assertSessionHasNoErrors();

        $session = StudyPracticeSession::query()->firstOrFail();
        $attempt = StudyPracticeAttempt::query()->firstOrFail();
        $this->assertSame(StudyPracticeSession::STATUS_ASSESSED, $session->status);
        $this->assertSame('native_ai', $session->assessment_provider);
        $this->assertSame($session->id, $attempt->study_practice_session_id);
        $this->assertSame(90, $attempt->score_percent);
        $this->assertSame('succeeded', NativeAiRun::query()->latest('id')->firstOrFail()->status);
        Http::assertSentCount(2);
    }

    public function test_study_workspace_domain_override_is_not_rejected_as_non_study_category(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $plan->update([
            'category' => 'その他',
            'workspace_domain_override' => 'study',
        ]);
        $this->fakeNative($plan, $task);

        $this->generate($user, $plan, $task)->assertSessionHasNoErrors();

        $session = StudyPracticeSession::query()->firstOrFail();
        $this->assertSame(StudyPracticeSession::STATUS_READY, $session->status);
        $this->assertSame('q1', data_get($session->questions_snapshot, '0.id'));
    }

    public function test_other_study_categories_are_accepted_by_current_domain_policy(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $plan->update(['category' => '試験勉強']);
        $this->fakeNative($plan, $task);

        $this->generate($user, $plan, $task)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_practice_sessions', 1);
    }

    public function test_task_edit_after_generation_provider_withholds_all_generated_session_and_candidate_rows(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $this->afterGateway(fn () => Task::query()->whereKey($task->id)->update([
            'description' => 'ユーザーによる修正版',
        ]));

        $this->generate($user, $plan, $task);

        $this->assertDatabaseCount('study_practice_sessions', 0);
        $this->assertDatabaseCount('practice_question_demands', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertSame('ユーザーによる修正版', $task->fresh()->description);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
    }

    public function test_plan_transfer_after_generation_provider_withholds_native_questions(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $other = User::factory()->create();
        $this->fakeNative($plan, $task);
        $this->afterGateway(fn () => Plan::query()->whereKey($plan->id)->update([
            'user_id' => $other->id,
        ]));

        $this->generate($user, $plan, $task);

        $this->assertDatabaseCount('study_practice_sessions', 0);
        $this->assertSame($other->id, $plan->fresh()->user_id);
    }

    public function test_entitlement_expiry_after_generation_provider_withholds_native_questions(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $this->afterGateway(fn () => UserProductGrant::query()
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]));

        $this->generate($user, $plan, $task);

        $this->assertDatabaseCount('study_practice_sessions', 0);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
    }

    public function test_answer_revision_after_assessment_provider_prevents_old_score_and_evidence(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task, true);
        $this->generate($user, $plan, $task);

        $this->afterGateway(fn () => StudyPracticeSession::query()->firstOrFail()->update([
            'draft_answers' => ['q1' => ['answer' => 'C']],
        ]));

        $this->assess($user, $plan, $task);

        $session = StudyPracticeSession::query()->firstOrFail();
        $this->assertNotSame(StudyPracticeSession::STATUS_ASSESSED, $session->status);
        $this->assertNull($session->assessment_payload);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertSame('succeeded', NativeAiRun::query()->latest('id')->firstOrFail()->status);
    }

    public function test_session_abandoned_after_assessment_provider_is_not_resurrected_by_ai(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task, true);
        $this->generate($user, $plan, $task);

        $this->afterGateway(fn () => StudyPracticeSession::query()->firstOrFail()->update([
            'status' => StudyPracticeSession::STATUS_ABANDONED,
        ]));
        $this->assess($user, $plan, $task);

        $session = StudyPracticeSession::query()->firstOrFail();
        $this->assertSame(StudyPracticeSession::STATUS_ABANDONED, $session->status);
        $this->assertNull($session->assessment_payload);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_entitlement_expiry_after_assessment_provider_does_not_score_answers(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task, true);
        $this->generate($user, $plan, $task);

        $this->afterGateway(fn () => UserProductGrant::query()
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]));

        $this->assess($user, $plan, $task);

        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertNull(StudyPracticeSession::query()->firstOrFail()->assessment_payload);
        $this->assertSame('succeeded', NativeAiRun::query()->latest('id')->firstOrFail()->status);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP対策',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク',
            'description' => 'DNS',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 10,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function generate(User $user, Plan $plan, Task $task)
    {
        return $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.native.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect();
    }

    private function assess(User $user, Plan $plan, Task $task)
    {
        return $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => [
                    'q1' => ['answer' => 'B', 'reasoning' => 'MXはメール配送先を指す'],
                ],
            ])
            ->assertRedirect();
    }

    private function fakeNative(
        Plan $plan,
        Task $task,
        bool $twoResponses = false,
        bool $assessmentOnly = false,
    ): void {
        $questionEnvelope = [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => 'DNS演習',
            'questions' => [[
                'id' => 'q1',
                'prompt' => 'メール配送先のDNSレコードは？',
                'work_input' => 'reasoning',
                'response_fields' => [
                    [
                        'id' => 'answer',
                        'type' => 'single_choice',
                        'label' => '回答',
                        'required' => true,
                        'placeholder' => '',
                        'choices' => [
                            ['id' => 'A', 'label' => 'A'],
                            ['id' => 'B', 'label' => 'MX'],
                        ],
                    ],
                    [
                        'id' => 'reasoning',
                        'type' => 'textarea',
                        'label' => '理由',
                        'required' => false,
                        'placeholder' => '',
                        'choices' => [],
                    ],
                ],
            ]],
        ];
        $assessmentEnvelope = [
            'schema_version' => '1.0',
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 90,
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'correct',
                'feedback' => 'MXで正解',
                'reasoning_feedback' => '説明も適切',
                'error_type' => 'none',
                'weakness_topics' => [],
                'misconceptions' => [],
            ]],
            'strengths' => ['DNS'],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 80,
            'evidence_summary' => 'DNSを理解した',
            'next_action' => '次の問題へ',
            'next_step' => [
                'kind' => 'continue_task',
                'label' => '次の問題へ',
                'reason' => '確認済み',
                'focus_topics' => [],
                'question_count' => 0,
            ],
        ];
        $encode = fn ($data) => [
            'id' => 'resp-g3',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 160, 'total_tokens' => 260],
        ];

        if ($twoResponses) {
            Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
                ->push($encode($questionEnvelope), 200)
                ->push($encode($assessmentEnvelope), 200)]);

            return;
        }

        Http::fake(['https://api.openai.com/v1/responses' => Http::response(
            $encode($assessmentOnly ? $assessmentEnvelope : $questionEnvelope), 200,
        )]);
    }

    private function afterGateway(callable $callback): void
    {
        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->once()
            ->andReturnUsing(function (...$args) use ($real, $callback) {
                $result = $real->generateStructured(...$args);
                $callback();

                return $result;
            });
        app()->instance(NativeAiGateway::class, $mock);
    }
}
