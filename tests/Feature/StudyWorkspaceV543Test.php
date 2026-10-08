<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyWorkspaceV543Test extends TestCase
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

    public function test_study_workspace_is_valid_even_without_a_study_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.study.index'))
            ->assertOk()
            ->assertSee('data-study-workspace', false)
            ->assertSee('data-study-workspace-no-plan', false)
            ->assertSee('学習Planを作る')
            ->assertSee(route('plans.create'), false)
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-workspace-mode-source="route_hint"', false);
    }

    public function test_workspace_selects_highest_priority_study_plan_and_preserves_explicit_deep_link(): void
    {
        $user = User::factory()->create();

        $lower = $this->plan(
            $user,
            '英語テスト',
            '定期テスト学習',
            priority: 4,
            deadline: today()->addDays(5),
        );
        $primary = $this->plan(
            $user,
            'AP対策',
            '資格学習',
            priority: 1,
            deadline: today()->addDays(20),
        );
        $this->plan(
            $user,
            'Canovia開発',
            '個人開発',
            priority: 1,
            deadline: today()->addDay(),
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index'))
            ->assertOk()
            ->assertSee($primary->title)
            ->assertDontSee($lower->title)
            ->assertSee('data-study-top-link', false)
            ->assertSee(route('workspace.study.top'), false)
            ->assertDontSee('id="study-workspace-plan"', false);

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $lower->id]))
            ->assertOk()
            ->assertSee($lower->title)
            ->assertDontSee($primary->title)
            ->assertSee('data-study-top-link', false)
            ->assertDontSee('id="study-workspace-plan"', false);
    }

    public function test_workspace_is_state_first_before_confirmed_scope_and_does_not_create_task(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-workspace-composed', false)
            ->assertSee('data-study-learning-type="certification_exam"', false)
            ->assertSee('data-study-workspace-missing-context="baseline"', false)
            ->assertSee('まず現在地を1回だけ測ります')
            ->assertDontSee('data-study-workspace-capture-first', false)
            ->assertDontSee('data-study-workspace-readiness', false);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_confirmed_scope_renders_study_intelligence_home_without_new_scoring_model(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            'AP対策',
            '資格学習',
            deadline: today()->addDays(10),
        );
        $this->scope($plan, 'ネットワーク', 'CIDR');
        $task = $this->task($plan, 'CIDRを演習する');
        $this->practiceEvidence($task, 86);
        $this->recallEvidence($task, 'good');

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-study-view-panel="work"', false)
            ->assertSee('data-study-workspace-recommendation', false)
            ->assertDontSee('data-study-workspace-action', false)
            ->assertDontSee('data-study-workspace-readiness', false)
            ->assertSee(
                route('plans.tasks.study_practice.show', [$plan, $task]),
                false,
            )
            ->assertSee(
                route('plans.tasks.study_recall.show', [$plan, $task]),
                false,
            );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertSee('data-study-workspace-readiness', false)
            ->assertSee('data-study-workspace-gap', false)
            ->assertSee('data-study-workspace-priority-scope', false)
            ->assertSee('EXAM READINESS')
            ->assertSee('BIGGEST GAP')
            ->assertSee('Coverage')
            ->assertSee('Mastery')
            ->assertSee('Retention')
            ->assertSee('Remaining')
            ->assertSee('CIDR')
            ->assertDontSee('data-study-workspace-recommendation', false);
    }

    public function test_explicit_plan_selection_rejects_inaccessible_or_non_study_plan(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $privateStudy = $this->plan($other, '他人の学習', '資格学習');
        $development = $this->plan($user, '開発', '個人開発');

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $privateStudy->id,
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $development->id,
            ]))
            ->assertNotFound();
    }

    public function test_study_workspace_context_overrides_different_manual_preference_without_erasing_it(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'development',
        ]);
        $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('workspace.study.index'))
            ->assertOk()
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-workspace-mode-source="route_hint"', false);

        $this->assertSame(
            'development',
            $user->fresh()->workspace_mode_preference,
        );
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority = 2,
        mixed $deadline = null,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => $deadline ?? today()->addWeeks(3),
            'is_public' => false,
        ]);
    }

    private function scope(
        Plan $plan,
        string $subject,
        string $unit,
    ): StudyScopeItem {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => '試験',
            'exam_date' => $plan->deadline?->format('Y-m-d'),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        return StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => $subject,
            'unit' => $unit,
            'range_text' => $unit,
            'confidence' => 1,
            'sort_order' => 0,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function practiceEvidence(Task $task, int $score): TaskEvidence
    {
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
                'strengths' => ['CIDR'],
                'weaknesses' => [],
                'weakness_topics' => [],
            ],
        ]);
    }

    private function recallEvidence(
        Task $task,
        string $rating,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_recall_reviewed',
            'external_key' => 'recall:'.Str::uuid(),
            'confidence' => 0.8,
            'occurred_at' => now()->addMinute(),
            'metadata' => [
                'rating' => $rating,
                'repetitions' => 2,
                'lapse_count' => 0,
                'interval_days' => 3,
                'mastered' => false,
            ],
        ]);
    }
}
