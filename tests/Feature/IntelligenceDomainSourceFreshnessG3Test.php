<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Services\ActionProjectionStore;
use App\Intelligence\Services\IntelligenceEvidenceFreshnessGuard;
use App\Intelligence\Services\StateSnapshotStore;
use App\Models\CareerApplication;
use App\Models\CareerCapture;
use App\Models\CareerSelectionEvent;
use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;
use App\Models\IntelligenceStateSnapshot;
use App\Models\InterviewReview;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IntelligenceDomainSourceFreshnessG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_career_capture_correction_invalidates_saved_state_decision_and_action_without_erasure(): void
    {
        [$user, $plan] = $this->scenario('就活・キャリア');
        $capture = CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'manual',
            'status' => 'pending',
            'raw_text' => 'private company and interview notes',
            'captured_at' => now(),
        ]);

        $this->persistCareer($plan);
        $request = $this->request($user);
        $store = app(StateSnapshotStore::class);
        $history = app(IntelligencePresentationHistoryService::class);
        $this->assertNotNull($store->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
        $this->assertCount(1, $history->forPlan(
            $request, $plan, IntelligenceDomain::Career,
        )['decisions']);

        $capture->update(['status' => 'ignored']);

        $this->assertNull($store->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
        $visible = $history->forPlan($request, $plan, IntelligenceDomain::Career);
        $this->assertCount(0, $visible['states']);
        $this->assertCount(0, $visible['decisions']);
        $this->assertCount(0, $visible['actions']);
        $this->assertNull(app(ActionProjectionStore::class)->latestActiveForPlan($plan->id));
        $this->assertSame(1, IntelligenceStateSnapshot::count());
        $this->assertSame(1, IntelligenceDecisionTrace::count());
        $this->assertSame(1, IntelligenceActionProjection::count());
    }

    public function test_new_career_application_invalidates_empty_state_even_without_task_evidence(): void
    {
        [$user, $plan] = $this->scenario('就活・キャリア');
        $this->persistCareer($plan);
        $request = $this->request($user);

        $state = app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        );
        $this->assertNotNull($state);
        $this->assertSame([], $state->evidence_references);

        $application = $this->application($plan);
        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
        $this->assertSame(1, IntelligenceStateSnapshot::count());

        $this->persistCareer($plan);
        $this->assertNotNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
        $this->assertSame(2, IntelligenceStateSnapshot::count());
        $application->delete();
        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
    }

    public function test_career_selection_event_and_review_correction_invalidates_without_task_evidence(): void
    {
        [$user, $plan] = $this->scenario('就活・キャリア');
        $application = $this->application($plan);
        $event = CareerSelectionEvent::query()->create([
            'career_application_id' => $application->id,
            'type' => 'interview',
            'stage' => 'interview',
            'status' => 'result_waiting',
            'scheduled_at' => now()->subDay(),
        ]);

        $this->persistCareer($plan);
        $request = $this->request($user);
        $this->assertNotNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));

        $review = InterviewReview::query()->create([
            'plan_id' => $plan->id,
            'career_application_id' => $application->id,
            'career_selection_event_id' => $event->id,
            'status' => InterviewReview::STATUS_COMPLETED,
            'insights' => ['secret' => 'no output should contain this'],
            'completed_at' => now(),
        ]);
        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));

        $this->persistCareer($plan);
        $this->assertNotNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));

        $review->update(['status' => InterviewReview::STATUS_DRAFT]);
        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
        $review->delete();
        $event->update(['status' => 'cancelled']);
        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Career, 'career_plan',
        ));
    }

    public function test_development_new_evidence_invalidates_empty_evidence_state_before_refresh(): void
    {
        [$user, $plan] = $this->scenario('個人開発');
        app(DevelopmentAdaptiveActionService::class)->refresh($plan, now());
        $request = $this->request($user);
        $state = app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Development, 'development_plan',
        );
        $this->assertNotNull($state);
        $this->assertSame([], $state->evidence_references);

        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'GitHub issue',
            'status' => 'todo', 'priority' => 1,
            'estimated_minutes' => 30, 'remaining_minutes' => 30,
            'progress_percent' => 0,
        ]);
        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'github_issue_observed',
            'external_key' => 'g3:issue:1',
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => ['issue_number' => 1, 'issue_state' => 'open'],
        ]);

        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $request, $plan, IntelligenceDomain::Development, 'development_plan',
        ));
        $this->assertSame(1, IntelligenceStateSnapshot::count());
    }

    public function test_plan_owner_transfer_masks_old_career_derivation_for_new_owner(): void
    {
        [$owner, $plan] = $this->scenario('就活・キャリア');
        $this->application($plan);
        $this->persistCareer($plan);
        $newOwner = User::factory()->create();
        $plan->update(['user_id' => $newOwner->id]);

        $state = IntelligenceStateSnapshot::firstOrFail();
        $this->assertFalse(app(IntelligenceEvidenceFreshnessGuard::class)->isCurrent($state));
        $this->assertNull(app(StateSnapshotStore::class)->latestForPlan(
            $this->request($newOwner), $plan, IntelligenceDomain::Career, 'career_plan',
        ));
        $this->assertSame(1, IntelligenceStateSnapshot::count());
    }

    private function persistCareer(Plan $plan): void
    {
        app(CareerAdaptiveActionService::class)->refresh($plan, now());
    }

    private function application(Plan $plan): CareerApplication
    {
        return CareerApplication::query()->create([
            'plan_id' => $plan->id,
            'stage' => 'candidate',
            'status' => 'active',
            'company_name' => 'Private employer',
        ]);
    }

    private function request(User $user): Request
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    private function scenario(string $category): array
    {
        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'G3 protected Plan',
            'category' => $category,
            'priority' => 1,
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        return [$user, $plan];
    }
}
