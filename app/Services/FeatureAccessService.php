<?php

namespace App\Services;

use App\Contracts\EntitlementResolver;
use App\Data\FeatureAccessDecision;
use App\Enums\EntitlementSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\User;

final class FeatureAccessService
{
    /** @var array<int, EntitlementResolver> */
    private array $resolvers;

    /**
     * @param  iterable<EntitlementResolver>  $resolvers
     */
    public function __construct(
        iterable $resolvers,
        private readonly ?AdminAccessService $adminAccess = null,
        private readonly ?AdminPreviewContext $adminPreview = null,
        private readonly ?ReleaseLevelService $releaseLevels = null,
    ) {
        $items = is_array($resolvers) ? $resolvers : iterator_to_array($resolvers);

        usort(
            $items,
            fn (EntitlementResolver $left, EntitlementResolver $right) => $right->priority() <=> $left->priority()
        );

        $this->resolvers = $items;
    }

    /**
     * The single boundary feature code should use when it needs to know whether
     * an actor may use a published feature. Publication maturity precedes
     * entitlement; feature-specific kill switches remain independently required.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolveAccess(?User $actor, FeatureKey $feature, array $context = []): FeatureAccessDecision
    {
        $levels = $this->releaseLevels ?? app(ReleaseLevelService::class);
        if (! $levels->allowsFeature($feature, $actor)) {
            return FeatureAccessDecision::deny($feature, 'feature_not_published');
        }

        if ($actor && $this->adminAccess?->isSuperAdmin($actor)) {
            $previewMode = $this->adminPreview?->mode($actor);

            if ($previewMode === 'free') {
                return $this->freePreviewDecision($feature);
            }

            if ($previewMode === 'premium') {
                return $this->premiumPreviewDecision($feature);
            }

            return FeatureAccessDecision::allow(
                $feature,
                EntitlementSource::Admin,
                'super_admin',
                ['admin_access' => true],
            );
        }

        foreach ($this->resolvers as $resolver) {
            $decision = $resolver->resolve($actor, $feature, $context);

            if ($decision?->allowed) {
                return $decision;
            }
        }

        return FeatureAccessDecision::deny($feature);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function canUse(?User $actor, FeatureKey $feature, array $context = []): bool
    {
        return $this->resolveAccess($actor, $feature, $context)->allowed;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function authorizeUse(?User $actor, FeatureKey $feature, array $context = []): FeatureAccessDecision
    {
        $decision = $this->resolveAccess($actor, $feature, $context);

        abort_unless($decision->allowed, 403, 'この機能を利用する権限がありません。');

        return $decision;
    }

    private function freePreviewDecision(FeatureKey $feature): FeatureAccessDecision
    {
        $configured = config('entitlements.features.'.$feature->value.'.free');
        $defaultAllow = (string) config('entitlements.default_policy', 'allow') === 'allow';
        $allowed = $configured === null ? $defaultAllow : (bool) $configured;

        return $allowed
            ? FeatureAccessDecision::allow($feature, EntitlementSource::Free, 'admin_preview_free')
            : FeatureAccessDecision::deny($feature, 'admin_preview_free');
    }

    private function premiumPreviewDecision(FeatureKey $feature): FeatureAccessDecision
    {
        $free = $this->freePreviewDecision($feature);
        if ($free->allowed) {
            return $free;
        }

        $premiumFeatures = (array) config(
            'economy.products.'.ProductKey::PremiumCore->value.'.feature_keys',
            [],
        );

        if (in_array($feature->value, $premiumFeatures, true)) {
            return FeatureAccessDecision::allow(
                $feature,
                EntitlementSource::Premium,
                'admin_preview_premium',
                ['effective_product_key' => ProductKey::PremiumCore->value],
            );
        }

        return FeatureAccessDecision::deny($feature, 'admin_preview_premium');
    }
}
