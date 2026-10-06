<?php

namespace Tests\Feature;

use App\Execution\ExecutionCapability;
use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Study\StudyMethodRecommendationService;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\ExecutionCapabilityResolver;
use App\Services\ExecutionProviderRegistry;
use App\Services\StudyActivityPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyLanguageActivitiesV5810Test extends TestCase
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

    public function test_pure_listening_task_prefers_listening(): void
    {
        [, $plan, $task] = $this->scenario(
            'TOEIC Listening',
            'TOEIC音声を聞いて内容を理解する',
            '字幕を見る前に音声を聞き、聞き取れない箇所を確認する',
        );

        $activity = app(StudyActivityPolicyService::class)
            ->forPlanTask($plan, $task);

        $this->assertSame(
            StudyActivityPolicyService::LISTENING,
            data_get($activity, 'primary.key'),
        );
    }

    public function test_listening_question_exercise_keeps_question_practice(): void
    {
        [, $plan, $task] = $this->scenario(
            'TOEIC Listening',
            'TOEICリスニング問題演習',
            'Part 3の過去問を解いて採点し、理解を確認する',
        );

        $activity = app(StudyActivityPolicyService::class)
            ->forPlanTask($plan, $task);

        $this->assertSame(
            StudyActivityPolicyService::QUESTION_PRACTICE,
            data_get($activity, 'primary.key'),
        );
    }

    public function test_dictation_and_shadowing_tasks_get_distinct_activities(): void
    {
        [, $dictationPlan, $dictation] = $this->scenario(
            '英語学習',
            '英文をディクテーションする',
            '短い音声を聞き取って書き取り、transcriptと比較する',
        );
        [, $shadowPlan, $shadowing] = $this->scenario(
            '英語発音',
            '英語音声をシャドーイングする',
            '音声に続いて発話し、リズムと強勢をまねる',
        );

        $policy = app(StudyActivityPolicyService::class);

        $this->assertSame(
            StudyActivityPolicyService::DICTATION,
            data_get(
                $policy->forPlanTask($dictationPlan, $dictation),
                'primary.key',
            ),
        );
        $this->assertSame(
            StudyActivityPolicyService::SHADOWING,
            data_get(
                $policy->forPlanTask($shadowPlan, $shadowing),
                'primary.key',
            ),
        );
    }

    public function test_state_aware_method_recommendation_preserves_explicit_language_task_semantics(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'TOEIC暗記型',
            '英文をディクテーションする',
            '音声を書き取り、聞き落としを確認する',
        );

        $method = app(StudyMethodRecommendationService::class)
            ->recommend(
                $plan,
                $task,
                ['key' => 'memorization'],
                ['has_confirmed_scope' => false],
                null,
                $user->id,
                null,
                null,
            );

        $this->assertSame(
            StudyActivityPolicyService::DICTATION,
            data_get($method, 'primary.key'),
        );
        $this->assertSame(
            route('plans.tasks.study_language.show', [
                $plan,
                $task,
                'activity' => StudyActivityPolicyService::DICTATION,
            ]),
            data_get($method, 'primary.url'),
        );
    }

    public function test_study_activity_page_links_language_primary_to_dedicated_surface(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'TOEIC Listening',
            '音声を聞いてリスニング練習をする',
            '字幕なしで聞き取り、難しい区間だけ繰り返す',
        );

        Http::fake();

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_activity.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('Listening')
            ->assertSee('Activityを実施')
            ->assertSee(
                route('plans.tasks.study_language.show', [
                    $plan,
                    $task,
                    'activity' => 'listening',
                ]),
                false,
            );

        Http::assertNothingSent();
    }

    public function test_language_surface_renders_activity_steps_and_existing_resources_without_provider_call(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '英語 Listening',
            '英文をディクテーションする',
            '音声を書き取ってtranscriptと比較する',
        );

        $resource = $plan->resources()->create([
            'created_by_user_id' => $user->id,
            'provider' => 'google_drive',
            'resource_type' => 'file',
            'title' => 'Listening audio',
            'url' => 'https://drive.google.com/file/d/example/view',
        ]);
        $task->resources()->attach($resource->id);

        Http::fake();

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_language.show',
                [
                    $plan,
                    $task,
                    'activity' => 'dictation',
                ],
            ))
            ->assertOk()
            ->assertSee('Dictation')
            ->assertSee('短く聞く')
            ->assertSee('聞こえたまま書く')
            ->assertSee('Listening audio')
            ->assertSee(
                'https://drive.google.com/file/d/example/view',
                false,
            )
            ->assertSee('実施結果を残す');

        Http::assertNothingSent();
    }

    public function test_explicit_language_completion_records_one_idempotent_self_report_evidence_without_progress_mutation(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '英語 Shadowing',
            '英語音声をシャドーイングする',
            '音声に続いて発話する',
        );

        $requestId = (string) Str::uuid();
        $route = route(
            'plans.tasks.study_language.store',
            [
                $plan,
                $task,
                'activity' => 'shadowing',
            ],
        );
        $payload = [
            'request_uuid' => $requestId,
            'rounds' => 4,
            'outcome_rating' => 'partial',
            'reflection' => 'connected speechがまだ難しい',
        ];

        $this->actingAs($user)
            ->post($route, $payload)
            ->assertRedirect(route(
                'plans.tasks.study_language.show',
                [
                    $plan,
                    $task,
                    'activity' => 'shadowing',
                ],
            ));

        $this->actingAs($user)
            ->post($route, $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('task_evidences', 1);

        $evidence = TaskEvidence::query()->firstOrFail();

        $this->assertSame(
            'study_language_activity_completed',
            $evidence->type,
        );
        $this->assertSame(0.6, $evidence->confidence);
        $this->assertSame(
            'shadowing',
            data_get($evidence->metadata, 'activity_type'),
        );
        $this->assertSame(
            4,
            data_get($evidence->metadata, 'rounds'),
        );
        $this->assertSame(
            'partial',
            data_get($evidence->metadata, 'outcome_rating'),
        );
        $this->assertSame(
            'connected speechがまだ難しい',
            data_get($evidence->metadata, 'reflection'),
        );
        $this->assertSame(
            'study-language:shadowing:'.$requestId,
            $evidence->external_key,
        );

        $task->refresh();

        $this->assertSame(0, $task->progress_percent);
        $this->assertSame(60, $task->remaining_minutes);
        $this->assertSame('todo', $task->status);
    }

    public function test_language_evidence_adapter_normalizes_known_facts_but_not_reflection(): void
    {
        [, $plan, $task] = $this->scenario(
            'English',
            'リスニングをする',
            '音声を聞く',
        );

        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'source' => 'native',
            'type' => 'study_language_activity_completed',
            'external_key' => 'v5810-evidence',
            'confidence' => 0.6,
            'occurred_at' => now(),
            'metadata' => [
                'activity_type' => 'listening',
                'rounds' => 3,
                'outcome_rating' => 'comfortable',
                'resource_count' => 2,
                'reflection' => 'raw reflection must stay outside facts',
            ],
        ]);

        $observation = app(TaskEvidenceAdapter::class)
            ->adapt($evidence);

        $this->assertSame(
            'listening',
            $observation->facts['activity_type'],
        );
        $this->assertSame(
            3,
            $observation->facts['rounds'],
        );
        $this->assertSame(
            'comfortable',
            $observation->facts['outcome_rating'],
        );
        $this->assertArrayNotHasKey(
            'reflection',
            $observation->facts,
        );
        $this->assertArrayNotHasKey(
            'resource_count',
            $observation->facts,
        );
    }

    public function test_execution_ecosystem_maps_all_language_activities_to_native_study_language_capability(): void
    {
        $resolver = app(ExecutionCapabilityResolver::class);

        foreach ([
            [
                'title' => 'リスニングをする',
                'description' => '音声を聞いて内容を理解する',
            ],
            [
                'title' => 'ディクテーションをする',
                'description' => '聞き取って書く',
            ],
            [
                'title' => 'シャドーイングをする',
                'description' => '音声に続いて発話する',
            ],
        ] as $scenario) {
            [, $plan, $task] = $this->scenario(
                'English Study',
                $scenario['title'],
                $scenario['description'],
            );

            $this->assertSame(
                ExecutionCapability::STUDY_LANGUAGE,
                $resolver->forTask($plan, $task),
            );
        }

        $registry = app(ExecutionProviderRegistry::class);

        $this->assertSame(
            ['canovia.study.language'],
            $registry
                ->forCapability(
                    ExecutionCapability::STUDY_LANGUAGE,
                )
                ->pluck('key')
                ->all(),
        );
    }

    private function scenario(
        string $planTitle,
        string $taskTitle,
        string $taskDescription,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $planTitle,
            'description' => $planTitle.'の学習',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => $taskDescription,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
