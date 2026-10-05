<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\TaskEvidence;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeResultRecoveryV4073Test extends TestCase
{
    use RefreshDatabase;

    public function test_answered_handoff_survives_session_loss_and_assessment_remains_visible(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->answeredSession($user, $plan, $task);
        $show = route('plans.tasks.study_practice.show', [$plan, $task]);
        $key = "study_practice.{$plan->id}.{$task->id}";

        $this->app['session']->forget($key);

        $this->actingAs($user)
            ->get($show)
            ->assertOk()
            ->assertSee('評価プロンプトをコピー')
            ->assertSee('DNSの役割')
            ->assertSee('名前解決');

        $this->assertSame($practiceSession->id, session("{$key}.practice_session_id"));
        $this->assertNotEmpty(session("{$key}.answers"));
        $this->assertSame(
            'ORIGINAL EVALUATION PROMPT',
            session("{$key}.evaluation_prompt"),
        );

        $assessment = [
            'schema_version' => '1.0',
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 88,
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'correct',
                'feedback' => 'DNSの役割を説明できています。',
                'reasoning_feedback' => '名前解決という観点が正しいです。',
                'misconceptions' => [],
            ]],
            'strengths' => ['DNS'],
            'weaknesses' => ['CNAME'],
            'recommended_task_progress_percent' => 75,
            'evidence_summary' => '基本概念を説明できています。',
            'next_action' => 'CNAMEレコードを確認する',
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
                'assessment_json' => json_encode($assessment, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('study_practice_scroll_to', 'practice-assessment');

        $attempt = StudyPracticeAttempt::where('study_practice_session_id', $practiceSession->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(88, $attempt->score_percent);
        $this->assertSame(
            StudyPracticeSession::STATUS_ASSESSED,
            $practiceSession->fresh()->status,
        );

        $evidence = TaskEvidence::query()
            ->where('task_id', $task->id)
            ->where('source', 'native')
            ->where('type', 'study_practice_assessed')
            ->where('external_key', 'study-practice-attempt:'.$attempt->id)
            ->firstOrFail();

        $this->assertSame(88, (int) data_get($evidence->metadata, 'score_percent'));
        $this->assertSame(75, (int) data_get($evidence->metadata, 'recommended_task_progress_percent'));

        // Simulate another navigation/session race after the assessment POST.
        $this->app['session']->forget($key);

        $this->actingAs($user)
            ->get($show)
            ->assertOk()
            ->assertSee('ASSESSMENT / NEXT STEP')
            ->assertSee('88%')
            ->assertSee('CNAMEレコードを確認する')
            ->assertSee('Taskへ反映');

        $this->assertSame($attempt->id, session("{$key}.attempt_id"));
        $this->assertSame(88, data_get(session("{$key}.assessment"), 'score_percent'));
    }

    public function test_assessment_post_recovers_answered_handoff_without_an_intermediate_get(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->answeredSession($user, $plan, $task);
        $show = route('plans.tasks.study_practice.show', [$plan, $task]);
        $key = "study_practice.{$plan->id}.{$task->id}";

        // Reproduce the production failure: the durable ANSWERED session exists,
        // but the PHP session disappeared before the learner pasted assessment JSON.
        $this->app['session']->forget($key);

        $assessment = [
            'schema_version' => '1.0',
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 90,
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'correct',
                'feedback' => 'DNSの役割を説明できています。',
                'reasoning_feedback' => '名前解決という観点が正しいです。',
                'misconceptions' => [],
            ]],
            'strengths' => ['DNS'],
            'weaknesses' => ['CNAME'],
            'recommended_task_progress_percent' => 80,
            'evidence_summary' => '回答済み状態をDBから復元して評価できます。',
            'next_action' => 'CNAMEを追加演習する',
            'next_step' => [
                'kind' => 'practice',
                'label' => 'CNAMEを3問演習する',
                'reason' => 'CNAMEの確認を続けるため。',
                'focus_topics' => ['DNS', 'CNAME'],
                'question_count' => 3,
            ],
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
                'assessment_json' => json_encode($assessment, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('study_practice_scroll_to', 'practice-assessment');

        $attempt = StudyPracticeAttempt::where('study_practice_session_id', $practiceSession->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(90, $attempt->score_percent);
        $this->assertSame('名前解決', data_get($attempt->answers, '0.fields.0.value'));
        $this->assertSame('practice', data_get($attempt->assessment, 'next_step.kind'));
        $this->assertSame(
            StudyPracticeSession::STATUS_ASSESSED,
            $practiceSession->fresh()->status,
        );
        $this->assertSame(
            'ORIGINAL EVALUATION PROMPT',
            session("{$key}.evaluation_prompt"),
        );
        $this->assertSame($practiceSession->id, session("{$key}.practice_session_id"));
        $this->assertSame($attempt->id, session("{$key}.attempt_id"));
    }

    public function test_assessment_post_does_not_reopen_an_older_answered_session(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $olderAnswered = $this->answeredSession($user, $plan, $task);
        $newerSession = $this->answeredSession($user, $plan, $task);
        $newerSession->update(['status' => StudyPracticeSession::STATUS_ASSESSED]);
        $key = "study_practice.{$plan->id}.{$task->id}";

        $this->app['session']->forget($key);

        $assessment = [
            'schema_version' => '1.0',
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 90,
            'question_feedback' => [],
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 80,
            'evidence_summary' => 'stale recovery guard',
            'next_action' => '次へ',
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
                'assessment_json' => json_encode($assessment, JSON_UNESCAPED_UNICODE),
            ])
            ->assertSessionHasErrors('assessment_json');

        $this->assertDatabaseMissing('study_practice_attempts', [
            'study_practice_session_id' => $olderAnswered->id,
        ]);
    }

    public function test_study_practice_redirects_expose_the_new_step_for_scroll_reveal(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $payload = [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => 'ネットワーク確認',
            'questions' => [[
                'id' => 'q1',
                'prompt' => 'DNSの役割を説明してください。',
                'response_fields' => [[
                    'id' => 'answer',
                    'type' => 'textarea',
                    'label' => '回答',
                    'required' => true,
                ]],
            ]],
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
                'questions_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertSessionHas('study_practice_scroll_to', 'practice-questions');

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => [
                    'q1' => ['answer' => 'ドメイン名とIPアドレスを対応付ける。'],
                ],
            ])
            ->assertSessionHas('study_practice_scroll_to', 'practice-evaluation');

        $view = file_get_contents(resource_path('views/study_practice/show.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('id="practice-questions"', $view);
        $this->assertStringContainsString('id="practice-evaluation"', $view);
        $this->assertStringContainsString('id="practice-assessment"', $view);
        $this->assertStringContainsString('data-study-practice-scroll-to', $view);
        $this->assertStringContainsString('target.scrollIntoView', $script);
    }

    private function answeredSession(User $user, Plan $plan, Task $task): StudyPracticeSession
    {
        return StudyPracticeSession::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'actor_token' => null,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_ANSWERED,
            'exercise_title' => 'DNS確認',
            'strategy' => 'baseline_assessment',
            'strategy_version' => 'v1',
            'selector_type' => 'external_ai',
            'selector_version' => 'prompt-v40.1',
            'question_provider' => 'external_ai',
            'question_provider_mode' => 'handoff',
            'assessment_provider' => 'external_ai',
            'assessment_provider_mode' => 'handoff',
            'selection_context' => [
                'strategy' => [
                    'key' => 'baseline_assessment',
                    'label' => '初回理解度確認',
                    'reason' => '現在の理解度を確認するため。',
                    'target_question_count' => 1,
                    'focus_topics' => [],
                ],
            ],
            'provider_payload' => [
                'generation_prompt' => 'ORIGINAL GENERATION PROMPT',
            ],
            'assessment_payload' => [
                'evaluation_prompt' => 'ORIGINAL EVALUATION PROMPT',
            ],
            'questions_snapshot' => [[
                'id' => 'q1',
                'prompt' => 'DNSの役割を説明してください。',
                'response_fields' => [[
                    'id' => 'answer',
                    'type' => 'textarea',
                    'label' => '回答',
                    'required' => true,
                    'placeholder' => '',
                    'choices' => [],
                ]],
                'type' => 'text',
                'choices' => [],
            ]],
            'draft_answers' => [
                'q1' => [
                    'answer' => '名前解決',
                ],
            ],
            'draft_saved_at' => now(),
            'started_at' => now(),
        ]);
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
