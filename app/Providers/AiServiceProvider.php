<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\AiGateway;
use App\AI\AiResponseCache;
use App\AI\ModelRegistry;
use App\AI\ModelResolver;
use App\AI\ProviderFactory;
use App\Repositories\EloquentUsageLogRepository;
use App\Repositories\UsageLogRepository;
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
        $this->app->singleton(ModelRegistry::class, fn (): ModelRegistry => new ModelRegistry(
            models: config('ai.models', []),
            defaults: config('ai.defaults', []),
        ));

        $this->app->singleton(ProviderFactory::class, fn (): ProviderFactory => new ProviderFactory(
            providers: config('ai.providers', []),
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
            ModelRegistry::class,
            ProviderFactory::class,
            ModelResolver::class,
            AiResponseCache::class,
            UsageLogRepository::class,
            AiGateway::class,
        ];
    }
}
