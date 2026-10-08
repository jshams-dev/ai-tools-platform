<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tools\ToolRegistry;
use App\Tools\ToolRunner;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the tool layer: which tools exist, and how one is run.
 *
 * Separate from AiServiceProvider because tools and vendors are different
 * bounded contexts — a tool that never calls AI still needs this one.
 * Deferred for the same reason that one is: it only binds.
 */
final class ToolServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, fn (): ToolRegistry => new ToolRegistry(
            definitions: config('tools.definitions', []),
        ));

        $this->app->singleton(ToolRunner::class, fn ($app): ToolRunner => new ToolRunner(
            container: $app,
            validator: $app->make(ValidatorFactory::class),
            limiter: $app->make(RateLimiter::class),
            maxRunsPerWindow: (int) config('tools.throttle.max_runs', 20),
            windowSeconds: (int) config('tools.throttle.window_seconds', 60),
        ));
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            ToolRegistry::class,
            ToolRunner::class,
        ];
    }
}
