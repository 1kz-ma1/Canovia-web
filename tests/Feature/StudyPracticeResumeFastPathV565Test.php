<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyPracticeOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeResumeFastPathV565Test extends TestCase
{
    use RefreshDatabase;

    public function test_normal_entry_redirects_ready_or_in_progress_session_to_resume_route(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $session = $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.study_practice.resume', [$plan, $task]));

        $this->assertSame(
            StudyPracticeSession::STATUS_IN_PROGRESS,
            $session->fresh()->status,
        );
    }

    public function test_resume_restores_draft_and_renders_questions_without_new_practice_setup(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'draft_answers' => [
                'q1' => [
                    'answer' => 'A',
                    'reasoning' => 'DNSは名前解決を行うため。',
                ],
            ],
            'draft_saved_at' => now(),
        ]);

        $this->mock(StudyPracticeOrchestrator::class, function ($mock) {
            $mock->shouldNotReceive('previewHandoff');
        });

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('CONTINUE PRACTICE')
            ->assertSee('DNS確認')
            ->assertSee('保存済みの回答をそのまま復元しました')
            ->assertSee('1/2問 回答済み')
            ->assertSee('続きから回答')
            ->assertSee('DNSは名前解決を行うため。')
            ->assertSee('data-study-practice-resume-fast-path', false)
            ->assertSee('data-study-practice-scroll-to="practice-questions"', false)
            ->assertDontSee('PRACTICE STRATEGY')
            ->assertDontSee('PRACTICE RELIABILITY')
            ->assertDontSee('aria-label="AI演習の進行状況"', false)
            ->assertDontSee('Canovia問題集から演習を始める');

        $this->assertMatchesRegularExpression(
            '/name="answers\[q1\]\[answer\]"[^>]*value="A"[^>]*checked/',
            $response->getContent(),
        );

        $this->assertSame(
            $practiceSession->id,
            session("study_practice.{$plan->id}.{$task->id}.practice_session_id"),
        );
    }

    public function test_freshly_prepared_session_uses_normal_answering_ui_not_resume_continuity(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_READY,
        ]);

        $this->actingAs($user)
            ->withSession([
                'study_practice_freshly_prepared_session_id' => $practiceSession->id,
                "study_practice.{$plan->id}.{$task->id}" => [
                    'title' => 'DNS確認',
                    'questions' => $this->questions(),
                    'answers' => [],
                    'draft_answers' => [],
                    'evaluation_prompt' => null,
                    'assessment' => null,
                    'attempt_id' => null,
                    'attempt_token' => (string) Str::uuid(),
                    'practice_session_id' => $practiceSession->id,
                ],
            ])
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('PRACTICE STRATEGY')
            ->assertSee('PRACTICE RELIABILITY')
            ->assertSee('aria-label="AI演習の進行状況"', false)
            ->assertDontSee('CONTINUE PRACTICE')
            ->assertDontSee('data-study-practice-resume-fast-path', false);
    }

    public function test_ready_session_without_answers_still_uses_resume_fast_path(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_READY,
            'draft_answers' => null,
        ]);

        $this->actingAs($user)
            ->followingRedirects()
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('CONTINUE PRACTICE')
            ->assertSee('0/2問 回答済み')
            ->assertSee('続きから回答');
    }

    public function test_answered_session_uses_existing_evaluation_recovery_not_answer_resume(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_ANSWERED,
            'draft_answers' => [
                'q1' => ['answer' => 'A'],
                'q2' => ['answer' => 'CNAMEの説明'],
            ],
            'assessment_payload' => [
                'evaluation_prompt' => 'この回答を評価してください。',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('AIに採点・評価してもらう')
            ->assertDontSee('CONTINUE PRACTICE')
            ->assertDontSee('data-study-practice-resume-fast-path', false);
    }

    public function test_abandoned_and_completed_sessions_are_not_resumed(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_ABANDONED,
            'updated_at' => now(),
        ]);
        $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_COMPLETED,
            'updated_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]));

        $response->assertOk();
        $response->assertDontSee('CONTINUE PRACTICE');
    }

    public function test_another_actors_session_is_not_resumed(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $other = User::factory()->create();

        $this->practiceSession($other, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertDontSee('CONTINUE PRACTICE');
    }

    public function test_reset_from_resume_abandons_session_and_returns_to_new_practice_setup(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'draft_answers' => [
                'q1' => ['answer' => 'A'],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.resume', [$plan, $task]))
            ->assertRedirect();

        $this->assertSame(
            $practiceSession->id,
            session("study_practice.{$plan->id}.{$task->id}.practice_session_id"),
        );

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.reset', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertSame(
            StudyPracticeSession::STATUS_ABANDONED,
            $practiceSession->fresh()->status,
        );
        $this->assertNull(
            session("study_practice.{$plan->id}.{$task->id}.practice_session_id"),
        );

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertDontSee('CONTINUE PRACTICE');
    }

    public function test_compact_legacy_study_ui_keeps_optional_reasoning_closed_and_next_on_the_right(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->practiceSession($user, $plan, $task);

        $response = $this->actingAs($user)->followingRedirects()
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-study-practice-optional-note', false)
            ->assertSee('data-study-practice-actions', false)
            ->assertSee('data-study-practice-next', false)
            ->assertSee('data-study-practice-question-progress', false)
            ->assertSee('lg:grid-cols-2', false)
            ->assertSee('sticky bottom-0', false)
            ->assertSee('次の問題 →');

        $html = $response->getContent();
        preg_match('/<details\b[^>]*data-study-practice-optional-note[^>]*>/', $html, $match);
        $this->assertNotEmpty($match, 'Optional textarea must be inside a native details control');
        $this->assertStringNotContainsString(' open', $match[0], 'Empty optional notes must start collapsed');
        $this->assertMatchesRegularExpression(
            '/data-study-practice-next[^>]*>次の問題 →<\/button>/', $html);
        // The visible action remains inside the same form to preserve final grading.
        $this->assertSame(0, \App\Models\StudyPracticeAttempt::count());
    }

    public function test_saved_optional_reasoning_opens_and_history_is_collapsed_without_deleting_evidence(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->practiceSession($user, $plan, $task, [
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'draft_answers' => ['q1' => [
                'answer' => 'A', 'reasoning' => '選択肢を比較した思考過程',
            ]],
            'draft_saved_at' => now(),
        ]);
        StudyPracticeAttempt::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $user->id, 'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => '前回の練習', 'questions' => [],
            'answers' => [], 'assessment' => [],
            'score_percent' => 80, 'recommended_task_progress_percent' => 45,
            'weaknesses' => ['前回の復習メモ'], 'next_action' => 'CNAMEを確認',
        ]);

        $response = $this->actingAs($user)->followingRedirects()
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('選択肢を比較した思考過程')
            ->assertSee('data-study-practice-history', false)
            ->assertSee('学習履歴を振り返る')
            ->assertSee('前回の復習メモ');
        $html = $response->getContent();
        preg_match('/<details\b[^>]*data-study-practice-optional-note[^>]*>/', $html, $reasoning);
        $this->assertNotEmpty($reasoning);
        $this->assertStringContainsString(' open', $reasoning[0], 'Nonempty saved draft stays expanded');
        preg_match('/<details\b[^>]*data-study-practice-history[^>]*>/', $html, $history);
        $this->assertNotEmpty($history);
        $this->assertStringNotContainsString(' open', $history[0], 'Learning history starts collapsed');
        $this->assertDatabaseCount('study_practice_attempts', 1);
    }

    private function practiceSession(
        User $user,
        Plan $plan,
        Task $task,
        array $overrides = [],
    ): StudyPracticeSession {
        return StudyPracticeSession::create(array_replace([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_READY,
            'exercise_title' => 'DNS確認',
            'strategy' => 'general_practice',
            'strategy_version' => 'v4',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank_grader',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => [
                    'version' => 'v4',
                    'key' => 'general_practice',
                    'label' => '分野横断演習',
                    'reason' => '保存済みSessionの方針',
                    'target_question_count' => 2,
                    'learning_phase' => [
                        'phase' => 'general_practice',
                        'label' => '総合演習',
                    ],
                    'focus_topics' => [],
                    'question_mix' => [
                        'primary' => 0,
                        'secondary' => 0,
                        'diagnostic' => 2,
                    ],
                    'weakness_priority' => [],
                ],
            ],
            'provider_payload' => [
                'title' => 'DNS確認',
                'pack' => ['title' => 'Test Pack'],
            ],
            'questions_snapshot' => $this->questions(),
            'selected_questions' => [],
            'started_at' => now()->subMinutes(10),
        ], $overrides));
    }

    private function questions(): array
    {
        return [
            [
                'id' => 'q1',
                'prompt' => 'DNSの役割として適切なものを選んでください。',
                'response_fields' => [
                    [
                        'id' => 'answer',
                        'type' => 'single_choice',
                        'label' => '回答',
                        'required' => true,
                        'placeholder' => '',
                        'choices' => [
                            ['id' => 'A', 'label' => '名前解決'],
                            ['id' => 'B', 'label' => '暗号化'],
                        ],
                    ],
                    [
                        'id' => 'reasoning',
                        'type' => 'textarea',
                        'label' => '考え方・判断理由',
                        'required' => false,
                        'placeholder' => '',
                        'choices' => [],
                    ],
                ],
                'type' => 'single_choice',
                'choices' => [
                    ['id' => 'A', 'label' => '名前解決'],
                    ['id' => 'B', 'label' => '暗号化'],
                ],
            ],
            [
                'id' => 'q2',
                'prompt' => 'CNAMEについて説明してください。',
                'response_fields' => [
                    [
                        'id' => 'answer',
                        'type' => 'textarea',
                        'label' => '回答',
                        'required' => true,
                        'placeholder' => '',
                        'choices' => [],
                    ],
                ],
                'type' => 'text',
                'choices' => [],
            ],
        ];
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

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
            'title' => '科目Aの分野横断問題演習を進める',
            'description' => '科目A全体を横断して弱点を探索する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
