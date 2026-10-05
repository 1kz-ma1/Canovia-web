<?php

namespace Tests\Feature;

use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyLearningTypeOverrideV5617Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_ambiguous_generic_skill_plan_shows_confirmation_surface(): void
    {
        [$user, $plan] = $this->scenario(
            '英語を学ぶ',
            '英語学習',
            '英語学習を進める',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="skill_learning"',
                false,
            )
            ->assertSee(
                'data-study-learning-type-confirmation',
                false,
            )
            ->assertSee('この学習はどれに近い？')
            ->assertSee('スコアを上げる')
            ->assertSee('学校のテスト')
            ->assertSee('資格に合格')
            ->assertSee('スキル習得')
            ->assertSee('暗記・定着')
            ->assertSee('その他の学習')
            ->assertSee(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                false,
            );
    }

    public function test_fallback_general_learning_plan_shows_confirmation_surface(): void
    {
        [$user, $plan] = $this->scenario(
            '教養を深める',
            '学習',
            '本を読む',
        );

        $type = app(StudyLearningTypeRouter::class)
            ->route($plan);

        $this->assertSame('general_learning', $type['key']);
        $this->assertTrue($type['needs_confirmation']);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type-confirmation',
                false,
            );
    }

    public function test_strong_python_ap_and_toeic_signals_do_not_prompt(): void
    {
        $router = app(StudyLearningTypeRouter::class);

        $cases = [
            ['Pythonを習得する', 'プログラミング学習', 'skill_learning'],
            ['AP対策', '資格学習', 'certification_exam'],
            ['TOEIC 600点を取る', '英語学習', 'score_exam'],
        ];

        foreach ($cases as [$title, $category, $expected]) {
            [$user, $plan] = $this->scenario(
                $title,
                $category,
                $title.' Task',
            );

            $type = $router->route($plan);

            $this->assertSame($expected, $type['key']);
            $this->assertFalse($type['needs_confirmation']);

            $this->actingAs($user)
                ->get(route('workspace.study.index', [
                    'plan_id' => $plan->id,
                ]))
                ->assertOk()
                ->assertDontSee(
                    'data-study-learning-type-confirmation',
                    false,
                );
        }
    }

    public function test_explicit_override_outranks_contradictory_ap_heuristic_and_changes_method(): void
    {
        [$user, $plan] = $this->scenario(
            'AP対策',
            '資格学習',
            '英単語100語',
        );

        $user->forceFill([
            'workspace_mode_preference' => 'study',
        ])->save();

        $this->actingAs($user)
            ->put(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                ['learning_type' => 'memorization'],
            )
            ->assertRedirect(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertSessionHasNoErrors();

        $plan->refresh();
        $user->refresh();

        $this->assertSame(
            'memorization',
            $plan->study_learning_type_override,
        );
        $this->assertNotNull(
            $plan->study_learning_type_confirmed_at,
        );
        $this->assertSame(
            'study',
            $user->workspace_mode_preference,
        );

        $type = app(StudyLearningTypeRouter::class)
            ->route($plan);

        $this->assertSame('memorization', $type['key']);
        $this->assertSame(
            'certification_exam',
            $type['inferred_key'],
        );
        $this->assertSame(
            'explicit_override',
            $type['source'],
        );
        $this->assertSame(1.0, $type['confidence']);
        $this->assertFalse($type['needs_confirmation']);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="memorization"',
                false,
            )
            ->assertSee(
                'data-study-learning-type-source="explicit_override"',
                false,
            )
            ->assertSee('手動設定')
            ->assertSee(
                'data-study-method-key="recall"',
                false,
            )
            ->assertDontSee(
                'data-study-learning-type-confirmation',
                false,
            )
            ->assertSee('自動判定に戻す');
    }

    public function test_score_exam_override_changes_state_first_surfaces(): void
    {
        [$user, $plan] = $this->scenario(
            '英語力を伸ばす',
            '英語学習',
            '英語学習を進める',
        );

        $this->actingAs($user)
            ->put(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                ['learning_type' => 'score_exam'],
            )
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="score_exam"',
                false,
            )
            ->assertSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            )
            ->assertSee('現在スコアを記録')
            ->assertSee(
                route('plans.study_scores.index', $plan),
                false,
            );
    }

    public function test_explicit_general_learning_is_intentional_and_does_not_prompt_again(): void
    {
        [$user, $plan] = $this->scenario(
            '英語を学ぶ',
            '英語学習',
            '英語学習を進める',
        );

        $this->actingAs($user)
            ->put(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                ['learning_type' => 'general_learning'],
            )
            ->assertSessionHasNoErrors();

        $type = app(StudyLearningTypeRouter::class)
            ->route($plan->fresh());

        $this->assertSame('general_learning', $type['key']);
        $this->assertSame(
            'explicit_override',
            $type['source'],
        );
        $this->assertFalse($type['needs_confirmation']);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-study-learning-type-confirmation',
                false,
            );
    }

    public function test_reset_restores_heuristic_and_confirmation_behavior(): void
    {
        [$user, $plan] = $this->scenario(
            '英語を学ぶ',
            '英語学習',
            '英語学習を進める',
        );

        $this->actingAs($user)
            ->put(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                ['learning_type' => 'memorization'],
            )
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->delete(
                route(
                    'plans.study_learning_type.destroy',
                    $plan,
                ),
            )
            ->assertRedirect(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]));

        $plan->refresh();

        $this->assertNull(
            $plan->study_learning_type_override,
        );
        $this->assertNull(
            $plan->study_learning_type_confirmed_at,
        );

        $type = app(StudyLearningTypeRouter::class)
            ->route($plan);

        $this->assertSame('skill_learning', $type['key']);
        $this->assertSame('heuristic', $type['source']);
        $this->assertTrue($type['needs_confirmation']);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type-confirmation',
                false,
            );
    }

    public function test_invalid_learning_type_is_rejected(): void
    {
        [$user, $plan] = $this->scenario(
            '英語を学ぶ',
            '英語学習',
            '英語学習を進める',
        );

        $this->actingAs($user)
            ->from(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->put(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                ['learning_type' => 'not_a_real_type'],
            )
            ->assertSessionHasErrors('learning_type');

        $this->assertNull(
            $plan->fresh()->study_learning_type_override,
        );
    }

    public function test_read_only_viewer_cannot_change_learning_type(): void
    {
        [$owner, $plan] = $this->scenario(
            '英語を学ぶ',
            '英語学習',
            '英語学習を進める',
        );
        $viewer = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan->forceFill([
            'is_collaborative' => true,
        ])->save();

        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->put(
                route(
                    'plans.study_learning_type.update',
                    $plan,
                ),
                ['learning_type' => 'score_exam'],
            )
            ->assertForbidden();

        $this->assertNull(
            $plan->fresh()->study_learning_type_override,
        );
    }

    public function test_goal_summary_editor_is_available_even_for_high_confidence_inference(): void
    {
        [$user, $plan] = $this->scenario(
            'AP対策',
            '資格学習',
            '科目Aを演習する',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type-editor',
                false,
            )
            ->assertSee('学習タイプを変更')
            ->assertDontSee(
                'data-study-learning-type-confirmation',
                false,
            );
    }

    /**
     * @return array{0:User,1:Plan,2:Task}
     */
    private function scenario(
        string $title,
        string $category,
        string $taskTitle,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(35),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => $taskTitle,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
