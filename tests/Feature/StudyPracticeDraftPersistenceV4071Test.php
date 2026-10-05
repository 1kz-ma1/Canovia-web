<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeDraftPersistenceV4071Test extends TestCase
{
    use RefreshDatabase;

    public function test_partial_answers_are_saved_without_submitting_the_exercise(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->importExercise($user, $plan, $task);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.draft', [$plan, $task]), [
                'practice_session_id' => $practiceSession->id,
                'answers_json' => json_encode([
                    'q1' => [
                        'answer' => 'A',
                        'reasoning' => 'DNSは名前解決を行うため。',
                    ],
                    'q2' => [
                        'answer' => '途中まで入力した説明',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->assertNoContent();

        $practiceSession->refresh();

        $this->assertSame(StudyPracticeSession::STATUS_IN_PROGRESS, $practiceSession->status);
        $this->assertSame('A', data_get($practiceSession->draft_answers, 'q1.answer'));
        $this->assertSame('DNSは名前解決を行うため。', data_get($practiceSession->draft_answers, 'q1.reasoning'));
        $this->assertSame('途中まで入力した説明', data_get($practiceSession->draft_answers, 'q2.answer'));
        $this->assertNotNull($practiceSession->draft_saved_at);
        $this->assertCount(2, $practiceSession->questions_snapshot);
    }

    public function test_answers_resume_silently_from_database_after_browser_session_state_is_lost(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->importExercise($user, $plan, $task);

        $practiceSession->update([
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'draft_answers' => [
                'q1' => [
                    'answer' => 'A',
                    'reasoning' => 'ここまで考えた内容を保持する',
                ],
                'q2' => [
                    'answer' => '途中回答',
                ],
            ],
            'draft_saved_at' => now(),
        ]);

        $this->app['session']->forget("study_practice.{$plan->id}.{$task->id}");

        $response = $this->actingAs($user)
            ->followingRedirects()
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('CONTINUE PRACTICE')
            ->assertSee('DNS確認')
            ->assertSee('ここまで考えた内容を保持する')
            ->assertSee('途中回答')
            ->assertSee('data-study-practice-draft-form', false)
            ->assertSee('data-draft-session-id="'.$practiceSession->id.'"', false);

        $this->assertMatchesRegularExpression(
            '/name="answers\[q1\]\[answer\]"[^>]*value="A"[^>]*checked/',
            $response->getContent(),
        );

        $this->assertSame(
            $practiceSession->id,
            session("study_practice.{$plan->id}.{$task->id}.practice_session_id"),
        );
    }

    public function test_reset_abandons_an_in_progress_draft_so_it_is_not_resumed(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $practiceSession = $this->importExercise($user, $plan, $task);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.draft', [$plan, $task]), [
                'practice_session_id' => $practiceSession->id,
                'answers_json' => json_encode([
                    'q1' => ['answer' => 'A'],
                ]),
            ])
            ->assertNoContent();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.reset', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertSame(
            StudyPracticeSession::STATUS_ABANDONED,
            $practiceSession->fresh()->status,
        );

        $this->app['session']->forget("study_practice.{$plan->id}.{$task->id}");

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertDontSee('DNS確認');
    }

    public function test_actor_cannot_write_draft_answers_into_another_practice_session(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $otherUser = User::factory()->create();

        $otherSession = StudyPracticeSession::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $otherUser->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_READY,
            'exercise_title' => '他人の演習',
            'strategy' => 'baseline_assessment',
            'strategy_version' => 'v1',
            'selector_type' => 'external_ai',
            'selector_version' => 'prompt-v40.1',
            'question_provider' => 'external_ai',
            'question_provider_mode' => 'handoff',
            'assessment_provider' => 'external_ai',
            'assessment_provider_mode' => 'handoff',
            'selection_context' => ['strategy' => ['key' => 'baseline_assessment']],
            'provider_payload' => [],
            'questions_snapshot' => [[
                'id' => 'q1',
                'prompt' => '説明してください。',
                'response_fields' => [[
                    'id' => 'answer',
                    'type' => 'textarea',
                    'label' => '回答',
                    'required' => true,
                    'placeholder' => '',
                    'choices' => [],
                ]],
            ]],
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.draft', [$plan, $task]), [
                'practice_session_id' => $otherSession->id,
                'answers_json' => json_encode(['q1' => ['answer' => '書き換え']]),
            ])
            ->assertStatus(409);

        $this->assertNull($otherSession->fresh()->draft_answers);
    }

    public function test_answering_ui_has_immediate_local_and_server_autosave_hooks(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $this->importExercise($user, $plan, $task);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-study-practice-draft-form', false)
            ->assertSee('data-draft-url=', false)
            ->assertSee('data-draft-session-token=', false);

        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('canovia.study-practice.draft.v1.', $javascript);
        $this->assertStringContainsString("form.addEventListener('input', scheduleSave)", $javascript);
        $this->assertStringContainsString("window.addEventListener('pagehide', flushBeforeLeave)", $javascript);
        $this->assertStringContainsString('persistServer(localDraft)', $javascript);
    }

    private function importExercise(User $user, Plan $plan, Task $task): StudyPracticeSession
    {
        $payload = [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => 'DNS確認',
            'questions' => [
                [
                    'id' => 'q1',
                    'prompt' => 'DNSの役割として適切なものを選び、理由も説明してください。',
                    'response_fields' => [
                        [
                            'id' => 'answer',
                            'type' => 'single_choice',
                            'label' => '回答',
                            'required' => true,
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
                        ],
                    ],
                ],
                [
                    'id' => 'q2',
                    'type' => 'text',
                    'prompt' => 'CNAMEレコードについて説明してください。',
                    'choices' => [],
                ],
            ],
        ];

        $prepareRequestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
                'prepare_request_id' => $prepareRequestId,
                'questions_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertSessionHasNoErrors();

        return StudyPracticeSession::where('prepare_request_id', $prepareRequestId)->firstOrFail();
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
