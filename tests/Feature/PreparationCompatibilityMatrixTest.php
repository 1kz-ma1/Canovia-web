<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PreparationCompatibilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    public static function audiences(): iterable
    {
        foreach ([false, true] as $preparation) {
            foreach ([0, 1] as $level) {
                foreach (['anonymous', 'normal', 'admin'] as $actor) {
                    yield ($preparation ? 'preparation' : 'original')."-L{$level}-{$actor}" => [$preparation, $level, $actor];
                }
            }
        }
    }

    private function history(User $owner, string $mode): array
    {
        $plan = Plan::create(['user_id' => $owner->id, 'title' => "合成 {$mode} 既存Plan",
            'owner_token' => Str::random(64), 'public_slug' => (string) Str::uuid(),
            'category' => $mode === 'study' ? '資格学習' : '個人開発',
            'start_date' => today(), 'is_public' => false, 'is_collaborative' => false]);
        $task = Task::create(['plan_id' => $plan->id, 'title' => '合成の既存Task', 'status' => 'doing',
            'estimated_minutes' => 30, 'remaining_minutes' => 25, 'progress_percent' => 17,
            'priority' => 1, 'activation_cost' => 1, 'sort_order' => 1]);
        WorkSession::create(['plan_id' => $plan->id, 'task_id' => $task->id, 'actor_token' => 'synthetic', 'browser_session_id' => (string) Str::uuid(),
            'status' => 'completed', 'started_at' => now()->subMinutes(5), 'ended_at' => now(),
            'actual_seconds' => 300, 'intended_minutes' => 5, 'source' => 'web']);
        if ($mode === 'study') {
            StudyPracticeAttempt::create(['plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $owner->id,
                'request_hash' => hash('sha256', (string) $plan->id), 'exercise_title' => '合成の採点履歴',
                'questions' => [], 'answers' => [], 'assessment' => [], 'score_percent' => 60,
                'recommended_task_progress_percent' => 17, 'progress_before_percent' => 17, 'progress_after_percent' => 17]);
        }
        return [$plan, $task];
    }

    private function snapshot(): array
    {
        return collect(['plans', 'tasks', 'study_practice_attempts', 'work_sessions'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    #[DataProvider('audiences')]
    public function test_profile_audience_matrix_preserves_existing_ap_development_and_history(bool $preparation, int $level, string $actor): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['release_levels.preparation_only' => $preparation, 'release_levels.public_level' => $level,
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
        $owner = User::factory()->create(['release_level_override' => null]);
        if ($actor === 'admin') {
            config(['canovia.super_admin_user_id' => $owner->id]);
        }
        [$study, $studyTask] = $this->history($owner, 'study');
        [$development] = $this->history($owner, 'development');
        $before = $this->snapshot();
        if ($actor !== 'anonymous') {
            $this->actingAs($owner);
        }
        foreach (['study' => $study, 'development' => $development] as $mode => $plan) {
            $response = $this->get(route("workspace.{$mode}.index", ['plan_id' => $plan->id]));
            if ($preparation) {
                $response->assertNotFound();
            } elseif ($level === 0) {
                $response->assertRedirect();
            } elseif ($actor === 'anonymous') {
                $response->assertNotFound();
            } else {
                $response->assertOk();
            }
        }
        $historyUrl = route('plans.tasks.study_practice.history', [$study, $studyTask]);
        $history = $this->get($historyUrl);
        if ($preparation) {
            $history->assertNotFound();
        } elseif ($actor !== 'anonymous' && $level === 1) {
            $history->assertOk();
        } else {
            $history->assertForbidden();
        }
        if ($actor !== 'anonymous') {
            $this->get(route('plans.show', $study))->assertOk();
            $this->get('/personalization')->assertStatus($level === 1 ? 200 : 302);
            $foreign = User::factory()->create();
            $this->actingAs($foreign)->get($historyUrl)->assertStatus($preparation ? 404 : 403);
            $this->put(route('plans.update', $study), ['title' => '他者の上書き'])->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot(), 'Profile/Level/access reads must not rewrite canonical data');
        $this->assertNull($owner->fresh()->release_level_override);
        Http::assertNothingSent();
    }

    public function test_explicit_tester_grant_and_on_off_transition_restore_same_history_without_regrading(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['release_levels.public_level' => 0, 'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
        $owner = User::factory()->create(['release_level_override' => 1]);
        [$plan, $task] = $this->history($owner, 'study');
        $before = $this->snapshot();
        $this->actingAs($owner);
        foreach ([false, true, false] as $profile) {
            config(['release_levels.preparation_only' => $profile]);
            $this->get('/personalization')->assertOk();
            $this->get(route('plans.show', $plan))->assertOk();
            $this->get(route('plans.tasks.study_practice.history', [$plan, $task]))->assertStatus($profile ? 404 : 200);
            $this->get(route('workspace.study.index', ['plan_id' => $plan->id]))->assertStatus($profile ? 404 : 200);
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertSame(1, $owner->fresh()->release_level_override);
        Http::assertNothingSent();
    }
}
