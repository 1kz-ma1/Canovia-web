<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\ProductKey;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CapabilityActivationV5826Test extends TestCase
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
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => 'configured-private-key',
            'services.github.app_private_key_base64' => null,
            'services.github.app_install_url' =>
                'https://github.com/apps/canovia/installations/new',
        ]);
    }

    public function test_interested_development_user_gets_guided_github_readiness_with_single_repository_candidate(): void
    {
        [$user, $plan, $repository] = $this->scenario();

        $response = $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk();

        $response
            ->assertSee('data-capability-activation="github_integration"', false)
            ->assertSee('data-capability-stage="readiness"', false)
            ->assertSee('GitHub連携を段階的に準備')
            ->assertSee($repository->title)
            ->assertSee('1件だけなので候補化')
            ->assertSee('GitHub連携の準備を進める')
            ->assertSee('Need')
            ->assertSee('Preview')
            ->assertSee('Interest')
            ->assertSee('Readiness')
            ->assertSee('Setup');
    }

    public function test_setup_start_persists_lifecycle_and_auto_candidate_without_connecting_github(): void
    {
        [$user, $plan, $repository] = $this->scenario();

        $this->actingAs($user)
            ->post(route('capabilities.setup.start', [
                'capability' => 'github_integration',
                'plan' => $plan,
            ]))
            ->assertRedirect(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'setup_started',
            data_get(
                $context->feature_readiness,
                'github.activation.stage',
            ),
        );
        $this->assertSame(
            $plan->id,
            data_get(
                $context->feature_readiness,
                'github.activation.plan_id',
            ),
        );
        $this->assertSame(
            $repository->id,
            data_get(
                $context->feature_readiness,
                'github.activation.repository_artifact_id',
            ),
        );
        $this->assertTrue((bool) data_get(
            $context->feature_readiness,
            'github.activation.repository_auto_candidate',
        ));

        $repository->refresh();
        $this->assertNull(data_get(
            $repository->metadata,
            'github_app_connection.status',
        ));

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::CapabilitySetupStarted->value,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_setup_start_with_multiple_repositories_does_not_auto_select_one(): void
    {
        [$user, $plan] = $this->scenario();

        PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Second Repository',
            'url' => 'https://github.com/example/second',
            'metadata' => null,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('複数Repositoryがあります')
            ->assertDontSee('1件だけなので候補化');

        $this->actingAs($user)
            ->post(route('capabilities.setup.start', [
                'capability' => 'github_integration',
                'plan' => $plan,
            ]))
            ->assertRedirect(route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertNull(data_get(
            $context->feature_readiness,
            'github.activation.repository_artifact_id',
        ));
        $this->assertFalse((bool) data_get(
            $context->feature_readiness,
            'github.activation.repository_auto_candidate',
        ));
    }

    public function test_abandon_hides_activation_card_without_changing_interest_answer(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->post(route('capabilities.setup.start', [
                'capability' => 'github_integration',
                'plan' => $plan,
            ]))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('capabilities.setup.abandon', [
                'capability' => 'github_integration',
                'plan' => $plan,
            ]))
            ->assertRedirect(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'abandoned',
            data_get(
                $context->feature_readiness,
                'github.activation.stage',
            ),
        );
        $this->assertSame(
            'yes',
            data_get(
                $context->self_reported_context,
                'capability_interest.github_integration',
            ),
        );

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-capability-activation="github_integration"',
                false,
            );

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::CapabilitySetupAbandoned->value,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_connected_repository_completes_activation_and_records_observed_context_without_overwriting_self_reported_context(): void
    {
        [$user, $plan, $repository] = $this->scenario(
            experience: 'beginner',
        );

        $this->actingAs($user)
            ->post(route('capabilities.setup.start', [
                'capability' => 'github_integration',
                'plan' => $plan,
            ]))
            ->assertRedirect();

        $repository->forceFill([
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                    'read_ready' => true,
                    'write_ready' => false,
                    'permissions' => [
                        'contents' => 'read',
                        'pull_requests' => 'read',
                    ],
                ],
            ],
        ])->save();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-capability-stage="completed"', false)
            ->assertSee('接続済み');

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'completed',
            data_get(
                $context->feature_readiness,
                'github.activation.stage',
            ),
        );
        $this->assertSame(
            'connected',
            data_get(
                $context->observed_context,
                'github_integration.status',
            ),
        );
        $this->assertSame(
            'beginner',
            data_get(
                $context->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame(
            'guided',
            $context->guidance_level,
            'Observed GitHub connection must not auto-promote guidance in Phase 2.',
        );

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::CapabilitySetupCompleted->value,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_operator_runtime_block_does_not_advance_setup_lifecycle(): void
    {
        config([
            'services.github.app_id' => '',
            'services.github.app_private_key' => '',
            'services.github.app_install_url' => '',
        ]);

        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('CANOVIA OPERATOR')
            ->assertDontSee('GitHub連携の準備を進める');

        $this->actingAs($user)
            ->post(route('capabilities.setup.start', [
                'capability' => 'github_integration',
                'plan' => $plan,
            ]))
            ->assertRedirect(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertNull(data_get(
            $context->feature_readiness,
            'github.activation.stage',
        ));

        $this->assertFalse(
            BehaviorEvent::query()
                ->where(
                    'event_type',
                    BehaviorEventType::CapabilitySetupStarted->value,
                )
                ->exists(),
        );
    }

    public function test_non_development_plan_cannot_start_github_capability_setup(): void
    {
        [$user] = $this->scenario();

        $study = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Study Plan',
            'category' => '資格学習',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $this->actingAs($user)
            ->post(route('capabilities.setup.start', [
                'capability' => 'github_integration',
                'plan' => $study,
            ]))
            ->assertNotFound();
    }

    /**
     * @return array{0:User,1:Plan,2:PlanArtifact}
     */
    private function scenario(
        string $experience = 'standard',
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $this->grantAllAccess($user);

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['development'],
                'weekly_capacity' => '5_10',
                'development_goal' => 'Capability Activation',
                'development_experience' => $experience,
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

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Capability Activation',
            'description' => 'GitHub guided readiness',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $repository = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => null,
        ]);

        return [$user, $plan, $repository];
    }

    private function grantAllAccess(User $user): void
    {
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'metadata' => ['test' => true],
        ]);
    }
}
