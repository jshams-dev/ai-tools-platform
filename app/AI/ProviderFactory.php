<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Contracts\TextGenerator;
use App\AI\Contracts\Translator;
use App\AI\Exceptions\UnknownModelException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\Clients\BaseHttpClient;

/**
 * Builds the right provider for a model, correctly typed.
 *
 * Why a factory rather than a container binding: the choice depends on runtime
 * state — which model the resolver picked for this user, on this tool, in this
 * tier. Container bindings are resolved once per application boot, so they
 * cannot express a per-call decision. That runtime choice is precisely what a
 * factory is for.
 *
 * Why one method per capability instead of a single `for(Capability, ...)`:
 * a single method can only declare `AiProvider` as its return type, so every
 * caller would have to assert its way back to something useful and static
 * analysis would verify nothing. The methods below return narrow types, which
 * turns "this provider cannot do this" into a type error at development time —
 * the claim the architecture makes, actually made true.
 *
 * Adding a capability adds a method here. That is a real cost, and it is the
 * one place the design accepts a central edit: it buys static verification at
 * every one of the many call sites, which an `assert()` cannot.
 */
final class ProviderFactory
{
    /** @var array<string, AiProvider> */
    private array $instances = [];

    /**
     * @param  array<string, array<string, mixed>>  $providers  config('ai.providers')
     * @param  array<string, mixed>  $http  config('ai.http')
     */
    public function __construct(
        private readonly array $providers,
        private readonly array $http = [],
    ) {}

    public function textGenerator(ResolvedModel $model): TextGenerator
    {
        $provider = $this->forCapability($model, Capability::TextGeneration);

        if (! $provider instanceof TextGenerator) {
            throw UnsupportedCapabilityException::forProvider(
                $model->provider,
                Capability::TextGeneration,
                TextGenerator::class,
            );
        }

        return $provider;
    }

    public function translator(ResolvedModel $model): Translator
    {
        $provider = $this->forCapability($model, Capability::Translation);

        if (! $provider instanceof Translator) {
            throw UnsupportedCapabilityException::forProvider(
                $model->provider,
                Capability::Translation,
                Translator::class,
            );
        }

        return $provider;
    }

    /**
     * Shared preamble: the model must declare the capability before we bother
     * constructing anything.
     *
     * The two checks catch genuinely different mistakes. This one is a config
     * error — a model whose capability list does not mention the work asked of
     * it. The instanceof in each public method is a code error — a vendor
     * class that has not implemented the interface yet.
     */
    private function forCapability(ResolvedModel $model, Capability $capability): AiProvider
    {
        if (! $model->supports($capability)) {
            throw UnsupportedCapabilityException::forModel($model, $capability);
        }

        return $this->make($model->provider);
    }

    /**
     * Providers are stateless translators between our calls and one vendor's
     * API, so one instance per vendor is reused for the whole request.
     */
    private function make(string $name): AiProvider
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $config = $this->providers[$name] ?? throw new UnknownModelException(
            "No provider registered under '{$name}'. Check the ai_providers table (is it seeded and active?)."
        );

        /** @var class-string<AiProvider> $driver */
        $driver = $config['driver'];

        return $this->instances[$name] = new $driver(
            $this->httpClientFor($config),
            $config,
        );
    }

    /**
     * Every provider gets its own configured transport.
     *
     * The provider does not build this itself: timeout, retry policy, and
     * egress routing are transport concerns, and letting each vendor class
     * decide them is how four different retry implementations appear.
     *
     * @param  array<string, mixed>  $config
     */
    private function httpClientFor(array $config): BaseHttpClient
    {
        return new BaseHttpClient(
            baseUrl: (string) $config['base_url'],
            proxy: isset($config['proxy']) && $config['proxy'] !== ''
                ? (string) $config['proxy']
                : null,
            timeout: (int) ($this->http['timeout'] ?? 30),
            streamTimeout: (int) ($this->http['stream_timeout'] ?? 300),
            maxAttempts: (int) ($this->http['max_attempts'] ?? 3),
            baseBackoffMs: (int) ($this->http['base_backoff_ms'] ?? 500),
            streamIdleTimeout: (int) ($this->http['stream_idle_timeout'] ?? 30),
        );
    }
}
