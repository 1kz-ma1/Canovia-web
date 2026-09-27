<?php

namespace Tests\Feature;

use App\Contracts\EntitlementResolver;
use App\Data\FeatureAccessDecision;
use App\Enums\EntitlementSource;
use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\Entitlements\FreeEntitlementResolver;
use App\Services\FeatureAccessService;
use App\Services\PlanToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeatureAccessV406Test extends TestCase
{
    use RefreshDatabase;

    public function test_existing_v406_features_remain_free_for_guest_and_user(): void
    {
        $service = app(FeatureAccessService::class);
        $user = User::factory()->create();

        foreach ([
            FeatureKey::AiPractice,
            FeatureKey::AdvancedAnalytics,
            FeatureKey::QuestionPack,
            FeatureKey::ProjectArtifact,
            FeatureKey::ConversationalOnboarding,
        ] as $feature) {
            foreach ([null, $user] as $actor) {
                $decision = $service->resolveAccess($actor, $feature);

                $this->assertTrue($decision->allowed, $feature->value.' must remain usable for the existing Free path');
                $this->assertSame(EntitlementSource::Free, $decision->source);
                $this->assertSame('free_access', $decision->reason);
            }
        }
    }

    public function test_feature_catalog_and_configuration_stay_in_sync(): void
    {
        $enumKeys = collect(FeatureKey::cases())->map(fn (FeatureKey $key) => $key->value)->sort()->values()->all();
        $configuredKeys = collect(array_keys(config('entitlements.features', [])))->sort()->values()->all();

        $this->assertSame($enumKeys, $configuredKeys);
    }

    public function test_tool_presentation_uses_the_same_access_boundary(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $plan->load(['resources', 'tasks.resources', 'tasks.artifacts']);
        $task = $plan->tasks->first();

        config()->set('entitlements.features.ai_practice.free', false);

        $tools = collect(app(PlanToolService::class)->forTask($plan, $task, true, $user));

        $this->assertFalse($tools->contains(fn (array $tool) => $tool['id'] === 'ai_practice'));
        $this->assertTrue($tools->contains(fn (array $tool) => $tool['id'] === 'timer'));
    }

    public function test_feature_flag_visibility_is_not_part_of_entitlement_resolution(): void
    {
        config()->set('features.canovia_ai', false);

        $decision = app(FeatureAccessService::class)
            ->resolveAccess(null, FeatureKey::AiPractice);

        $this->assertTrue($decision->allowed);
        $this->assertSame(EntitlementSource::Free, $decision->source);
    }

    public function test_automatic_ai_execution_is_not_part_of_the_free_manual_handoff_path(): void
    {
        $decision = app(FeatureAccessService::class)
            ->resolveAccess(User::factory()->create(), FeatureKey::AutomaticAiExecution);

        $this->assertFalse($decision->allowed);
        $this->assertNull($decision->source);
        $this->assertSame('no_entitlement', $decision->reason);
    }

    public function test_future_resolver_can_grant_access_without_feature_code_knowing_the_source(): void
    {
        config()->set('entitlements.features.advanced_analytics.free', false);

        $premium = new class implements EntitlementResolver {
            public function source(): EntitlementSource
            {
                return EntitlementSource::Premium;
            }

            public function priority(): int
            {
                return 100;
            }

            public function resolve(?User $actor, FeatureKey $feature, array $context = []): ?FeatureAccessDecision
            {
                if ($feature !== FeatureKey::AdvancedAnalytics || ! $actor) {
                    return null;
                }

                return FeatureAccessDecision::allow($feature, EntitlementSource::Premium, 'premium_subscription');
            }
        };

        $service = new FeatureAccessService([
            new FreeEntitlementResolver(),
            $premium,
        ]);
        $decision = $service->resolveAccess(User::factory()->create(), FeatureKey::AdvancedAnalytics);

        $this->assertTrue($decision->allowed);
        $this->assertSame(EntitlementSource::Premium, $decision->source);
        $this->assertSame('premium_subscription', $decision->reason);
        $this->assertSame([
            'feature_key' => 'advanced_analytics',
            'allowed' => true,
            'access_source' => 'premium',
            'access_reason' => 'premium_subscription',
        ], $decision->auditMetadata());
    }

    public function test_ai_practice_route_can_be_denied_from_one_common_boundary(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        config()->set('entitlements.features.ai_practice.free', false);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertForbidden();
    }

    public function test_question_pack_can_be_denied_without_disabling_ai_practice_itself(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        config()->set('entitlements.features.ai_practice.free', true);
        config()->set('entitlements.features.question_pack.free', false);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.prepare', [$plan, $task]), [
                'prepare_request_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk();
    }

    public function test_project_artifact_routes_use_the_same_access_boundary(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '個人開発');
        config()->set('entitlements.features.project_artifact.free', false);

        $this->actingAs($user)
            ->get(route('plans.artifacts.index', $plan))
            ->assertForbidden();
    }

    public function test_default_policy_can_be_flipped_without_adding_billing_fields_to_user(): void
    {
        config()->set('entitlements.default_policy', 'deny');
        config()->set('entitlements.features.automatic_ai_execution.free', null);

        $decision = app(FeatureAccessService::class)
            ->resolveAccess(User::factory()->create(), FeatureKey::AutomaticAiExecution);

        $this->assertFalse($decision->allowed);
        $this->assertNull($decision->source);
        $this->assertSame('no_entitlement', $decision->reason);
        $this->assertFalse(array_key_exists('is_premium', (new User())->getAttributes()));
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '資格学習');
        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => '弱点を演習する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function plan(User $user, string $category): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Entitlement test',
            'category' => $category,
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }
}
