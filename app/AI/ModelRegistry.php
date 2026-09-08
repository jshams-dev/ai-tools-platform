<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Exceptions\UnknownModelException;
use App\AI\Exceptions\UnsupportedCapabilityException;

/**
 * Reads config/ai.php and hands back typed models.
 *
 * Everything above this class deals in ResolvedModel objects, never in config
 * arrays. That is what makes the eventual move to an `ai_models` table a
 * change to this one class: the shape of the config already mirrors the table.
 */
final class ModelRegistry
{
    /** @var array<string, ResolvedModel> */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $models
     * @param  array<string, string>  $defaults  Capability value => model key.
     */
    public function __construct(
        private readonly array $models,
        private readonly array $defaults = [],
    ) {}

    public function get(string $key): ResolvedModel
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $config = $this->models[$key] ?? throw UnknownModelException::forKey($key);

        return $this->resolved[$key] = $this->hydrate($key, $config);
    }

    public function has(string $key): bool
    {
        return isset($this->models[$key]);
    }

    /**
     * The model to use for a capability when nothing more specific was chosen.
     *
     * Once tools exist, a tool's ModelPolicy and the user's tier both outrank
     * this. It is the floor, not the policy.
     */
    public function defaultFor(Capability $capability): ResolvedModel
    {
        $key = $this->defaults[$capability->value]
            ?? throw new UnknownModelException(
                "No default model configured for '{$capability->value}'. ".
                "Add one under 'defaults' in config/ai.php."
            );

        $model = $this->get($key);

        // A default that cannot do the job it is the default for is a config
        // error worth catching loudly, not a null to propagate.
        if (! $model->supports($capability)) {
            throw UnsupportedCapabilityException::forModel($model, $capability);
        }

        return $model;
    }

    /**
     * Every model that can serve a capability, in config order.
     *
     * @return list<ResolvedModel>
     */
    public function supporting(Capability $capability): array
    {
        $matches = [];

        foreach (array_keys($this->models) as $key) {
            $model = $this->get($key);

            if ($model->supports($capability)) {
                $matches[] = $model;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hydrate(string $key, array $config): ResolvedModel
    {
        /** @var list<string> $capabilityValues */
        $capabilityValues = $config['capabilities'] ?? [];

        $capabilities = array_map(
            fn (string $value): Capability => Capability::tryFrom($value)
                ?? throw new UnknownModelException(
                    "Model '{$key}' declares an unknown capability '{$value}' in config/ai.php."
                ),
            $capabilityValues,
        );

        /** @var array{input?: float|int, output?: float|int} $cost */
        $cost = $config['cost'] ?? [];

        return new ResolvedModel(
            key: $key,
            provider: (string) ($config['provider'] ?? throw new UnknownModelException(
                "Model '{$key}' has no provider in config/ai.php."
            )),
            apiId: (string) ($config['api_id'] ?? $key),
            capabilities: array_values($capabilities),
            tier: (string) ($config['tier'] ?? 'free'),
            supportsStreaming: (bool) ($config['streaming'] ?? false),
            supportsSampling: (bool) ($config['sampling'] ?? true),
            inputCostPerMillion: (float) ($cost['input'] ?? 0.0),
            outputCostPerMillion: (float) ($cost['output'] ?? 0.0),
            maxOutputTokens: isset($config['max_output_tokens'])
                ? (int) $config['max_output_tokens']
                : null,
        );
    }
}
