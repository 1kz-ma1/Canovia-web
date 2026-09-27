<?php

namespace App\Providers;

use App\Services\Entitlements\FreeEntitlementResolver;
use App\Services\Entitlements\GiftProductGrantEntitlementResolver;
use App\Services\Entitlements\ProductGrantEntitlementResolver;
use App\Services\Entitlements\SponsorProductGrantEntitlementResolver;
use App\Services\AdminAccessService;
use App\Services\AdminPreviewContext;
use App\Services\FeatureAccessService;
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
