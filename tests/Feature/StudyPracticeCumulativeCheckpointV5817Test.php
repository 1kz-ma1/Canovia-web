<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyPracticeCumulativeCheckpointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeCumulativeCheckpointV5817Test extends TestCase
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
        ]);
    }

    public function test_no_attempts_starts_at_zero_with_fifty_as_next_checkpoint(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            0,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            50,
            $result['next_checkpoint'],
        );
        $this->assertSame(
            50,
            $result['questions_to_next_checkpoint'],
        );
        $this->assertFalse(
            data_get(
                $result,
                'checkpoints.50.reached',
            ),
        );
        $this->assertFalse($result['complete']);
    }

    public function test_invalid_and_duplicate_feedback_do_not_inflate_question_count(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $this->attempt(
            $plan,
            $task,
            $user,
            [
                [
                    'question_id' => 'q1',
                    'correctness' => 'correct',
                ],
                [
                    'question_id' => 'q1',
                    'correctness' => 'incorrect',
                ],
                [
                    'question_id' => '',
                    'correctness' => 'correct',
                ],
                [
                    'question_id' => 'q2',
                    'correctness' => 'pending',
                ],
                [
                    'question_id' => 'q3',
                    'correctness' => 'partial',
                ],
            ],
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            2,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            1,
            $result['correct_count'],
        );
        $this->assertSame(
            1,
            $result['partial_count'],
        );
        $this->assertSame(
            0,
            $result['incorrect_count'],
        );
        $this->assertSame(
            50,
            $result['observed_correct_rate_percent'],
        );
    }

    public function test_bank_repeat_counts_as_volume_but_not_unique_bank_question(): void
    {
        [$user, $plan, $task] = $this->studyTask();
        $question = $this->bankQuestion();

        foreach (['correct', 'incorrect'] as $correctness) {
            $session = $this->practiceSession(
                $plan,
                $task,
                $user,
                [[
                    'question_ref' => 'bank_'.$question->id,
                    'question_id' => $question->id,
                    'source_type' => 'official',
                    'source_reference' => 'test',
                ]],
            );

            $this->attempt(
                $plan,
                $task,
                $user,
                [[
                    'question_id' => 'bank_'.$question->id,
                    'correctness' => $correctness,
                ]],
                $session,
            );
        }

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            2,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            2,
            $result['bank_question_exposure_count'],
        );
        $this->assertSame(
            1,
            $result['unique_bank_question_count'],
        );
        $this->assertSame(
            1,
            $result['repeated_bank_exposure_count'],
        );
    }

    public function test_ai_graded_questions_count_without_fake_bank_uniqueness(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $this->attempt(
            $plan,
            $task,
            $user,
            [
                [
                    'question_id' => 'native_q1',
                    'correctness' => 'correct',
                ],
                [
                    'question_id' => 'native_q2',
                    'correctness' => 'incorrect',
                ],
            ],
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            2,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            0,
            $result['unique_bank_question_count'],
        );
        $this->assertFalse(
            $result['has_bank_provenance'],
        );
    }

    public function test_forty_nine_questions_leave_one_to_fifty(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $this->gradedVolume(
            $plan,
            $task,
            $user,
            49,
            'correct',
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            49,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            50,
            $result['next_checkpoint'],
        );
        $this->assertSame(
            1,
            $result['questions_to_next_checkpoint'],
        );
    }

    public function test_fifty_checkpoint_freezes_first_fifty_questions(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $this->gradedVolume(
            $plan,
            $task,
            $user,
            50,
            'correct',
        );
        $this->attempt(
            $plan,
            $task,
            $user,
            [[
                'question_id' => 'after_50',
                'correctness' => 'incorrect',
            ]],
            null,
            now()->addMinute(),
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );
        $checkpoint = data_get(
            $result,
            'checkpoints.50',
        );

        $this->assertTrue($checkpoint['reached']);
        $this->assertSame(
            50,
            $checkpoint['assessed_question_count'],
        );
        $this->assertSame(
            50,
            $checkpoint['correct_count'],
        );
        $this->assertSame(
            100,
            $checkpoint['observed_correct_rate_percent'],
        );

        $this->assertSame(
            51,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            98,
            $result['observed_correct_rate_percent'],
        );
        $this->assertSame(
            100,
            $result['next_checkpoint'],
        );
        $this->assertSame(
            49,
            $result['questions_to_next_checkpoint'],
        );
    }

    public function test_one_hundred_checkpoint_uses_first_hundred_and_partial_is_not_correct(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $this->gradedVolume(
            $plan,
            $task,
            $user,
            80,
            'correct',
        );
        $this->gradedVolume(
            $plan,
            $task,
            $user,
            10,
            'partial',
            1000,
        );
        $this->gradedVolume(
            $plan,
            $task,
            $user,
            10,
            'incorrect',
            2000,
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );
        $checkpoint = data_get(
            $result,
            'checkpoints.100',
        );

        $this->assertTrue($checkpoint['reached']);
        $this->assertSame(
            100,
            $checkpoint['assessed_question_count'],
        );
        $this->assertSame(
            80,
            $checkpoint['correct_count'],
        );
        $this->assertSame(
            10,
            $checkpoint['partial_count'],
        );
        $this->assertSame(
            10,
            $checkpoint['incorrect_count'],
        );
        $this->assertSame(
            80,
            $checkpoint['observed_correct_rate_percent'],
        );
        $this->assertNull($result['next_checkpoint']);
        $this->assertTrue($result['complete']);
    }

    public function test_chronological_order_not_insert_order_controls_checkpoint_snapshot(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        $later = now()->addHour();
        $earlier = now();

        $this->gradedVolume(
            $plan,
            $task,
            $user,
            10,
            'incorrect',
            9000,
            $later,
        );
        $this->gradedVolume(
            $plan,
            $task,
            $user,
            50,
            'correct',
            0,
            $earlier,
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            100,
            data_get(
                $result,
                'checkpoints.50.observed_correct_rate_percent',
            ),
        );
        $this->assertSame(
            83,
            $result['observed_correct_rate_percent'],
        );
    }

    public function test_actor_and_task_isolation(): void
    {
        [$user, $plan, $task] = $this->studyTask();
        $otherUser = User::factory()->create();
        $otherTask = $this->task(
            $plan,
            '別の演習Task',
            2,
        );

        $this->gradedVolume(
            $plan,
            $task,
            $user,
            3,
            'correct',
        );
        $this->gradedVolume(
            $plan,
            $task,
            $otherUser,
            7,
            'incorrect',
            1000,
        );
        $this->gradedVolume(
            $plan,
            $otherTask,
            $user,
            9,
            'incorrect',
            2000,
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            3,
            $result['assessed_question_count'],
        );
        $this->assertSame(
            3,
            $result['correct_count'],
        );
    }

    public function test_attempt_history_is_bounded_at_oldest_five_hundred_and_reports_truncation(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        for ($i = 0; $i < 501; $i++) {
            $this->attempt(
                $plan,
                $task,
                $user,
                [[
                    'question_id' => 'q_'.$i,
                    'correctness' => 'correct',
                ]],
                null,
                now()->addSeconds($i),
            );
        }

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertTrue(
            $result['history_truncated'],
        );
        $this->assertSame(
            500,
            $result['attempts_considered'],
        );
        $this->assertSame(
            500,
            $result['assessed_question_count'],
        );
        $this->assertTrue(
            data_get(
                $result,
                'checkpoints.100.reached',
            ),
        );
    }

    public function test_practice_ui_renders_checkpoint_without_provider_call_or_mutation(): void
    {
        [$user, $plan, $task] = $this->studyTask();

        Http::fake();

        $this->gradedVolume(
            $plan,
            $task,
            $user,
            10,
            'correct',
        );

        $beforeAttempts =
            StudyPracticeAttempt::query()->count();
        $beforeSessions =
            StudyPracticeSession::query()->count();

        $this->actingAs($user)
            ->get(
                route(
                    'plans.tasks.study_practice.show',
                    [$plan, $task],
                ),
            )
            ->assertOk()
            ->assertSee(
                'data-study-practice-cumulative-checkpoint',
                false,
            )
            ->assertSee('50問 / 100問の累積確認')
            ->assertSee('10 / 50問')
            ->assertSee('次のCheckpointまであと 40問');

        $this->assertSame(
            $beforeAttempts,
            StudyPracticeAttempt::query()->count(),
        );
        $this->assertSame(
            $beforeSessions,
            StudyPracticeSession::query()->count(),
        );

        Http::assertNothingSent();
    }

    private function project(
        Plan $plan,
        Task $task,
        User $user,
    ): array {
        return app(
            StudyPracticeCumulativeCheckpointService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );
    }

    private function gradedVolume(
        Plan $plan,
        Task $task,
        User $user,
        int $count,
        string $correctness,
        int $offset = 0,
        $createdAt = null,
    ): void {
        $remaining = $count;
        $cursor = 0;

        while ($remaining > 0) {
            $take = min(10, $remaining);
            $feedback = [];

            for ($i = 0; $i < $take; $i++) {
                $feedback[] = [
                    'question_id' =>
                        'q_'.($offset + $cursor + $i),
                    'correctness' => $correctness,
                ];
            }

            $this->attempt(
                $plan,
                $task,
                $user,
                $feedback,
                null,
                $createdAt
                    ? $createdAt->copy()->addSeconds($cursor)
                    : now()->addSeconds($offset + $cursor),
            );

            $cursor += $take;
            $remaining -= $take;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $feedback
     */
    private function attempt(
        Plan $plan,
        Task $task,
        User $user,
        array $feedback,
        ?StudyPracticeSession $session = null,
        $createdAt = null,
    ): StudyPracticeAttempt {
        $attempt = StudyPracticeAttempt::query()->create([
            'study_practice_session_id' =>
                $session?->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash(
                'sha256',
                'checkpoint-'
                .$user->id.'-'
                .$task->id.'-'
                .Str::uuid(),
            ),
            'exercise_title' => 'checkpoint',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'question_feedback' => $feedback,
            ],
            'score_percent' => 0,
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' =>
                (int) $task->progress_percent,
            'evidence_summary' => 'checkpoint',
            'next_action' => 'continue',
        ]);

        if ($createdAt !== null) {
            $attempt->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $attempt->fresh();
    }

    /**
     * @param array<int,array<string,mixed>> $selected
     */
    private function practiceSession(
        Plan $plan,
        Task $task,
        User $user,
        array $selected,
    ): StudyPracticeSession {
        return StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' =>
                StudyPracticeSession::STATUS_ASSESSED,
            'strategy' => 'general_practice',
            'strategy_version' => 'v4',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' =>
                'question_bank_grader',
            'assessment_provider_mode' => 'direct',
            'selected_questions' => $selected,
            'completed_at' => now(),
        ]);
    }

    private function bankQuestion(): Question
    {
        $pack = QuestionPack::query()->create([
            'slug' => 'checkpoint-pack-'.Str::uuid(),
            'title' => 'Checkpoint Pack',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'downloadable' => true,
            'metadata' => [],
        ]);

        return Question::query()->create([
            'question_pack_id' => $pack->id,
            'external_key' => 'q-'.Str::uuid(),
            'source_type' => 'official',
            'source_reference' => 'test',
            'prompt' => 'test',
            'response_schema' => [[
                'id' => 'answer',
                'type' => 'single_choice',
                'label' => '回答',
                'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => 'A'],
                    ['id' => 'B', 'label' => 'B'],
                ],
            ]],
            'grading_rule' => [
                'type' => 'exact_choice',
                'field_id' => 'answer',
                'answer' => 'A',
            ],
            'learning_metadata' => [],
            'difficulty' => 3,
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function studyTask(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 応用情報技術者試験 科目A',
            'description' => '科目Aを100問演習する',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = $this->task(
            $plan,
            '科目Aの分野横断問題演習を進める',
            1,
        );

        return [$user, $plan, $task];
    }

    private function task(
        Plan $plan,
        string $title,
        int $sortOrder,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => '科目A全体を横断する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 120,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $sortOrder,
        ]);
    }
}
