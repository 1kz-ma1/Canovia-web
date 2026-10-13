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

final class StudyPracticeGenerationCommitFenceG3Test extends TestCase
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

    public function test_authorized_native_question_generation_remains_usable(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);

        $this->generate($user, $plan, $task)->assertSessionHasNoErrors();

        $session = StudyPracticeSession::query()->firstOrFail();
        $this->assertSame(StudyPracticeSession::STATUS_READY, $session->status);
        $this->assertSame('hybrid_ai', $session->question_provider);
        $this->assertSame('q1', data_get($session->questions_snapshot, '0.id'));
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        Http::assertSentCount(1);
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

    public function test_role_downgrade_and_repromotion_during_native_generation_withholds_questions(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $plan->update(['user_id' => User::factory()->create()->id, 'is_collaborative' => true]);
        $membership = \App\Models\PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'role' => \App\Models\PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);
        $this->fakeNative($plan, $task);
        $this->afterGateway(function () use ($membership): void {
            $membership->update(['role' => \App\Models\PlanMember::ROLE_VIEWER]);
            $membership->update(['role' => \App\Models\PlanMember::ROLE_EDITOR]);
        });

        $this->generate($user, $plan, $task);
        $this->assertDatabaseCount('study_practice_sessions', 0);
        $this->assertDatabaseCount('practice_question_demands', 0);
        $this->assertDatabaseCount('practice_question_candidates', 0);
        $this->assertSame(3, (int) $membership->fresh()->authorization_epoch);
    }

    public function test_member_rejoin_with_same_role_during_native_generation_withholds_questions(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $plan->update(['user_id' => User::factory()->create()->id, 'is_collaborative' => true]);
        $membership = \App\Models\PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'role' => \App\Models\PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);
        $this->fakeNative($plan, $task);
        $this->afterGateway(function () use ($membership): void {
            $planId = $membership->plan_id;
            $userId = $membership->user_id;
            $membership->delete();
            \App\Models\PlanMember::query()->create([
                'plan_id' => $planId,
                'user_id' => $userId,
                'role' => \App\Models\PlanMember::ROLE_EDITOR,
                'joined_at' => now(),
            ]);
        });

        $this->generate($user, $plan, $task);
        $this->assertDatabaseCount('study_practice_sessions', 0);
        $this->assertDatabaseCount('practice_question_demands', 0);
        $this->assertDatabaseCount('practice_question_candidates', 0);
        $this->assertNotSame((int) $membership->id, (int) \App\Models\PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $user->id)->firstOrFail()->id);
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

    public function test_study_review_can_generate_new_questions_for_task_already_completed_before_request(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $task->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);
        $this->fakeNative($plan, $task);

        $this->generate($user, $plan, $task)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_practice_sessions', 1);
        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_user_finishes_task_while_native_generation_runs_and_no_new_session_is_saved(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $this->afterGateway(fn () => Task::query()->whereKey($task->id)->update([
            'status' => 'done', 'progress_percent' => 100,
        ]));

        $this->generate($user, $plan, $task);

        $this->assertSame('done', $task->fresh()->status);
        $this->assertDatabaseCount('study_practice_sessions', 0);
    }

    public function test_duplicate_native_prepare_does_not_rewrite_candidate_or_demand(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $id = (string) Str::uuid();
        $url = route('plans.tasks.study_practice.native.prepare', [$plan, $task]);

        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertSessionHasNoErrors();

        $session = StudyPracticeSession::query()->firstOrFail();
        $fingerprint = data_get($session->selection_context, 'native_generation_source_fingerprint');
        $this->assertIsString($fingerprint);
        $this->assertSame(64, strlen($fingerprint));
        $this->assertDatabaseCount('practice_question_demands', 1);
        $this->assertDatabaseCount('practice_question_candidates', 1);
        $candidate = \App\Models\PracticeQuestionCandidate::query()->firstOrFail();
        $firstSeen = $candidate->last_seen_at;
        $this->assertSame(1, (int) $candidate->generation_count);

        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_practice_sessions', 1);
        $this->assertDatabaseCount('practice_question_demands', 1);
        $this->assertDatabaseCount('practice_question_candidates', 1);
        $this->assertSame(1, (int) $candidate->fresh()->generation_count);
        $this->assertEquals($firstSeen, $candidate->fresh()->last_seen_at);
        Http::assertSentCount(1);
    }

    public function test_duplicate_native_prepare_does_not_reset_in_progress_answer_draft(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $id = (string) Str::uuid();
        $url = route('plans.tasks.study_practice.native.prepare', [$plan, $task]);

        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertSessionHasNoErrors();
        $session = StudyPracticeSession::query()->firstOrFail();
        $existingBrowserState = session("study_practice.{$plan->id}.{$task->id}");

        $session->update([
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'draft_answers' => ['q1' => ['answer' => 'B']],
            'draft_saved_at' => now(),
        ]);

        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertRedirect();

        $this->assertSame(StudyPracticeSession::STATUS_IN_PROGRESS, $session->fresh()->status);
        $this->assertSame(['q1' => ['answer' => 'B']], $session->fresh()->draft_answers);
        $this->assertSame($existingBrowserState, session("study_practice.{$plan->id}.{$task->id}"));
        $this->assertDatabaseCount('practice_question_candidates', 1);
        Http::assertSentCount(1);
    }

    public function test_duplicate_prepare_refuses_changed_task_instead_of_backfilling_candidates(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $id = (string) Str::uuid();
        $url = route('plans.tasks.study_practice.native.prepare', [$plan, $task]);

        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertSessionHasNoErrors();

        $task->update(['description' => '範囲を更新した']);
        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertRedirect();

        $this->assertSame('範囲を更新した', $task->fresh()->description);
        $this->assertDatabaseCount('practice_question_demands', 1);
        $this->assertDatabaseCount('practice_question_candidates', 1);
        $this->assertSame(1, (int) \App\Models\PracticeQuestionCandidate::query()->firstOrFail()->generation_count);
        Http::assertSentCount(1);
    }

    public function test_historical_unsigned_native_session_is_not_silently_resigned_on_replay(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $id = (string) Str::uuid();
        $url = route('plans.tasks.study_practice.native.prepare', [$plan, $task]);

        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertSessionHasNoErrors();

        $session = StudyPracticeSession::query()->firstOrFail();
        $context = $session->selection_context;
        unset($context['native_generation_source_fingerprint']);
        $session->update(['selection_context' => $context]);
        $this->actingAs($user)->post($url, [
            'prepare_request_id' => $id,
        ])->assertRedirect();

        $this->assertNull(data_get($session->fresh()->selection_context, 'native_generation_source_fingerprint'));
        $this->assertDatabaseCount('practice_question_demands', 1);
        $this->assertDatabaseCount('practice_question_candidates', 1);
        Http::assertSentCount(1);
    }

    public function test_controller_finalization_rejects_task_change_after_orchestrator_commit(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $this->afterOrchestrator(fn () => $task->update(['description' => '最新の出題範囲']));

        $this->generate($user, $plan, $task)->assertSessionHas('status');

        $study = StudyPracticeSession::query()->firstOrFail();
        $this->assertSame(StudyPracticeSession::STATUS_READY, $study->status);
        $this->assertSame('最新の出題範囲', $task->fresh()->description);
        $this->assertNull(session("study_practice.{$plan->id}.{$task->id}"));
        $this->assertNull(NativeAiRun::query()->firstOrFail()->study_practice_session_id);
        $this->assertDatabaseCount('practice_question_demands', 1);
        Http::assertSentCount(1);
    }

    public function test_controller_finalization_does_not_reset_concurrent_human_answer(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->fakeNative($plan, $task);
        $this->afterOrchestrator(function (): void {
            StudyPracticeSession::query()->firstOrFail()->update([
                'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
                'draft_answers' => ['q1' => ['answer' => 'B']],
                'draft_saved_at' => now(),
            ]);
        });

        $this->generate($user, $plan, $task)->assertSessionHas('status');

        $study = StudyPracticeSession::query()->firstOrFail();
        $this->assertSame(StudyPracticeSession::STATUS_IN_PROGRESS, $study->status);
        $this->assertSame(['q1' => ['answer' => 'B']], $study->draft_answers);
        $this->assertNull(session("study_practice.{$plan->id}.{$task->id}"));
        $this->assertNull(NativeAiRun::query()->firstOrFail()->study_practice_session_id);
        Http::assertSentCount(1);
    }

    private function afterOrchestrator(callable $callback): void
    {
        $real = app(\App\Services\StudyPracticeOrchestrator::class);
        $mock = Mockery::mock(\App\Services\StudyPracticeOrchestrator::class)->makePartial();
        $mock->shouldReceive('prepare')->once()
            ->andReturnUsing(function (...$args) use ($real, $callback) {
                $result = $real->prepare(...$args);
                $callback();

                return $result;
            });
        app()->instance(\App\Services\StudyPracticeOrchestrator::class, $mock);
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
