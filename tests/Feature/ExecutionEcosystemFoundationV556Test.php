<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ExecutionProviderKind;
use App\Execution\ExecutionCapability;
use App\Models\ExecutionActivity;
use App\Models\Plan;
use App\Models\PlanExecutionPreference;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\ExecutionActivityService;
use App\Services\ExecutionCapabilityResolver;
use App\Services\ExecutionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionEcosystemFoundationV556Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_capability_resolver_reuses_existing_domain_policies(): void
    {
        $user = User::factory()->create();
        $resolver = app(ExecutionCapabilityResolver::class);

        $study = $this->plan($user, '応用情報', '資格学習');
        $practice = $this->task($study, 'ネットワーク過去問を10問解く');
        $this->assertSame(
            ExecutionCapability::STUDY_PRACTICE,
            $resolver->forTask($study, $practice),
        );

        $toeic = $this->plan($user, 'TOEIC対策', '資格学習');
        $recall = $this->task($toeic, '英単語と語彙を暗記する');
        $this->assertSame(
            ExecutionCapability::STUDY_RECALL,
            $resolver->forTask($toeic, $recall),
        );

        $resourcePlan = $this->plan($user, '資格テキスト学習', '資格学習');
        $resource = $this->task($resourcePlan, '参考書の解説を読む');
        $this->assertSame(
            ExecutionCapability::STUDY_RESOURCE,
            $resolver->forTask($resourcePlan, $resource),
        );

        $development = $this->plan($user, 'Canovia', '個人開発');
        $devTask = $this->task($development, 'APIを実装する');
        $this->assertSame(
            ExecutionCapability::CODING_REPOSITORY,
            $resolver->forTask($development, $devTask),
        );

        $career = $this->plan($user, '就職活動', '就活');
        $careerTask = $this->task($career, '企業研究を進める');
        $this->assertSame(
            ExecutionCapability::GENERAL_TASK,
            $resolver->forTask($career, $careerTask),
        );
    }

    public function test_resolver_prefers_plan_preference_then_native_default(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Canovia', '個人開発');
        $task = $this->task($plan, 'Repositoryで実装する');
        $resolver = app(ExecutionResolver::class);

        $default = $resolver->resolve($plan, $task);

        $this->assertSame(
            ExecutionCapability::CODING_REPOSITORY,
            $default->capability,
        );
        $this->assertSame('canovia.development', $default->provider?->key);
        $this->assertSame(
            ExecutionProviderKind::Native,
            $default->provider?->kind,
        );
        $this->assertSame('native_default', $default->source);

        PlanExecutionPreference::query()->create([
            'plan_id' => $plan->id,
            'capability' => ExecutionCapability::CODING_REPOSITORY,
            'provider_key' => 'github',
            'user_selected' => true,
        ]);

        $preferred = $resolver->resolve($plan, $task);

        $this->assertSame('github', $preferred->provider?->key);
        $this->assertSame(
            ExecutionProviderKind::External,
            $preferred->provider?->kind,
        );
        $this->assertSame('plan_preference', $preferred->source);

        PlanExecutionPreference::query()
            ->where('plan_id', $plan->id)
            ->where('capability', ExecutionCapability::CODING_REPOSITORY)
            ->update(['provider_key' => 'retired-provider']);

        $fallback = $resolver->resolve($plan, $task);

        $this->assertSame('canovia.development', $fallback->provider?->key);
        $this->assertSame('native_default', $fallback->source);
    }

    public function test_external_activity_can_exist_unbound_then_projects_to_task_evidence(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Canovia', '個人開発');
        $task = $this->task($plan, 'Repository連携を実装する', progress: 15);
        $activities = app(ExecutionActivityService::class);

        $first = $activities->record(
            providerKey: 'github',
            capability: ExecutionCapability::CODING_REPOSITORY,
            type: 'repository_session',
            title: 'Repositoryで実装',
            status: 'completed',
            metrics: ['commits' => 2],
            metadata: ['provider_trace' => 'kept-outside-intelligence'],
            externalKey: 'session-001',
            userId: $user->id,
            startedAt: now()->subMinutes(20),
            completedAt: now(),
            durationSeconds: 1200,
        );

        $same = $activities->record(
            providerKey: 'github',
            capability: ExecutionCapability::CODING_REPOSITORY,
            type: 'repository_session',
            title: 'Repositoryで実装',
            status: 'completed',
            metrics: ['commits' => 3],
            metadata: ['provider_trace' => 'updated'],
            externalKey: 'session-001',
            userId: $user->id,
            startedAt: now()->subMinutes(20),
            completedAt: now(),
            durationSeconds: 1200,
        );

        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('execution_activities', 1);
        $this->assertNull($same->plan_id);
        $this->assertNull($same->task_id);
        $this->assertNull($same->task_evidence_id);

        $linked = $activities->linkToTask($same, $task);

        $this->assertSame($plan->id, (int) $linked->plan_id);
        $this->assertSame($task->id, (int) $linked->task_id);
        $this->assertNotNull($linked->task_evidence_id);
        $this->assertNotNull($linked->linked_at);

        $evidence = TaskEvidence::query()->findOrFail(
            $linked->task_evidence_id,
        );

        $this->assertSame(EvidenceSource::External, $evidence->source);
        $this->assertSame('execution_activity_observed', $evidence->type);
        $this->assertSame(
            'github',
            data_get($evidence->metadata, 'provider_key'),
        );
        $this->assertSame(
            ExecutionCapability::CODING_REPOSITORY,
            data_get($evidence->metadata, 'capability'),
        );
        $this->assertSame(
            3,
            data_get($evidence->metadata, 'metrics.commits'),
        );
        $this->assertArrayNotHasKey(
            'provider_trace',
            (array) $evidence->metadata,
        );

        $this->assertSame(15, (int) $task->fresh()->progress_percent);

        $linkedAgain = $activities->linkToTask($linked, $task);

        $this->assertSame(
            $linked->task_evidence_id,
            $linkedAgain->task_evidence_id,
        );
        $this->assertDatabaseCount('task_evidences', 1);
    }

    public function test_plan_and_task_expose_execution_foundation_relationships(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Study', '資格学習');
        $task = $this->task($plan, '過去問を解く');

        $preference = PlanExecutionPreference::query()->create([
            'plan_id' => $plan->id,
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'provider_key' => 'canovia.study.practice',
            'user_selected' => true,
        ]);

        $activity = ExecutionActivity::query()->create([
            'user_id' => $user->id,
            'provider_key' => 'canovia.study.practice',
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'type' => 'question_practice',
            'title' => '問題演習',
            'status' => 'started',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
        ]);

        $this->assertSame(
            $preference->id,
            $plan->executionPreferences()->firstOrFail()->id,
        );
        $this->assertSame(
            $activity->id,
            $plan->executionActivities()->firstOrFail()->id,
        );
        $this->assertSame(
            $activity->id,
            $task->executionActivities()->firstOrFail()->id,
        );
    }

    private function plan(
        User $user,
        string $title,
        string $category,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        int $progress = 0,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => $progress,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
