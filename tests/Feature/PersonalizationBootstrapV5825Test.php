<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use App\Services\PersonalizationContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationBootstrapV5825Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_existing_user_is_not_forced_into_personalization_and_has_optional_account_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-personalization-account-entry', false)
            ->assertSee('Canoviaをあなた向けに調整する')
            ->assertSee(
                route('personalization.show', ['source' => 'account']),
                false,
            );
    }


    public function test_level_zero_keeps_personalization_hidden_and_first_run_uses_stable_core_path(): void
    {
        config([
            'release_levels.public_level' =>
                \App\Enums\ReleaseLevel::CoreStable->value,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('data-personalization-account-entry', false);

        $this->actingAs($user)
            ->get(route('personalization.show'))
            ->assertRedirect(route('workspace.overview.index'));

        auth()->logout();

        $this->post(route('first_run.start'))
            ->assertRedirect(route('plans.create'));
    }

    public function test_multi_domain_diagnosis_persists_self_reported_context_and_keeps_observed_inferred_separate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['study', 'development'],
                'deadline' => '2026-11-10',
                'weekly_capacity' => '5_10',
                'study_goal' => '応用情報技術者試験 合格',
                'study_kind' => 'qualification',
                'study_stage' => 'started',
                'development_goal' => 'Canovia Early Access',
                'development_experience' => 'standard',
                'development_stage' => 'existing',
                'github_usage' => 'yes',
                'repository_ready' => 'yes',
            ])
            ->assertRedirect(route('personalization.result'));

        $stored = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            ['study', 'development'],
            data_get($stored->self_reported_context, 'domains'),
        );
        $this->assertSame(
            '応用情報技術者試験 合格',
            data_get(
                $stored->self_reported_context,
                'domain_context.study.goal',
            ),
        );
        $this->assertSame(
            'Canovia Early Access',
            data_get(
                $stored->self_reported_context,
                'domain_context.development.goal',
            ),
        );
        $this->assertSame([], $stored->observed_context);
        $this->assertSame([], $stored->inferred_context);
        $this->assertSame('standard', $stored->guidance_level);
        $this->assertTrue(
            (bool) data_get(
                $stored->feature_readiness,
                'github.eligible',
            ),
        );
        $this->assertNotNull($stored->completed_at);
        $this->assertNotNull($stored->last_evaluated_at);

        $this->actingAs($user)
            ->get(route('personalization.result'))
            ->assertOk()
            ->assertSee('data-plan-seed="study_qualification"', false)
            ->assertSee('data-plan-seed="development_existing"', false)
            ->assertSee(
                'data-capability-preview="github_integration"',
                false,
            );
    }

    public function test_observed_candidate_never_overwrites_self_reported_diagnosis(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['development'],
                'weekly_capacity' => '2_5',
                'development_goal' => '新規アプリ',
                'development_experience' => 'beginner',
                'development_stage' => 'new',
                'github_usage' => 'no',
                'repository_ready' => 'no',
            ])
            ->assertRedirect(route('personalization.result'));

        $request = $this->requestFor($user);
        app(PersonalizationContextService::class)
            ->storeObservedCandidate($request, [
                'development' => [
                    'repository_count' => 3,
                    'pull_request_activity' => 'frequent',
                ],
            ]);

        $stored = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'beginner',
            data_get(
                $stored->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame(
            3,
            data_get(
                $stored->observed_context,
                'development.repository_count',
            ),
        );
        $this->assertSame([], $stored->inferred_context);
        $this->assertSame(
            'guided',
            $stored->guidance_level,
            'Observed candidate must not auto-promote Guidance in Phase 1.',
        );
    }

    public function test_client_hidden_seed_fields_cannot_fake_plan_created_from_seed_telemetry(): void
    {
        $user = User::factory()->create();
        $createRequestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => 'Fake seed attempt',
                'description' => 'normal manual creation',
                'category' => '個人開発',
                'workspace_mode' => 'development',
                'create_request_id' => $createRequestId,
                'personalization_seed_key' => 'development_existing',
                'personalization_seed_domain' => 'development',
            ])
            ->assertRedirect();

        $plan = Plan::query()
            ->where('creation_request_id', $createRequestId)
            ->firstOrFail();

        $this->assertFalse(
            BehaviorEvent::query()
                ->where(
                    'event_type',
                    BehaviorEventType::PlanCreatedFromSeed->value,
                )
                ->where('plan_id', $plan->id)
                ->exists(),
        );
    }

    public function test_github_preview_is_not_shown_without_readiness_signal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['development'],
                'weekly_capacity' => 'unknown',
                'development_experience' => 'standard',
                'development_stage' => 'existing',
                'github_usage' => 'no',
                'repository_ready' => 'no',
            ])
            ->assertRedirect(route('personalization.result'));

        $this->actingAs($user)
            ->get(route('personalization.result'))
            ->assertOk()
            ->assertSee('data-plan-seed="development_existing"', false)
            ->assertDontSee(
                'data-capability-preview="github_integration"',
                false,
            );
    }

    public function test_github_interest_is_self_reported_and_does_not_force_connection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['development'],
                'weekly_capacity' => '5_10',
                'development_experience' => 'advanced',
                'development_stage' => 'existing',
                'github_usage' => 'yes',
                'repository_ready' => 'yes',
            ])
            ->assertRedirect(route('personalization.result'));

        $this->actingAs($user)
            ->from(route('personalization.result'))
            ->post(route('personalization.capability.interest', [
                'capability' => 'github_integration',
            ]), [
                'interest' => 'yes',
            ])
            ->assertRedirect(route('personalization.result'));

        $stored = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'yes',
            data_get(
                $stored->self_reported_context,
                'capability_interest.github_integration',
            ),
        );
        $this->assertSame(
            'yes',
            data_get($stored->feature_readiness, 'github.interest'),
        );

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::CapabilityInterestYes->value,
        ]);
    }

    public function test_seed_acceptance_reuses_existing_plan_form_and_plan_creation_records_seed_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['study'],
                'deadline' => '2026-11-10',
                'weekly_capacity' => '5_10',
                'study_goal' => '応用情報技術者試験 合格',
                'study_kind' => 'qualification',
                'study_stage' => 'started',
            ])
            ->assertRedirect(route('personalization.result'));

        $this->actingAs($user)
            ->post(route('personalization.seed.accept', [
                'seedKey' => 'study_qualification',
            ]))
            ->assertRedirect(route('plans.create.manual', [
                'workspace_mode' => 'study',
            ]));

        $form = $this->actingAs($user)
            ->get(route('plans.create.manual', [
                'workspace_mode' => 'study',
            ]))
            ->assertOk()
            ->assertSee('value="応用情報技術者試験 合格"', false)
            ->assertSee('value="2026-11-10"', false)
            ->assertSee(
                'name="personalization_seed_key" value="study_qualification"',
                false,
            )
            ->assertSee('弱点探索');

        $createRequestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => '応用情報技術者試験 合格',
                'description' => "おすすめの初期骨格:\n- 現在地確認\n- 弱点探索",
                'category' => '資格学習',
                'deadline' => '2026-11-10',
                'workspace_mode' => 'study',
                'create_request_id' => $createRequestId,
                'personalization_seed_key' => 'study_qualification',
                'personalization_seed_domain' => 'study',
            ])
            ->assertRedirect();

        $plan = Plan::query()
            ->where('creation_request_id', $createRequestId)
            ->firstOrFail();

        $this->assertSame($user->id, $plan->user_id);
        $this->assertSame('資格学習', $plan->category);

        $event = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::PlanCreatedFromSeed->value,
            )
            ->where('plan_id', $plan->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(
            'study_qualification',
            data_get($event?->metadata, 'seed_key'),
        );
    }

    public function test_skip_keeps_personalization_optional_and_allows_normal_plan_creation_path(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.skip'))
            ->assertRedirect(route('plans.create'));

        $stored = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertNotNull($stored->skipped_at);

        $this->actingAs($user)
            ->get(route('plans.create'))
            ->assertOk();
    }

    public function test_unsure_only_keeps_questionnaire_light_and_produces_no_forced_seed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['unsure'],
                'weekly_capacity' => 'unknown',
            ])
            ->assertRedirect(route('personalization.result'));

        $this->actingAs($user)
            ->get(route('personalization.result'))
            ->assertOk()
            ->assertSee('まだ決めなくて大丈夫です')
            ->assertDontSee('data-plan-seed=', false)
            ->assertDontSee('data-capability-preview=', false);

        $stored = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame('guided', $stored->guidance_level);
    }

    public function test_study_and_development_seed_forms_disable_legacy_auto_tour(): void
    {
        foreach ([
            ['study', 'study_goal', '資格試験', 'study_qualification', 'study_kind', 'qualification'],
            ['development', 'development_goal', 'アプリ開発', 'development_new', 'development_stage', 'new'],
        ] as [$domain, $goalField, $goal, $seedKey, $kindField, $kind]) {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->post(route('personalization.store'), [
                    'domains' => [$domain],
                    $goalField => $goal,
                    $kindField => $kind,
                ])
                ->assertRedirect(route('personalization.result'));

            $this->actingAs($user)
                ->get(route('personalization.result'))
                ->assertOk()
                ->assertSee('data-onboarding-auto="0"', false);

            $this->actingAs($user)
                ->post(route('personalization.seed.accept', ['seedKey' => $seedKey]))
                ->assertRedirect(route('plans.create.manual', ['workspace_mode' => $domain]));

            $this->actingAs($user)
                ->get(route('plans.create.manual', ['workspace_mode' => $domain]))
                ->assertOk()
                ->assertSee('data-onboarding-auto="0"', false);
        }
    }

    public function test_skipping_diagnosis_does_not_restart_legacy_auto_tour(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.skip'))
            ->assertRedirect(route('plans.create'));

        $this->actingAs($user)
            ->get(route('plans.create'))
            ->assertOk()
            ->assertSee('data-onboarding-auto="0"', false);
    }

    public function test_seed_metadata_survives_plan_validation_retry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['study'],
                'study_goal' => '資格試験',
                'study_kind' => 'qualification',
            ])
            ->assertRedirect(route('personalization.result'));

        $this->actingAs($user)
            ->post(route('personalization.seed.accept', ['seedKey' => 'study_qualification']))
            ->assertRedirect(route('plans.create.manual', ['workspace_mode' => 'study']));

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'study']))
            ->assertOk()
            ->assertSee('name="personalization_seed_key" value="study_qualification"', false);

        $this->actingAs($user)
            ->from(route('plans.create.manual', ['workspace_mode' => 'study']))
            ->post(route('plans.store'), [
                'title' => '',
                'category' => '資格学習',
                'workspace_mode' => 'study',
                'personalization_seed_key' => 'study_qualification',
                'personalization_seed_domain' => 'study',
            ])
            ->assertSessionHasErrors('title');

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'study']))
            ->assertOk()
            ->assertSee('name="personalization_seed_key" value="study_qualification"', false)
            ->assertSee('name="personalization_seed_domain" value="study"', false);
    }

    public function test_diagnosed_goal_appears_in_empty_specialized_workspace(): void
    {
        foreach ([
            ['study', 'study_goal', '資格試験を合格する', 'workspace.study.index', 'data-study-first-use-context'],
            ['development', 'development_goal', '新しいアプリを公開する', 'workspace.development.index', 'data-development-first-use-context'],
        ] as [$domain, $goalField, $goal, $route, $marker]) {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->post(route('personalization.store'), [
                    'domains' => [$domain],
                    $goalField => $goal,
                ])
                ->assertRedirect(route('personalization.result'));

            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee($marker, false)
                ->assertSee($goal)
                ->assertSee(route('personalization.result'), false);
        }
    }

    public function test_diagnosis_seed_creation_opens_matching_specialized_workspace(): void
    {
        foreach ([
            ['study', 'study_goal', '資格試験を合格する', 'study_kind', 'qualification', 'study_qualification', '資格学習', 'workspace.study.index'],
            ['development', 'development_goal', 'アプリを公開する', 'development_stage', 'new', 'development_new', '個人開発', 'workspace.development.index'],
        ] as [$domain, $goalField, $goal, $kindField, $kind, $seedKey, $category, $workspaceRoute]) {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->post(route('personalization.store'), [
                    'domains' => [$domain],
                    $goalField => $goal,
                    $kindField => $kind,
                ])
                ->assertRedirect(route('personalization.result'));

            $this->actingAs($user)
                ->post(route('personalization.seed.accept', ['seedKey' => $seedKey]))
                ->assertRedirect(route('plans.create.manual', ['workspace_mode' => $domain]));

            $this->actingAs($user)
                ->get(route('plans.create.manual', ['workspace_mode' => $domain]))
                ->assertOk()
                ->assertSee('value="' . $goal . '"', false);

            $requestId = (string) Str::uuid();
            $this->actingAs($user)
                ->post(route('plans.store'), [
                    'title' => $goal,
                    'category' => $category,
                    'workspace_mode' => $domain,
                    'create_request_id' => $requestId,
                    'personalization_seed_key' => $seedKey,
                    'personalization_seed_domain' => $domain,
                ]);

            $plan = Plan::query()
                ->where('creation_request_id', $requestId)
                ->firstOrFail();

            $this->actingAs($user)
                ->get(route($workspaceRoute, ['plan_id' => $plan->id]))
                ->assertOk()
                ->assertSee($goal);
        }
    }

    private function requestFor(User $user): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create(
            '/personalization',
            'GET',
        );
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
