<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\ModelRegistry;
use App\AI\ProviderFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI infrastructure into the container.
 *
 * Split from ToolServiceProvider (which arrives with the tool layer) because
 * providers should be divided by bounded context, not by class. This one owns
 * vendors and models; that one will own tool discovery. AppServiceProvider
 * stays for genuinely application-wide concerns.
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
    }

    /**
     * Both are singletons holding only configuration, so they are safe to
     * reuse across requests under Octane or FrankenPHP. Nothing here stores
     * request-scoped state, which is the rule that keeps the eventual move to
     * FrankenPHP a deployment change rather than a rewrite.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return [ModelRegistry::class, ProviderFactory::class];
    }
}
