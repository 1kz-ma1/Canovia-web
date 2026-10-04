<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Intelligence\Career\CareerPlanIntelligenceService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CareerIntelligenceV550Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-10-04 21:00:00');

        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_empty_career_state_has_no_numeric_score_and_only_requests_real_signal(): void
    {
        [$user, $plan] = $this->scenario();

        $result = app(CareerAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertSame(
            IntelligenceDomain::Career,
            $result->intelligence->state->domain,
        );
        $this->assertNull($result->intelligence->readiness->score);
        $this->assertSame(
            ReadinessLevel::Unknown,
            $result->intelligence->readiness->level,
        );
        $this->assertSame(
            'career_process_observability_not_employability',
            data_get(
                $result->intelligence->readiness->metadata,
                'meaning',
            ),
        );
        $this->assertSame(
            'capture_career_signal',
            $result->decision->type,
        );
        $this->assertSame(
            '求人・応募情報を1つ残す',
            $result->primaryAction()?->title,
        );
    }

    public function test_pending_capture_becomes_process_gap_without_copying_raw_career_content(): void
    {
        [$user, $plan] = $this->scenario();

        CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'source_url' => 'https://private.example/jobs/secret',
            'raw_text' => '秘密企業株式会社 年収1000万円 特別求人',
            'extracted_data' => [
                'company_name' => '秘密企業株式会社',
                'role_title' => '秘密の役職',
            ],
            'confidence' => 1,
            'captured_at' => now(),
        ]);

        $result = app(CareerAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertNull($result->intelligence->readiness->score);
        $this->assertSame(
            ReadinessLevel::Developing,
            $result->intelligence->readiness->level,
        );
        $this->assertSame(
            'organize_capture',
            $result->decision->type,
        );

        $serialized = json_encode(
            [
                $result->intelligence->state->metrics,
                $result->intelligence->state->facts,
                $result->decision->metadata,
                $result->primaryAction()?->metadata,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertIsString($serialized);
        $this->assertStringNotContainsString(
            '秘密企業株式会社',
            $serialized,
        );
        $this->assertStringNotContainsString(
            '秘密の役職',
            $serialized,
        );
        $this->assertStringNotContainsString(
            '年収1000万円',
            $serialized,
        );
        $this->assertStringNotContainsString(
            'private.example',
            $serialized,
        );
    }

    public function test_result_waiting_interview_without_review_prioritizes_review_then_stops_after_review_completion(): void
    {
        [$user, $plan] = $this->scenario();
        $task = $this->task($plan, '一次面接');
        $application = $this->application(
            $plan,
            'Example株式会社',
            'interview',
            'waiting',
        );
        $event = CareerSelectionEvent::query()->create([
            'career_application_id' => $application->id,
            'task_id' => $task->id,
            'type' => 'interview',
            'stage' => 'interview',
            'status' => 'result_waiting',
            'scheduled_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);

        $before = app(CareerAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertSame(
            'complete_interview_review',
            $before->decision->type,
        );
        $this->assertSame(
            $event->id,
            data_get(
                $before->decision->metadata,
                'target_selection_event_id',
            ),
        );

        InterviewReview::query()->create([
            'plan_id' => $plan->id,
            'career_application_id' => $application->id,
            'career_selection_event_id' => $event->id,
            'status' => InterviewReview::STATUS_COMPLETED,
            'context_snapshot' => [
                'company_name' => 'Example株式会社',
            ],
            'insights' => [
                'next_focus' => '秘密の面接回答',
            ],
            'completed_at' => now(),
        ]);

        $after = app(CareerAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertNotSame(
            'complete_interview_review',
            $after->decision->type,
        );
        $this->assertSame(
            'review_pipeline',
            $after->decision->type,
        );
    }

    public function test_upcoming_interview_is_prioritized_without_predicting_outcome(): void
    {
        [, $plan] = $this->scenario();
        $application = $this->application(
            $plan,
            'A社',
            'interview',
            'active',
        );
        $event = CareerSelectionEvent::query()->create([
            'career_application_id' => $application->id,
            'type' => 'interview',
            'stage' => 'interview',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHours(24),
        ]);

        $result = app(CareerAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertSame('prepare_interview', $result->decision->type);
        $this->assertSame(
            'interview_due_soon',
            $result->decision->reasonCode,
        );
        $this->assertSame(
            $event->id,
            data_get(
                $result->primaryAction()?->metadata,
                'target_selection_event_id',
            ),
        );
        $this->assertNull($result->intelligence->readiness->score);
        $this->assertStringNotContainsString(
            '確率',
            (string) $result->primaryAction()?->intent,
        );
    }

    public function test_offer_action_only_organizes_conditions_and_does_not_accept_or_reject_for_user(): void
    {
        [, $plan] = $this->scenario();
        $application = $this->application(
            $plan,
            'Offer株式会社',
            'offer',
            'active',
            'offer',
        );

        $result = app(CareerAdaptiveActionService::class)
            ->evaluate($plan);

        $this->assertSame(
            'review_offer_conditions',
            $result->decision->type,
        );
        $this->assertSame(
            $application->id,
            data_get(
                $result->decision->metadata,
                'target_application_id',
            ),
        );
        $this->assertSame(
            'オファー条件を整理する',
            $result->primaryAction()?->title,
        );
        $this->assertStringContainsString(
            'Canoviaが決めません',
            (string) $result->primaryAction()?->intent,
        );
        $this->assertStringNotContainsString(
            '承諾する',
            (string) $result->primaryAction()?->title,
        );
        $this->assertStringNotContainsString(
            '辞退する',
            (string) $result->primaryAction()?->title,
        );
    }

    public function test_refresh_persists_generic_intelligence_history_without_sensitive_career_text(): void
    {
        [$user, $plan] = $this->scenario();
        $capture = CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'manual',
            'status' => 'pending',
            'raw_text' => '極秘会社の求人メモ',
            'confidence' => 1,
            'captured_at' => now(),
        ]);

        $result = app(CareerAdaptiveActionService::class)
            ->refresh($plan);

        $this->assertNotNull($result->decisionTrace);
        $this->assertNotNull($result->projection);
        $this->assertSame(
            IntelligenceDomain::Career,
            $result->decisionTrace?->domain,
        );
        $this->assertSame(
            IntelligenceDomain::Career,
            $result->projection?->domain,
        );
        $this->assertNull($result->decisionTrace?->readiness_score);
        $this->assertSame(
            $capture->id,
            data_get(
                $result->decisionTrace?->decision_metadata,
                'target_capture_id',
            ),
        );

        $snapshot = IntelligenceStateSnapshot::query()
            ->where('plan_id', $plan->id)
            ->where('domain', IntelligenceDomain::Career->value)
            ->latest('id')
            ->firstOrFail();

        $serialized = json_encode(
            [
                $snapshot->metrics,
                $snapshot->facts,
                $snapshot->evidence_references,
                $result->decisionTrace?->decision_metadata,
                $result->projection?->metadata,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertStringNotContainsString(
            '極秘会社',
            (string) $serialized,
        );
        $this->assertDatabaseCount('intelligence_state_snapshots', 1);
        $this->assertDatabaseCount('intelligence_decision_traces', 1);
        $this->assertDatabaseCount('intelligence_action_projections', 1);
    }

    public function test_career_task_evidence_adapter_drops_company_role_and_answer_text(): void
    {
        [$user, $plan] = $this->scenario();
        $task = $this->task($plan, '面接');
        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'interview_review_completed',
            'external_key' => 'review:1',
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'career_application_id' => 10,
                'career_selection_event_id' => 20,
                'interview_review_id' => 30,
                'company_name' => '秘密会社',
                'role_title' => '秘密役職',
                'stage' => 'interview',
                'best_moment' => '秘密回答A',
                'difficult_moment' => '秘密回答B',
                'next_focus' => '秘密回答C',
            ],
        ]);

        $observation = app(TaskEvidenceAdapter::class)
            ->adapt($evidence);

        $this->assertSame(10, $observation->facts['career_application_id']);
        $this->assertSame(20, $observation->facts['career_selection_event_id']);
        $this->assertSame(30, $observation->facts['interview_review_id']);
        $this->assertSame('interview', $observation->facts['stage']);
        $this->assertArrayNotHasKey('company_name', $observation->facts);
        $this->assertArrayNotHasKey('role_title', $observation->facts);
        $this->assertArrayNotHasKey('best_moment', $observation->facts);
        $this->assertArrayNotHasKey('difficult_moment', $observation->facts);
        $this->assertArrayNotHasKey('next_focus', $observation->facts);
    }

    public function test_career_capture_mutation_refreshes_intelligence_but_get_does_not_add_history(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->post(route('plans.career.captures.store', $plan), [
                'source_type' => 'url',
                'source_url' => 'https://example.com/jobs/1',
                'note' => '非公開求人メモ',
            ])
            ->assertRedirect(route('plans.career.index', $plan));

        $this->assertSame(
            1,
            IntelligenceDecisionTrace::query()
                ->where('plan_id', $plan->id)
                ->where('domain', IntelligenceDomain::Career->value)
                ->count(),
        );

        $before = IntelligenceDecisionTrace::count();

        $this->actingAs($user)
            ->get(route('plans.career.index', $plan))
            ->assertOk();

        $this->assertSame(
            $before,
            IntelligenceDecisionTrace::count(),
        );
    }

    public function test_career_intelligence_rejects_non_career_plan(): void
    {
        [$user] = $this->scenario();
        $plan = $this->plan($user, '資格学習');

        $this->expectException(InvalidArgumentException::class);

        app(CareerPlanIntelligenceService::class)
            ->evaluate($plan);
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user);

        return [$user, $plan];
    }

    private function plan(
        User $user,
        string $category = '就活・キャリア',
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'エンジニア就活',
            'description' => 'Career Intelligence test',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonths(3),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'Career task',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function application(
        Plan $plan,
        string $company,
        string $stage,
        string $status,
        ?string $result = null,
    ): CareerApplication {
        return CareerApplication::query()->create([
            'plan_id' => $plan->id,
            'company_name' => $company,
            'role_title' => 'エンジニア',
            'stage' => $stage,
            'status' => $status,
            'result' => $result,
        ]);
    }
}
