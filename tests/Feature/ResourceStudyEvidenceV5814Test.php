<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Execution\ExecutionCapability;
use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Study\StudyActivityOutcomeObservationService;
use App\Intelligence\Study\StudyMethodRecommendationService;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\ExecutionCapabilityResolver;
use App\Services\ExecutionProviderRegistry;
use App\Services\StudyActivityPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResourceStudyEvidenceV5814Test extends TestCase
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

    public function test_resource_study_recommendation_routes_to_dedicated_execution_surface(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '簿記2級',
            '参考書の第3章を読む',
            '解説を読んで新しい論点を理解する',
        );

        $method = app(
            StudyMethodRecommendationService::class,
        )->recommend(
            $plan,
            $task,
            ['key' => 'certification_exam'],
            [
                'has_confirmed_scope' => true,
                'current_position_known' => true,
            ],
            null,
            $user->id,
            null,
            null,
        );

        $this->assertSame(
            StudyActivityPolicyService::RESOURCE_STUDY,
            data_get($method, 'primary.key'),
        );
        $this->assertSame(
            route(
                'plans.tasks.study_resource.show',
                [$plan, $task],
            ),
            data_get($method, 'primary.url'),
        );
        $this->assertSame(
            '教材学習を準備',
            data_get($method, 'primary.action_label'),
        );
    }

    public function test_task_linked_resources_are_preferred_over_unscoped_plan_resources(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $linked = $this->resource(
            $user,
            $plan,
            'Task教材',
            'https://example.com/task-resource',
        );
        $linked->tasks()->attach($task->id);

        $planLevel = $this->resource(
            $user,
            $plan,
            'Plan教材',
            'https://example.com/plan-resource',
        );

        Http::fake();

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_resource.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('Task教材')
            ->assertSee($linked->url, false)
            ->assertDontSee('Plan教材');

        Http::assertNothingSent();
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_unscoped_plan_resources_are_available_when_task_has_no_linked_resource(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $planLevel = $this->resource(
            $user,
            $plan,
            'Plan全体教材',
            'https://example.com/plan-level',
        );

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_resource.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('Plan全体教材')
            ->assertSee($planLevel->url, false);
    }

    public function test_other_task_only_resource_cannot_be_submitted(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $otherTask = $this->task(
            $plan,
            '別Task',
            '別の教材だけを使う',
            2,
        );
        $resource = $this->resource(
            $user,
            $plan,
            '別Task専用教材',
            'https://example.com/other-task',
        );
        $resource->tasks()->attach($otherTask->id);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_resource.store',
                [$plan, $task],
            ), [
                'request_uuid' => (string) Str::uuid(),
                'resource_id' => $resource->id,
                'outcome_rating' => 'partial',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_cross_plan_resource_cannot_be_submitted(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $otherPlan = $this->plan(
            $user,
            '別Plan',
        );
        $resource = $this->resource(
            $user,
            $otherPlan,
            '別Plan教材',
            'https://example.com/foreign',
        );

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_resource.store',
                [$plan, $task],
            ), [
                'request_uuid' => (string) Str::uuid(),
                'resource_id' => $resource->id,
                'outcome_rating' => 'partial',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_explicit_completion_records_idempotent_resource_study_evidence_without_progress_mutation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $resource = $this->resource(
            $user,
            $plan,
            'DNS教材',
            'https://example.com/dns',
        );

        $requestUuid = (string) Str::uuid();
        $payload = [
            'request_uuid' => $requestUuid,
            'resource_id' => $resource->id,
            'outcome_rating' => 'partial',
            'reflection' => 'MXとCNAMEは理解したがNS委任がまだ曖昧',
        ];

        $route = route(
            'plans.tasks.study_resource.store',
            [$plan, $task],
        );

        $this->actingAs($user)
            ->post($route, $payload)
            ->assertRedirect(route(
                'plans.tasks.study_resource.show',
                [$plan, $task],
            ));

        $this->actingAs($user)
            ->post($route, $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('task_evidences', 1);

        $evidence = TaskEvidence::query()->firstOrFail();

        $this->assertSame(
            'study_resource_study_completed',
            $evidence->type,
        );
        $this->assertSame(0.65, $evidence->confidence);
        $this->assertSame(
            $resource->id,
            data_get($evidence->metadata, 'resource_id'),
        );
        $this->assertSame(
            'DNS教材',
            data_get($evidence->metadata, 'resource_title'),
        );
        $this->assertSame(
            'partial',
            data_get($evidence->metadata, 'outcome_rating'),
        );
        $this->assertSame(
            'study-resource:'.$requestUuid,
            $evidence->external_key,
        );
        $this->assertSame('教材学習', $evidence->typeLabel());
        $this->assertStringContainsString(
            'DNS教材',
            $evidence->summary(),
        );
        $this->assertStringContainsString(
            '一部理解できた',
            $evidence->summary(),
        );

        $task->refresh();

        $this->assertSame(0, $task->progress_percent);
        $this->assertSame(60, $task->remaining_minutes);
        $this->assertSame('todo', $task->status);
    }

    public function test_resource_study_intelligence_normalization_excludes_title_url_and_reflection(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_resource_study_completed',
            'external_key' => 'resource-study-normalization',
            'confidence' => 0.65,
            'occurred_at' => now(),
            'metadata' => [
                'resource_id' => 42,
                'resource_title' => 'Private title',
                'resource_url' => 'https://example.com/private',
                'outcome_rating' => 'covered',
                'reflection' => 'private free text',
            ],
        ]);

        $observation = app(TaskEvidenceAdapter::class)
            ->adapt($evidence);

        $this->assertSame(
            42,
            $observation->facts['resource_id'],
        );
        $this->assertSame(
            'covered',
            $observation->facts['outcome_rating'],
        );
        $this->assertArrayNotHasKey(
            'resource_title',
            $observation->facts,
        );
        $this->assertArrayNotHasKey(
            'resource_url',
            $observation->facts,
        );
        $this->assertArrayNotHasKey(
            'reflection',
            $observation->facts,
        );
    }

    public function test_resource_study_is_observed_between_practice_assessments(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice(
            $task,
            $user,
            50,
            $base,
        );
        $this->resourceStudy(
            $task,
            $user,
            'partial',
            $base->copy()->addHours(2),
        );
        $this->resourceStudy(
            $task,
            $user,
            'covered',
            $base->copy()->addHours(4),
        );
        $this->practice(
            $task,
            $user,
            72,
            $base->copy()->addDay(),
        );

        $projection = app(
            StudyActivityOutcomeObservationService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );

        $resource = collect($projection['methods'])
            ->firstWhere('key', 'resource_study');

        $this->assertSame('observed', $resource['measurement_status']);
        $this->assertSame(2, $resource['usage_count']);
        $this->assertSame(1, $resource['observation_count']);
        $this->assertSame(50, $resource['average_before_score']);
        $this->assertSame(72, $resource['average_after_score']);
        $this->assertSame(22, $resource['average_score_delta']);
    }

    public function test_mixed_resource_study_and_recall_interval_is_excluded(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice($task, $user, 60, $base);
        $this->resourceStudy(
            $task,
            $user,
            'partial',
            $base->copy()->addHour(),
        );
        $this->recall(
            $task,
            $user,
            $base->copy()->addHours(2),
        );
        $this->practice(
            $task,
            $user,
            80,
            $base->copy()->addDay(),
        );

        $projection = app(
            StudyActivityOutcomeObservationService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );

        $this->assertSame(
            0,
            $projection['valid_observation_pair_count'],
        );
        $this->assertSame(
            1,
            $projection['ambiguous_interval_count'],
        );
    }

    public function test_resource_study_execution_ecosystem_remains_native_and_provider_free(): void
    {
        [$user, $plan, $task] = $this->scenario();

        Http::fake();

        $this->assertSame(
            ExecutionCapability::STUDY_RESOURCE,
            app(ExecutionCapabilityResolver::class)
                ->forTask($plan, $task),
        );

        $this->assertSame(
            ['canovia.study.resource'],
            app(ExecutionProviderRegistry::class)
                ->forCapability(
                    ExecutionCapability::STUDY_RESOURCE,
                )
                ->pluck('key')
                ->all(),
        );

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_resource.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('Resource Study')
            ->assertSee('Resourceを開いただけでは学習完了として扱いません');

        Http::assertNothingSent();
    }

    private function scenario(
        string $planTitle = '応用情報技術者試験',
        string $taskTitle = '参考書のネットワーク章を読む',
        string $taskDescription = '解説を読んでDNSを理解する',
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = $this->plan(
            $user,
            $planTitle,
        );

        $task = $this->task(
            $plan,
            $taskTitle,
            $taskDescription,
            1,
        );

        return [$user, $plan, $task];
    }

    private function plan(
        User $user,
        string $title,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の学習',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $description,
        int $sortOrder,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $description,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }

    private function resource(
        User $user,
        Plan $plan,
        string $title,
        string $url,
    ): PlanResource {
        return PlanResource::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'google_drive',
            'resource_type' => 'file',
            'title' => $title,
            'url' => $url,
        ]);
    }

    private function practice(
        Task $task,
        User $user,
        int $score,
        Carbon $occurredAt,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            $user,
            'study_practice_assessed',
            ['score_percent' => $score],
            $occurredAt,
            1.0,
        );
    }

    private function resourceStudy(
        Task $task,
        User $user,
        string $outcome,
        Carbon $occurredAt,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            $user,
            'study_resource_study_completed',
            [
                'resource_id' => 1,
                'outcome_rating' => $outcome,
            ],
            $occurredAt,
            0.65,
        );
    }

    private function recall(
        Task $task,
        User $user,
        Carbon $occurredAt,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            $user,
            'study_recall_reviewed',
            [
                'rating' => 'good',
                'interval_days' => 1,
            ],
            $occurredAt,
            0.75,
        );
    }

    private function evidence(
        Task $task,
        User $user,
        string $type,
        array $metadata,
        Carbon $occurredAt,
        float $confidence,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'actor_token' => null,
            'source' => EvidenceSource::Native->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'confidence' => $confidence,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata,
        ]);
    }
}
