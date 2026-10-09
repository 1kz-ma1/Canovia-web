<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\QuestionPackPublicationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A bundled JSON file alone is never an installed or published Question Bank.
 * Prove the existing admin-only Draft -> Review -> Publish workflow on a
 * disposable test database and then exercise the real Learning Run routes.
 */
final class LearningBundledPackDeliveryL2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'study.adaptive_learning.locked_queue_size' => 2,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function learner(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP科目A演習',
            'category' => '資格学習',
            'priority' => 3,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(30),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク問題を確認する',
            'estimated_minutes' => 40,
            'remaining_minutes' => 25,
            'progress_percent' => 35,
            'status' => 'doing',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    public function test_core_and_official_bundles_require_explicit_admin_import_and_publication_before_learning_use(): void
    {
        [$user, $plan, $task] = $this->learner();
        $bundles = [
            'ap/ap-a-canovia-core-v1' => ['slug' => 'ap-a-canovia-core-v1', 'count' => 74],
            'ap/ap-a-ipa-2025-autumn-official-v1' => ['slug' => 'ap-a-ipa-2025-autumn-official-v1', 'count' => 35],
        ];

        $this->actingAs($user)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-adaptive-learning-no-packs', false);

        $this->get(route('admin.question_packs.index'))->assertForbidden();
        $admin = $this->withSession([AdminAccessService::SESSION_KEY => true]);
        $admin->get(route('admin.question_packs.index'))->assertOk()
            ->assertSee('data-install-status="absent"', false)
            ->assertSee('未取込（ユーザーには出題されません）');

        foreach ($bundles as $key => $expected) {
            $admin->post(route('admin.question_packs.import_bundled'), ['catalog_key' => $key])
                ->assertRedirect(route('admin.question_packs.index'))
                ->assertSessionHasNoErrors();

            $pack = QuestionPack::where('slug', $expected['slug'])->firstOrFail();
            $this->assertSame('draft', $pack->status);
            $this->assertSame($expected['count'], $pack->questions()->count());

            $inspection = app(QuestionPackPublicationReadinessService::class)->inspect($pack);
            $this->assertTrue($inspection['publishable'], $key.' should pass publication preflight');
            $this->assertGreaterThan(0, $inspection['one_question_count']);
            $this->assertSame(0, $inspection['missing_attribution_count']);

            $admin->get(route('admin.question_packs.index'))
                ->assertOk()
                ->assertSee('data-install-status="draft"', false)
                ->assertSee('公開条件：充足');

            $this->actingAs($user)->get(route('plans.tasks.learning.index', [$plan, $task]))
                ->assertOk()->assertDontSee($pack->title);

            $admin->patch(route('admin.question_packs.status', $pack), ['status' => 'review'])
                ->assertSessionHasNoErrors();
            $this->assertSame('review', $pack->fresh()->status);

            $admin->patch(route('admin.question_packs.status', $pack), ['status' => 'published'])
                ->assertSessionHasNoErrors();
            $this->assertSame('published', $pack->fresh()->status);

            $admin->get(route('admin.question_packs.index'))
                ->assertOk()
                ->assertSee('data-install-status="published"', false)
                ->assertSee('公開済み・公開終了済みPackは再取込できません');

            $this->actingAs($user)->get(route('plans.tasks.learning.index', [$plan, $task]))
                ->assertOk()->assertSee($pack->title);

            $startId = (string) Str::uuid();
            $this->actingAs($user)->post(route('plans.tasks.learning.start', [$plan, $task]), [
                'start_request_id' => $startId,
                'question_pack_id' => $pack->id,
                'mode' => 'understanding',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $run = LearningRun::where('start_request_id', $startId)->firstOrFail();
            $item = $run->items()->orderBy('ordinal')->firstOrFail();
            $correct = (string) data_get($item->grading_rule_snapshot, 'answer');
            $this->assertNotSame('', $correct);

            $this->actingAs($user)->post(
                route('plans.tasks.learning.answer', [$plan, $task, $run]),
                [
                    'request_id' => (string) Str::uuid(),
                    'learning_run_item_id' => $item->id,
                    'choice' => $correct,
                ],
            )->assertRedirect()->assertSessionHasNoErrors();

            $this->assertTrue((bool) $item->answer()->firstOrFail()->was_correct);
            $this->actingAs($user)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
                ->assertOk()->assertSee('正答：');

            // A published Pack must not be rewritten via the Bundled import.
            $before = $pack->questions()->count();
            $admin->from(route('admin.question_packs.index'))
                ->post(route('admin.question_packs.import_bundled'), ['catalog_key' => $key])
                ->assertSessionHasErrors('pack_json');
            $this->assertSame('published', $pack->fresh()->status);
            $this->assertSame($before, $pack->questions()->count());
            $this->assertTrue((bool) $item->answer()->firstOrFail()->was_correct);
        }

        $this->assertDatabaseCount('question_packs', 2);
        $this->assertDatabaseCount('learning_answer_events', 2);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertSame(35, $task->fresh()->progress_percent);
        $this->assertSame(25, $task->fresh()->remaining_minutes);
    }
}
