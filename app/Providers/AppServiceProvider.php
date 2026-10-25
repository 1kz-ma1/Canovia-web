<?php

namespace App\Providers;

use App\Contracts\ExecutionActivityProjector;
use App\Contracts\ExecutionProviderCatalog;
use App\Models\Task;
use App\Services\AdminAccessService;
use App\Services\AdminPreviewContext;
use App\Services\CoreContextService;
use App\Services\Entitlements\FreeEntitlementResolver;
use App\Services\Entitlements\GiftProductGrantEntitlementResolver;
use App\Services\Entitlements\ProductGrantEntitlementResolver;
use App\Services\Entitlements\SponsorProductGrantEntitlementResolver;
use App\Services\ExecutionProviderRegistry;
use App\Services\FeatureAccessService;
use App\Services\PlanLifecyclePersonalizationAdapter;
use App\Services\PlanOwnershipService;
use App\Services\ReleaseLevelService;
use App\Services\RequestBehaviorHistory;
use App\Services\TaskEvidenceExecutionActivityProjector;
use App\Support\RequestPerformance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\PreparationExecutionGuard::class);
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
                $app->make(ReleaseLevelService::class),
            ),
        );
    }

    public function boot(): void
    {
        // dispatchNow and non-queued dispatchSync do not emit Queue::before.
        // Keep the same restrictive profile at the command handler boundary.
        \Illuminate\Support\Facades\Bus::pipeThrough([
            \App\Bus\Middleware\EnsurePreparationExecution::class,
        ]);
        // Recheck in the running process, before any queued handler (including
        // old deliveries). Queue reservation/failure bookkeeping is not a
        // proof of zero DB writes or a substitute for a stopped writer window.
        // A JobProcessing exception can still update attempts/release a delivery.
        // The managed daemon must stop BEFORE resolving a broker or popping again.
        \Illuminate\Support\Facades\Queue::looping(function (): ?bool {
            if (app(\App\Services\PreparationExecutionGuard::class)->stopped()) {
                app('queue.worker')->shouldQuit = true;

                return false;
            }

            return null;
        });
        \Illuminate\Support\Facades\Queue::before(function (): void {
            if (app(\App\Services\PreparationExecutionGuard::class)->stopped()) {
                throw new \RuntimeException('PREPARATION_QUEUE_CLOSED');
            }
        });
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\CommandStarting::class,
            function ($event): void {
                if (app(\App\Services\PreparationExecutionGuard::class)->stopped()
                    && ! in_array($event->command, [
                        'list', 'help', 'about', 'serve', 'up', 'down',
                        'config:cache', 'config:clear', 'route:cache', 'route:clear',
                        'view:cache', 'view:clear', 'canovia:release-schema-ready',
                    ], true)) {
                    throw new \RuntimeException('PREPARATION_CONSOLE_CLOSED');
                }
            },
        );

        // V6.11 confirmation must not be exhausted by the different Study
        // answer/start/next endpoints sharing numeric throttle buckets.
        // Register in the provider so the named limiter survives route:cache.
        RateLimiter::for('learning-next-action-confirm', fn (Request $request) => Limit::perMinute(8)->by($request->user()
                ? 'user:'.$request->user()->getAuthIdentifier()
                : 'guest-ip:'.$request->ip()
        )
        );

        // V6.13: starting a new Bank Run is a distinct user action.
        // Keep the established 12/min bound but isolate it from unrelated
        // Study POST endpoints in the shared anonymous numeric limiter.
        RateLimiter::for('learning-bank-start', fn (Request $request) => Limit::perMinute(12)->by($request->user()
                ? 'user:'.$request->user()->getAuthIdentifier()
                : 'guest-ip:'.$request->ip()
        )
        );

        Task::updated(function (Task $task): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();
            if (! $request->user()) {
                return;
            }

            app(PlanLifecyclePersonalizationAdapter::class)
                ->observeTaskUpdated($request, $task);
        });

        if ((bool) config('performance.enabled', false)) {
            DB::listen(function (QueryExecuted $event): void {
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
