<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Study\StudyActionTaskProjector;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\IntelligenceActionProjection;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class StudyActionCommitFenceG3Test extends TestCase
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

    public function test_stale_scope_correction_denies_study_task_creation_after_action_refresh(): void
    {
        [$owner, $plan, $scope] = $this->scenario();
        $result = app(StudyAdaptiveActionService::class)->refresh($plan, now());
        $this->assertSame('project_task',
            data_get($result->primaryAction()?->metadata, 'route_kind'));

        $scope->update(['unit' => 'Lesson 5']);

        $this->assertDenied(409, fn () => $this->project($plan, $result, $owner));
        $this->assertDatabaseCount('tasks', 0);
        $this->assertNull($result->projection->fresh()->projected_task_id);
        $this->assertDatabaseCount('plan_activity_logs', 0);
    }

    public function test_new_practice_evidence_and_target_task_reassignment_deny_stale_action(): void
    {
        [$owner, $plan] = $this->scenario();
        $result = app(StudyAdaptiveActionService::class)->refresh($plan, now());
        $task = $plan->tasks()->create([
            'title' => 'new externally recorded practice',
            'status' => 'todo',
            'priority' => 3,
            'estimated_minutes' => 20,
            'remaining_minutes' => 20,
            'progress_percent' => 0,
            'sort_order' => 1,
        ]);
        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $owner->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'g3:study:1',
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => ['score_percent' => 30],
        ]);

        $this->assertDenied(409, fn () => $this->project($plan, $result, $owner));
        $this->assertDatabaseCount('tasks', 1);
        $this->assertNull($result->projection->fresh()->projected_task_id);
    }

    public function test_plan_transfer_and_editor_removal_prevent_task_commit(): void
    {
        [$owner, $plan] = $this->scenario();
        $result = app(StudyAdaptiveActionService::class)->refresh($plan, now());
        $plan->update(['user_id' => User::factory()->create()->id]);

        $this->assertDenied(403, fn () => $this->project($plan, $result, $owner));
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_removed_collaborative_editor_cannot_commit_prior_action(): void
    {
        [$owner, $plan] = $this->scenario();
        $plan->update(['is_collaborative' => true]);
        $editor = User::factory()->create();
        $membership = PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);
        $result = app(StudyAdaptiveActionService::class)->refresh($plan, now());
        $membership->delete();

        $this->assertDenied(403, fn () => $this->project($plan, $result, $editor));
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_active_verified_action_creates_once_and_stale_replay_cannot_create_duplicate(): void
    {
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => true]);
        [$owner, $plan] = $this->scenario();
        $result = app(StudyAdaptiveActionService::class)->refresh($plan, now());

        $first = $this->project($plan, $result, $owner);
        $this->assertTrue($first['created']);
        $this->assertDatabaseHas('learning_context_source_epochs', [
            'plan_id' => $plan->id, 'epoch' => 1, 'last_mutation_kind' => 'task_created',
        ]);
        $this->assertSame($first['task']->id,
            $result->projection->fresh()->projected_task_id);

        // The new Task itself alters the Study state, invalidating replay of
        // this pre-creation evaluation, even from a second browser tab.
        $this->assertDenied(409, fn () => $this->project($plan, $result, $owner));
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('learning_context_source_epochs', [
            'plan_id' => $plan->id, 'epoch' => 1,
        ]);
        $this->assertDatabaseCount('task_dependencies', 0);
        $this->assertSame(1, IntelligenceActionProjection::count());
    }

    private function project(Plan $plan, mixed $result, User $user): array
    {
        return app(StudyActionTaskProjector::class)->project(
            $plan, $result, $this->actorRequest($user),
        );
    }

    private function actorRequest(User $user): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function assertDenied(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Stale Study Action must not mutate a Task.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'G3 Study Action',
            'category' => '定期テスト学習',
            'priority' => 1,
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);

        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $owner->id,
            'status' => 'confirmed',
            'exam_title' => 'Practice Exam',
            'exam_date' => today()->addWeeks(3),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);
        $scope = StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => 'English',
            'unit' => 'Lesson 4',
            'range_text' => 'Lesson 4 grammar',
            'confidence' => 1,
            'sort_order' => 0,
        ]);

        return [$owner, $plan, $scope];
    }
}
