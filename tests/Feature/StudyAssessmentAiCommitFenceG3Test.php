<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\NativeAiRun;
use App\Models\Plan;
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

final class StudyAssessmentAiCommitFenceG3Test extends TestCase
{
    use RefreshDatabase;

    private array $question = [
        'id' => 'q1',
        'prompt' => 'DNSでメール配送先を指定するレコードは？',
        'work_input' => 'none',
        'response_fields' => [[
            'id' => 'answer',
            'type' => 'single_choice',
            'label' => '回答',
            'required' => true,
            'choices' => [
                ['id' => 'A', 'label' => 'Aレコード'],
                ['id' => 'B', 'label' => 'MXレコード'],
            ],
        ]],
    ];

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

    public function test_unchanged_answer_commits_assessment_attempt_and_evidence(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);

        $this->submit($user, $plan, $task)->assertSessionHasNoErrors();

        $this->assertSame(StudyPracticeSession::STATUS_ASSESSED, $session->fresh()->status);
        $this->assertSame('native_ai', $session->fresh()->assessment_provider);
        $this->assertDatabaseCount('study_practice_attempts', 1);
        $this->assertDatabaseCount('task_evidences', 1);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        Http::assertSentCount(1);
    }

    public function test_answer_draft_edited_after_provider_does_not_create_grade_or_evidence(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(fn () => $session->update([
            'draft_answers' => ['q1' => ['answer' => 'A']],
            'draft_saved_at' => now(),
        ]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
        $this->assertSame(['q1' => ['answer' => 'A']], $session->fresh()->draft_answers);
    }

    public function test_human_completed_session_during_provider_is_not_reopened(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(fn () => $session->update([
            'status' => StudyPracticeSession::STATUS_COMPLETED,
            'completed_at' => now(),
        ]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session, StudyPracticeSession::STATUS_COMPLETED);
    }

    public function test_plan_owner_transfer_after_provider_rejects_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $other = User::factory()->create();
        $this->afterGateway(fn () => $plan->update(['user_id' => $other->id]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
    }

    public function test_owner_transfer_away_and_back_after_provider_does_not_resurrect_ai_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $other = User::factory()->create();
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(function () use ($plan, $other, $user): void {
            $plan->update(['user_id' => $other->id]);
            $plan->update(['user_id' => $user->id]);
        });

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
        $this->assertSame((int) $user->id, (int) $plan->fresh()->user_id);
        $this->assertSame(3, (int) $plan->fresh()->authorization_epoch);
    }

    public function test_shared_editor_downgrade_then_repromotion_does_not_publish_old_ai_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $owner = User::factory()->create();
        $plan->update(['user_id' => $owner->id, 'is_collaborative' => true]);
        $membership = \App\Models\PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'role' => \App\Models\PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(function () use ($membership): void {
            $membership->update(['role' => \App\Models\PlanMember::ROLE_VIEWER]);
            $membership->update(['role' => \App\Models\PlanMember::ROLE_EDITOR]);
        });

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
        $this->assertSame(3, (int) $membership->fresh()->authorization_epoch);
    }

    public function test_shared_editor_rejoin_with_same_role_does_not_publish_old_ai_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $plan->update(['user_id' => User::factory()->create()->id, 'is_collaborative' => true]);
        $membership = \App\Models\PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'role' => \App\Models\PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(function () use ($membership): void {
            $userId = $membership->user_id;
            $planId = $membership->plan_id;
            $membership->delete();
            \App\Models\PlanMember::query()->create([
                'plan_id' => $planId, 'user_id' => $userId,
                'role' => \App\Models\PlanMember::ROLE_EDITOR, 'joined_at' => now(),
            ]);
        });

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
        $this->assertNotSame((int) $membership->id, (int) \App\Models\PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $user->id)->firstOrFail()->id);
    }

    public function test_task_move_after_provider_rejects_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $another = $this->plan($user);
        $this->afterGateway(fn () => $task->update(['plan_id' => $another->id]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
    }

    public function test_grant_expiry_during_provider_keeps_user_draft_without_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(fn () => UserProductGrant::query()
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
    }

    public function test_changed_question_snapshot_during_provider_rejects_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(fn () => $session->update([
            'questions_snapshot' => [[
                ...$this->question, 'prompt' => '問題が変更されました',
            ]],
        ]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
    }

    public function test_plan_context_change_after_provider_rejects_grade(): void
    {
        [$user, $plan, $task, $session] = $this->scenario();
        $this->fakeAssessment($plan, $task);
        $this->afterGateway(fn () => $task->update([
            'description' => '学習範囲が更新されました',
        ]));

        $this->submit($user, $plan, $task);
        $this->assertWithheld($session);
    }

    private function fakeAssessment(Plan $plan, Task $task): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-study-assessment-g3',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'schema_version' => '1.0',
                        'flow' => 'study_assessment',
                        'target_plan' => ['id' => $plan->id],
                        'target_task' => ['id' => $task->id],
                        'score_percent' => 100,
                        'question_feedback' => [[
                            'question_id' => 'q1',
                            'correctness' => 'correct',
                            'feedback' => 'MXが正解です',
                            'reasoning_feedback' => '',
                            'error_type' => 'none',
                            'weakness_topics' => [],
                            'misconceptions' => [],
                        ]],
                        'strengths' => ['DNS'],
                        'weaknesses' => [],
                        'recommended_task_progress_percent' => 70,
                        'evidence_summary' => 'DNSの理解を確認',
                        'next_action' => '別のDNS問題へ',
                        'next_step' => [
                            'kind' => 'continue_task',
                            'label' => '別のDNS問題へ',
                            'reason' => '復習するため',
                            'focus_topics' => [],
                            'question_count' => 0,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'total_tokens' => 200],
        ], 200)]);
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
        ]);
        $plan = $this->plan($user);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク',
            'description' => 'DNS演習',
            'status' => 'doing',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
        $session = StudyPracticeSession::query()->create([
            'prepare_request_id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_READY,
            'question_provider' => 'native_ai',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'external_ai',
            'assessment_provider_mode' => 'handoff',
            'questions_snapshot' => [$this->question],
            'exercise_title' => 'DNS演習',
            'strategy' => 'practice',
            'strategy_version' => 'v1',
            'selector_type' => 'native_ai',
            'selector_version' => 'v1',
            'started_at' => now(),
        ]);
        session()->put("study_practice.{$plan->id}.{$task->id}", [
            'title' => 'DNS演習',
            'questions' => [$this->question],
            'answers' => [],
            'draft_answers' => [],
            'evaluation_prompt' => null,
            'assessment' => null,
            'attempt_token' => (string) Str::uuid(),
            'attempt_id' => null,
            'practice_session_id' => $session->id,
        ]);

        return [$user, $plan, $task, $session];
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
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

    private function submit(User $user, Plan $plan, Task $task)
    {
        return $this->actingAs($user)->post(
            route('plans.tasks.study_practice.answers', [$plan, $task]),
            ['answers' => ['q1' => ['answer' => 'B']]],
        )->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));
    }

    private function assertWithheld(
        StudyPracticeSession $session,
        string $expectedStatus = StudyPracticeSession::STATUS_READY,
    ): void {
        $this->assertSame($expectedStatus, $session->fresh()->status);
        $this->assertNull($session->fresh()->assessment_payload);
        $this->assertNotNull($session->fresh()->draft_answers);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertNull(data_get(session("study_practice.{$session->plan_id}.{$session->task_id}"), 'assessment'));
        Http::assertSentCount(1);
    }
}
