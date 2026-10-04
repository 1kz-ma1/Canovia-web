<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Enums\ReadinessLevel;
use App\Intelligence\Study\StudyPlanIntelligenceService;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\PlanToolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyIntelligenceV535Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-04 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_confirmed_scope_and_evidence_produce_conservative_exam_readiness(): void
    {
        [$user, $plan] = $this->studyPlan('定期テスト学習', '2026-10-10');

        [$mathCapture, $mathScope] = $this->scope(
            $plan,
            subject: '数学',
            unit: '二次関数',
            range: '教科書 42〜68ページ',
            examDate: '2026-10-10',
        );
        [, $englishScope] = $this->scope(
            $plan,
            subject: '英語',
            unit: 'Lesson 4',
            range: '教科書 Lesson 4',
            examDate: '2026-10-10',
        );

        $mathTask = $this->task(
            $plan,
            '二次関数を演習する',
            progress: 99,
        );
        $englishTask = $this->task(
            $plan,
            'Lesson 4を復習する',
            progress: 0,
        );

        $this->practiceEvidence(
            $mathTask,
            score: 90,
            strengths: ['二次関数'],
        );
        $this->recallEvidence(
            $mathTask,
            rating: 'good',
            mastered: false,
        );

        $service = app(StudyPlanIntelligenceService::class);
        $partial = $service->evaluate(
            $plan,
            new \DateTimeImmutable('2026-10-04T12:00:00+09:00'),
        );

        $this->assertSame(2, $partial->state->metrics['confirmed_scope_count']);
        $this->assertSame(1, $partial->state->metrics['observed_scope_count']);
        $this->assertSame(50, $partial->state->metrics['coverage_percent']);
        $this->assertSame(90, $partial->state->metrics['mastery_score_percent']);
        $this->assertSame(80, $partial->state->metrics['retention_score_percent']);
        $this->assertNull($partial->state->metrics['speed_score_percent']);
        $this->assertSame('unmeasured', $partial->state->facts['speed_status']);
        $this->assertSame(1.1, $partial->state->metrics['remaining_effort_units']);
        $this->assertSame(55, $partial->state->metrics['remaining_effort_percent']);
        $this->assertSame(6, $partial->state->metrics['days_until_exam']);
        $this->assertSame('low', $partial->state->facts['deadline_pressure']);
        $this->assertSame(74, $partial->readiness->score);
        $this->assertSame(ReadinessLevel::Developing, $partial->readiness->level);

        $gapCodes = collect($partial->readiness->gaps)
            ->pluck('code')
            ->all();
        $this->assertContains('coverage_below_target', $gapCodes);
        $this->assertContains('speed_unmeasured', $gapCodes);

        // Task progress is intentionally not State truth.
        $this->assertArrayNotHasKey('progress_percent', $partial->state->metrics);
        $this->assertArrayNotHasKey('task_progress_percent', $partial->state->facts);

        $this->practiceEvidence(
            $englishTask,
            score: 85,
            strengths: ['Lesson 4'],
        );
        $this->recallEvidence(
            $englishTask,
            rating: 'easy',
            mastered: false,
        );

        $ready = $service->evaluate(
            $plan,
            new \DateTimeImmutable('2026-10-04T12:05:00+09:00'),
        );

        $this->assertSame(100, $ready->state->metrics['coverage_percent']);
        $this->assertSame(88, $ready->state->metrics['mastery_score_percent']);
        $this->assertSame(88, $ready->state->metrics['retention_score_percent']);
        $this->assertSame(0.2, $ready->state->metrics['remaining_effort_units']);
        $this->assertSame(10, $ready->state->metrics['remaining_effort_percent']);
        $this->assertSame(92, $ready->readiness->score);
        $this->assertSame(ReadinessLevel::Ready, $ready->readiness->level);

        $priority = collect($ready->state->facts['priority_remaining_scope']);
        $this->assertSame(
            collect([$mathScope->id, $englishScope->id])->sort()->values()->all(),
            $priority->pluck('scope_item_id')->sort()->values()->all(),
        );

        $this->assertSame($mathCapture->id, $mathScope->study_scope_capture_id);
    }

    public function test_scope_matching_does_not_spread_one_subject_task_across_multiple_units(): void
    {
        [, $plan] = $this->studyPlan('学校の試験勉強', '2026-10-20');

        $this->scope($plan, '数学', '二次関数', '第3章', '2026-10-20');
        $this->scope($plan, '数学', '図形', '第4章', '2026-10-20');

        $genericMathTask = $this->task($plan, '数学を勉強する', progress: 80);
        $this->practiceEvidence(
            $genericMathTask,
            score: 95,
            strengths: ['数学'],
        );

        $result = app(StudyPlanIntelligenceService::class)->evaluate(
            $plan,
            new \DateTimeImmutable('2026-10-04T12:00:00+09:00'),
        );

        $this->assertSame(0, $result->state->metrics['observed_scope_count']);
        $this->assertSame(0, $result->state->metrics['coverage_percent']);
        $this->assertNull($result->state->metrics['mastery_score_percent']);
        $this->assertSame(2.0, $result->state->metrics['remaining_effort_units']);

        // Subject-only evidence is ambiguous when multiple units share a subject.
        foreach ($result->state->facts['scope_item_states'] as $state) {
            $this->assertFalse($state['observed']);
            $this->assertSame([], $state['task_ids']);
        }
    }

    public function test_conflicting_confirmed_exam_dates_are_exposed_instead_of_guessed(): void
    {
        [, $plan] = $this->studyPlan('大学試験学習', null);

        $this->scope($plan, '統計学', '推定', 'Chapter 2', '2026-10-18');
        $this->scope($plan, '統計学', '検定', 'Chapter 3', '2026-10-25');

        $result = app(StudyPlanIntelligenceService::class)->evaluate(
            $plan,
            new \DateTimeImmutable('2026-10-04T12:00:00+09:00'),
        );

        $this->assertTrue($result->state->facts['exam_date_conflict']);
        $this->assertNull($result->state->facts['exam_date']);
        $this->assertNull($result->state->metrics['days_until_exam']);
        $this->assertSame(
            'conflicting_scope_captures',
            $result->state->facts['exam_date_source'],
        );

        $this->assertContains(
            'exam_date_conflict',
            collect($result->readiness->gaps)->pluck('code')->all(),
        );
    }

    public function test_duplicate_confirmed_scope_is_deduplicated_and_snapshot_can_be_persisted(): void
    {
        [$user, $plan] = $this->studyPlan('テスト勉強', '2026-10-12');

        $this->scope($plan, '理科', '電流', '教科書 10〜20', '2026-10-12');
        $this->scope($plan, '理科', '電流', '教科書 10〜20', '2026-10-12');

        $result = app(StudyPlanIntelligenceService::class)->persistSnapshot(
            $plan,
            new \DateTimeImmutable('2026-10-04T12:00:00+09:00'),
        );

        $this->assertSame(1, $result->state->metrics['confirmed_scope_count']);
        $this->assertNotNull($result->persistedSnapshot);
        $this->assertSame('study_plan', $result->persistedSnapshot->scope_type);
        $this->assertSame((string) $plan->id, $result->persistedSnapshot->scope_id);
        $this->assertSame('53.5', data_get(
            $result->persistedSnapshot->metadata,
            'builder_version',
        ));
        $this->assertSame($user->id, $result->persistedSnapshot->user_id);
        $this->assertDatabaseCount('intelligence_state_snapshots', 1);
    }

    public function test_ordinary_school_study_plan_receives_study_tools(): void
    {
        [$user, $plan] = $this->studyPlan('定期テスト学習', '2026-10-15');
        $task = $this->task($plan, '二次関数の問題演習');

        $plan->load(['resources', 'tasks.resources', 'tasks.artifacts']);
        $task = $plan->tasks->firstWhere('id', $task->id);

        $tools = collect(app(PlanToolService::class)->forTask(
            $plan,
            $task,
            true,
            $user,
        ));

        $this->assertTrue($tools->contains(
            fn (array $tool) => ($tool['id'] ?? null) === 'ai_practice',
        ));

        $this->actingAs($user)
            ->get(route('plans.tasks.study_activity.show', [$plan, $task]))
            ->assertOk();
    }

    private function studyPlan(
        string $category,
        ?string $deadline,
    ): array {
        $user = User::factory()->create();

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Study Intelligence Test',
            'category' => $category,
            'start_date' => '2026-10-01',
            'deadline' => $deadline,
            'is_public' => false,
        ]);

        return [$user, $plan];
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
            'status' => $progress > 0 ? 'doing' : 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function scope(
        Plan $plan,
        string $subject,
        ?string $unit,
        ?string $range,
        ?string $examDate,
    ): array {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => 'テスト',
            'exam_date' => $examDate,
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        $item = StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => $subject,
            'unit' => $unit,
            'range_text' => $range,
            'confidence' => 1,
            'sort_order' => 0,
        ]);

        return [$capture, $item];
    }

    private function practiceEvidence(
        Task $task,
        int $score,
        array $strengths = [],
        array $weaknesses = [],
        array $weaknessTopics = [],
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'practice:'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'score_percent' => $score,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'weakness_topics' => $weaknessTopics,
                'provider_payload' => ['must' => 'stay outside intelligence'],
            ],
        ]);
    }

    private function recallEvidence(
        Task $task,
        string $rating,
        bool $mastered,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_recall_reviewed',
            'external_key' => 'recall:'.Str::uuid(),
            'confidence' => 0.75,
            'occurred_at' => now()->addMinute(),
            'metadata' => [
                'rating' => $rating,
                'repetitions' => $mastered ? 3 : 1,
                'lapse_count' => 0,
                'interval_days' => $mastered ? 7 : 3,
                'mastered' => $mastered,
                'prompt' => 'raw prompt must not become Intelligence facts',
            ],
        ]);
    }
}
