<?php

namespace App\Providers;

use App\Services\Entitlements\FreeEntitlementResolver;
use App\Services\Entitlements\GiftProductGrantEntitlementResolver;
use App\Services\Entitlements\ProductGrantEntitlementResolver;
use App\Services\Entitlements\SponsorProductGrantEntitlementResolver;
use App\Contracts\ExecutionActivityProjector;
use App\Contracts\ExecutionProviderCatalog;
use App\Services\AdminAccessService;
use App\Services\AdminPreviewContext;
use App\Services\FeatureAccessService;
use App\Services\ExecutionProviderRegistry;
use App\Services\TaskEvidenceExecutionActivityProjector;
use App\Services\CoreContextService;
use App\Services\PlanOwnershipService;
use App\Services\RequestBehaviorHistory;
use App\Support\RequestPerformance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FreeEntitlementResolver::class);
        $this->app->singleton(ProductGrantEntitlementResolver::class);
        $this->app->singleton(GiftProductGrantEntitlementResolver::class);
        $this->app->singleton(SponsorProductGrantEntitlementResolver::class);
        $this->app->scoped(RequestBehaviorHistory::class);
        $this->app->scoped(PlanOwnershipService::class);
        $this->app->scoped(CoreContextService::class);
        $this->app->singleton(
            ExecutionProviderRegistry::class,
            fn () => new ExecutionProviderRegistry(
                (bool) config(
                    'canovia.execution_setup_validation_enabled',
                    false,
                ),
            ),
        );
        $this->app->singleton(
            ExecutionProviderCatalog::class,
            fn ($app) => $app->make(ExecutionProviderRegistry::class),
        );
        $this->app->singleton(
            ExecutionActivityProjector::class,
            fn ($app) => $app->make(TaskEvidenceExecutionActivityProjector::class),
        );
        $this->app->tag(
            [
                SponsorProductGrantEntitlementResolver::class,
                GiftProductGrantEntitlementResolver::class,
                ProductGrantEntitlementResolver::class,
                FreeEntitlementResolver::class,
            ],
            'canovia.entitlement_resolvers',
        );

        $this->app->singleton(
            FeatureAccessService::class,
            fn ($app) => new FeatureAccessService(
                $app->tagged('canovia.entitlement_resolvers'),
                $app->make(AdminAccessService::class),
                $app->make(AdminPreviewContext::class),
            ),
        );
    }

    public function boot(): void
    {
        if ((bool) config('performance.enabled', false)) {
            DB::listen(function (\Illuminate\Database\Events\QueryExecuted $event): void {
                if (! app()->bound('request')) {
                    return;
                }

                $metrics = request()->attributes->get(RequestPerformance::ATTRIBUTE);
                if ($metrics instanceof RequestPerformance) {
                    $metrics->add($event);
                }
            });
        }

        if (! app()->environment('production')) {
            return;
        }

        URL::forceScheme('https');

        $canonicalUrl = rtrim((string) config('canovia.canonical_url', ''), '/');
        if ($canonicalUrl !== '') {
            URL::forceRootUrl($canonicalUrl);
        }
    }
}
