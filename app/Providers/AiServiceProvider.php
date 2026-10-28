<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\AiGateway;
use App\AI\AiResponseCache;
use App\AI\ModelRegistry;
use App\AI\ModelResolver;
use App\AI\ProviderFactory;
use App\Repositories\AiCatalog;
use App\Repositories\EloquentUsageLogRepository;
use App\Repositories\UsageLogRepository;
use App\Usage\CostCircuitBreaker;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI infrastructure into the container.
 *
 * Split from ToolServiceProvider (which arrives with the tool layer) because
 * providers should be divided by bounded context, not by class. This one owns
 * vendors, models, and the gateway; that one will own tool discovery.
 * AppServiceProvider stays for genuinely application-wide concerns.
 *
 * Deferred: it only ever binds, never boots. Most requests — every static page
 * and asset — touch no AI at all, and there is no reason for them to build a
 * model registry to find that out.
 */
final class AiServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(AiCatalog::class, fn (): AiCatalog => new AiCatalog(
            drivers: config('ai.drivers', []),
            credentials: config('ai.credentials', []),
            defaultProxy: config('ai.proxy') ?: null,
        ));

        // Built from the database once per request, on first use. Under
        // Octane these would outlive the request and miss admin edits, so a
        // move there means scoping them to the request — see §10.1.
        $this->app->singleton(ModelRegistry::class, fn ($app): ModelRegistry => new ModelRegistry(
            models: $app->make(AiCatalog::class)->models(),
        ));

        $this->app->singleton(ProviderFactory::class, fn ($app): ProviderFactory => new ProviderFactory(
            providers: $app->make(AiCatalog::class)->providers(),
            http: config('ai.http', []),
        ));

        $this->app->singleton(ModelResolver::class);

        $this->app->singleton(AiResponseCache::class, fn ($app): AiResponseCache => new AiResponseCache(
            // An explicit store, not the default: AI results are large and
            // long-lived, and sharing a store with sessions means one flush
            // takes out both.
            cache: $app->make(CacheFactory::class)->store(config('ai.cache.store')),
            ttlSeconds: (int) config('ai.cache.ttl', 86_400),
        ));

        $this->app->bind(UsageLogRepository::class, EloquentUsageLogRepository::class);

        $this->app->singleton(CostCircuitBreaker::class, fn ($app): CostCircuitBreaker => new CostCircuitBreaker(
            logs: $app->make(UsageLogRepository::class),
            cache: $app->make(CacheFactory::class)->store(),
            dailyCapUsd: (float) config('ai.spend.daily_cap_usd', 5.0),
            recomputeSeconds: (int) config('ai.spend.recompute_seconds', 60),
        ));

        $this->app->singleton(AiGateway::class);
    }

    /**
     * Everything here holds configuration only — nothing request-scoped — so
     * these are safe as singletons under Octane or FrankenPHP. That rule is
     * what keeps the eventual move to FrankenPHP a deployment change rather
     * than a rewrite; request-scoped state travels in RunContext instead.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            AiCatalog::class,
            ModelRegistry::class,
            ProviderFactory::class,
            ModelResolver::class,
            AiResponseCache::class,
            UsageLogRepository::class,
            CostCircuitBreaker::class,
            AiGateway::class,
        ];
    }
}
