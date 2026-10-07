<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\ProductKey;
use App\Enums\ReleaseLevel;
use App\Enums\WorkspaceMode;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LivingProfileV5827Test extends TestCase
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

    public function test_workspace_change_auto_applies_only_low_risk_surface_priority(): void
    {
        $user = $this->personalizedUser(
            domains: ['study', 'development'],
            experience: 'standard',
        );

        $before = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            ['workspace.study', 'workspace.development'],
            $before->recommended_surfaces,
        );

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Development->value,
            ]))
            ->assertRedirect(route('workspace.development.top'));

        $after = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'workspace.development',
            $after->recommended_surfaces[0],
        );
        $this->assertSame(
            'development',
            data_get(
                $after->observed_context,
                'workspace.recent_domain',
            ),
        );
        $this->assertSame(
            'auto_applied',
            data_get(
                $after->inferred_context,
                'update_candidates.recent_domain_priority_development.status',
            ),
        );
        $this->assertSame(
            'standard',
            data_get(
                $after->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame(
            'standard',
            $after->guidance_level,
        );

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::ContextUpdateAutoApplied->value,
        ]);
    }

    public function test_github_completion_creates_confirmation_candidate_without_reclassifying_user(): void
    {
        [$user, $plan, $repository] = $this->githubScenario(
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
            ->assertOk();

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $candidate = data_get(
            $context->inferred_context,
            'update_candidates.development_advanced_support',
        );

        $this->assertIsArray($candidate);
        $this->assertSame('pending', data_get($candidate, 'status'));
        $this->assertSame('high', data_get($candidate, 'risk'));
        $this->assertSame('low', data_get($candidate, 'confidence'));
        $this->assertSame(1, data_get(
            $candidate,
            'confidence_calibration.signal_strength',
        ));
        $this->assertTrue((bool) data_get(
            $candidate,
            'confirmation_required',
        ));

        $this->assertSame(
            'beginner',
            data_get(
                $context->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame('guided', $context->guidance_level);
        $this->assertNull(data_get(
            $context->feature_readiness,
            'development.advanced_support.enabled',
        ));

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-living-profile-account-entry', false)
            ->assertSee('1件、確認してほしいContext更新候補があります。');

        $this->actingAs($user)
            ->get(route('personalization.updates.index'))
            ->assertOk()
            ->assertSee(
                'data-context-update-candidate="development_advanced_support"',
                false,
            )
            ->assertSee('より高度なDevelopment支援を表示しますか？')
            ->assertSee('初回に回答した経験レベルも変更しません。');

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::ContextUpdateCandidateCreated->value,
            'plan_id' => $plan->id,
        ]);
    }

    public function test_confirmed_high_impact_candidate_changes_feature_readiness_not_self_reported_experience(): void
    {
        [$user] = $this->pendingAdvancedSupportScenario(
            experience: 'beginner',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.confirm', [
                'candidateKey' => 'development_advanced_support',
            ]))
            ->assertRedirect(route('personalization.updates.index'));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'confirmed',
            data_get(
                $context->inferred_context,
                'update_candidates.development_advanced_support.status',
            ),
        );
        $this->assertTrue((bool) data_get(
            $context->feature_readiness,
            'development.advanced_support.enabled',
        ));
        $this->assertSame(
            'context_confirmation',
            data_get(
                $context->feature_readiness,
                'development.advanced_support.source',
            ),
        );
        $this->assertSame(
            'beginner',
            data_get(
                $context->self_reported_context,
                'domain_context.development.experience',
            ),
        );
        $this->assertSame('guided', $context->guidance_level);

        $this->assertDatabaseHas('behavior_events', [
            'event_type' =>
                BehaviorEventType::ContextUpdateConfirmed->value,
        ]);
    }

    public function test_dismissed_candidate_is_not_recreated_from_same_observed_fact(): void
    {
        [$user] = $this->pendingAdvancedSupportScenario(
            experience: 'standard',
        );

        $this->actingAs($user)
            ->post(route('personalization.updates.dismiss', [
                'candidateKey' => 'development_advanced_support',
            ]))
            ->assertRedirect(route('personalization.updates.index'));

        $this->actingAs($user)
            ->post(route('personalization.updates.refresh'))
            ->assertRedirect(route('personalization.updates.index'));

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'dismissed',
            data_get(
                $context->inferred_context,
                'update_candidates.development_advanced_support.status',
            ),
        );
        $this->assertNull(data_get(
            $context->feature_readiness,
            'development.advanced_support.enabled',
        ));

        $this->actingAs($user)
            ->get(route('personalization.updates.index'))
            ->assertOk()
            ->assertSee('確認が必要な変化はありません')
            ->assertDontSee(
                'data-context-update-candidate="development_advanced_support"',
                false,
            );

        $candidateEvents = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextUpdateCandidateCreated->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'candidate_key')
                        === 'development_advanced_support',
            );

        $this->assertCount(1, $candidateEvents);
    }

    public function test_user_without_personalization_context_is_not_silently_profiled_on_workspace_change(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Development->value,
            ]))
            ->assertRedirect(route('workspace.development.top'));

        $this->assertDatabaseMissing(
            'user_personalization_contexts',
            ['user_id' => $user->id],
        );

        $this->assertFalse(
            BehaviorEvent::query()
                ->where(
                    'event_type',
                    BehaviorEventType::ContextRefreshTriggered->value,
                )
                ->exists(),
        );
    }

    public function test_level_zero_hides_living_profile_surface(): void
    {
        $user = $this->personalizedUser(
            domains: ['development'],
            experience: 'standard',
        );

        config([
            'release_levels.public_level' =>
                ReleaseLevel::CoreStable->value,
        ]);

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee(
                'data-living-profile-account-entry',
                false,
            );

        $this->actingAs($user)
            ->get(route('personalization.updates.index'))
            ->assertRedirect(route('workspace.overview.index'));
    }

    /**
     * @return array{0:User,1:Plan,2:PlanArtifact}
     */
    private function githubScenario(
        string $experience = 'standard',
    ): array {
        $user = $this->personalizedUser(
            domains: ['development'],
            experience: $experience,
        );
        $this->grantAllAccess($user);

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
            'title' => 'Living Profile Development',
            'description' => 'Context update candidate',
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

    /**
     * @return array{0:User,1:Plan,2:PlanArtifact}
     */
    private function pendingAdvancedSupportScenario(
        string $experience,
    ): array {
        [$user, $plan, $repository] = $this->githubScenario(
            experience: $experience,
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
            ->assertOk();

        return [$user, $plan, $repository];
    }

    private function personalizedUser(
        array $domains,
        string $experience,
    ): User {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $payload = [
            'domains' => $domains,
            'weekly_capacity' => '5_10',
        ];

        if (in_array('study', $domains, true)) {
            $payload += [
                'study_goal' => '応用情報技術者試験 合格',
                'study_kind' => 'qualification',
                'study_stage' => 'started',
            ];
        }

        if (in_array('development', $domains, true)) {
            $payload += [
                'development_goal' => 'Canovia',
                'development_experience' => $experience,
                'development_stage' => 'existing',
                'github_usage' => 'yes',
                'repository_ready' => 'yes',
            ];
        }

        $this->actingAs($user)
            ->post(route('personalization.store'), $payload)
            ->assertRedirect(route('personalization.result'));

        return $user;
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
