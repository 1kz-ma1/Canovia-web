<?php

namespace Tests\Feature;

use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdaptiveExamPackReadinessService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\AdminAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningVerifiedApExamL5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function fixture(int $questionCount = 80): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '応用情報の科目A',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => '本番形式の確認',
            'estimated_minutes' => 150, 'remaining_minutes' => 120,
            'progress_percent' => 23, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'ap-l5-synthetic-'.Str::lower(Str::random(9)),
            'title' => '合成80問の動作確認専用（実問題ではない）',
            'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '2026-v1', 'status' => 'published',
            'downloadable' => false,
            'metadata' => [
                'match_terms' => ['AP'],
                'exam_simulation_profile_key' => 'ap-a-cbt-2026-v1',
                'exam_simulation_profile_version' => '2026-v1',
            ],
        ]);

        $questions = [];
        for ($number = 1; $number <= $questionCount; $number++) {
            $questions[] = Question::create([
                'question_pack_id' => $pack->id,
                'external_key' => sprintf('synthetic-%03d', $number),
                'source_type' => 'canovia_original',
                'prompt' => '合成のテスト問題 '.$number,
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice',
                    'label' => '回答', 'required' => true,
                    'choices' => [
                        ['id' => 'A', 'label' => 'ア'],
                        ['id' => 'B', 'label' => 'イ'],
                        ['id' => 'C', 'label' => 'ウ'],
                        ['id' => 'D', 'label' => 'エ'],
                    ],
                ]],
                'grading_rule' => ['type' => 'exact_choice',
                    'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => ['テスト']],
                'explanation' => 'テストの解説',
                'difficulty' => 2, 'sort_order' => $number,
                'is_active' => true,
            ]);
        }
        return [$user, $plan, $task, $pack, $questions];
    }

    private function review(QuestionPack $pack, array $overrides = []): void
    {
        $metadata = $pack->metadata;
        $metadata['exam_simulation_review'] = array_merge([
            'reviewed_pack_version' => $pack->version,
            'reviewed_at' => '2026-10-09',
            'format_checked' => true,
            'answer_key_checked' => true,
            'content_rights_checked' => true,
        ], $overrides);
        $pack->update(['metadata' => $metadata]);
    }

    private function attempt(User $user, Plan $plan, Task $task, QuestionPack $pack)
    {
        return $this->actingAs($user)
            ->post(route('plans.tasks.learning.exam.start', [$plan, $task]), [
                'start_request_id' => (string) Str::uuid(),
                'exam_profile_key' => 'ap-a-cbt-2026-v1',
                'question_pack_id' => $pack->id,
            ]);
    }

    public function test_official_ap_a_is_verified_without_publishing_any_unreviewed_exam_set_or_ap_b(): void
    {
        $profiles = app(AdaptiveExamProfileRegistry::class)->available();
        $ap = $profiles->firstWhere('key', 'ap-a-cbt-2026-v1');
        $this->assertNotNull($ap);
        $this->assertSame(80, $ap['question_count']);
        $this->assertSame(150, $ap['duration_minutes']);
        $this->assertSame(4, $ap['choices_per_question']);
        $this->assertSame('single_choice', $ap['response_format']);
        $this->assertSame('科目A', $ap['subject']);
        $this->assertFalse($profiles->contains(fn ($p) =>
            $p['exam_code'] === 'AP' && $p['subject'] === '科目B'));

        [$user, $plan, $task] = $this->fixture(4);
        $this->actingAs($user)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-adaptive-exam-format', false)
            ->assertSee('80問 / 150分')
            ->assertSee('data-adaptive-exam-no-ready-pack', false)
            ->assertDontSee('data-adaptive-exam-entry', false);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_reviewed_partial_or_wrong_format_pack_is_not_selectable_or_startable(): void
    {
        [$user, $plan, $task, $pack, $questions] = $this->fixture(79);
        $this->review($pack);
        $this->attempt($user, $plan, $task, $pack)->assertSessionHasErrors('question_pack_id');
        $this->assertDatabaseCount('learning_runs', 0);

        $missing = Question::create([
            'question_pack_id' => $pack->id, 'external_key' => 'synthetic-080',
            'source_type' => 'canovia_original', 'prompt' => '80問目の不適切な回答形式',
            'response_schema' => [[
                'id' => 'answer', 'type' => 'number', 'label' => '数値', 'required' => true,
                'choices' => [],
            ]],
            'grading_rule' => ['type' => 'numeric_tolerance',
                'field_id' => 'answer', 'answer' => 3, 'tolerance' => 0],
            'learning_metadata' => ['concepts' => ['テスト']],
            'explanation' => 'テスト用', 'difficulty' => 2, 'sort_order' => 80,
            'is_active' => true,
        ]);
        $this->attempt($user, $plan, $task, $pack)->assertSessionHasErrors('question_pack_id');
        $this->assertDatabaseCount('learning_runs', 0);

        $missing->update([
            'response_schema' => $questions[0]->response_schema,
            'grading_rule' => $questions[0]->grading_rule,
        ]);
        $this->review($pack, ['content_rights_checked' => false]);
        $this->attempt($user, $plan, $task, $pack)->assertSessionHasErrors('question_pack_id');
        $this->actingAs($user)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertDontSee('data-adaptive-exam-entry', false);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()->assertSee('data-exam-pack-readiness="'.$pack->id.'"', false)
            ->assertSee('模試セット：未完成・未承認');

        // A pack may be published for ordinary practice yet fail the
        // separate official-format exam gate.
        $this->assertSame('published', $pack->fresh()->status);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_manually_reviewed_synthetic_eighty_question_pack_freezes_full_exam_without_early_answers(): void
    {
        [$user, $plan, $task, $pack, $questions] = $this->fixture();
        $this->review($pack);
        $inspection = app(AdaptiveExamPackReadinessService::class)->inspect(
            $pack->fresh(), app(AdaptiveExamProfileRegistry::class)->requireVerified('ap-a-cbt-2026-v1'));
        $this->assertTrue($inspection['ready'], implode(' ', $inspection['blocking']));
        $this->assertSame(80, $inspection['active_count']);

        $this->actingAs($user)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-adaptive-exam-entry', false)
            ->assertSee('data-adaptive-exam-option="ap-a-cbt-2026-v1"', false)
            ->assertSee('80問 / 150分');

        $this->attempt($user, $plan, $task, $pack)->assertRedirect()->assertSessionHasNoErrors();
        $run = LearningRun::where('mode', 'exam')->sole();
        $this->assertSame('2026-v1', $run->exam_profile_version);
        $this->assertSame(80, $run->items()->count());
        $this->assertEquals($run->started_at->copy()->addMinutes(150), $run->exam_deadline_at);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('learning_exam_response_drafts', 0);
        $this->assertSame($questions[0]->id, $run->items()->first()->question_id);
        $this->actingAs($user)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('合成のテスト問題 1')
            ->assertDontSee('合成のテスト問題 2')
            ->assertDontSee('正答：')->assertDontSee('テストの解説');

        $this->actingAs($user)->post(route('plans.tasks.learning.exam.answer', [$plan, $task, $run]), [
            'request_id' => (string) Str::uuid(),
            'learning_run_item_id' => $run->items()->first()->id,
            'choice' => 'A',
        ])->assertRedirect();
        $this->assertDatabaseCount('learning_exam_response_drafts', 1);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->actingAs($user)->post(route('plans.tasks.learning.exam.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertSame('completed', $run->fresh()->status);
        $this->actingAs($user)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('採点結果：1/80問正解');

        $this->assertSame(23, $task->fresh()->progress_percent);
        $this->assertSame(120, $task->fresh()->remaining_minutes);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }
}
