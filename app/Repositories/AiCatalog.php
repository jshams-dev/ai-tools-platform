<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AiModel;
use App\Models\AiProvider;

/**
 * Reads providers and models from the database, in the plain-array shapes
 * ModelRegistry and ProviderFactory take.
 *
 * Those two classes were written against arrays shaped like the future
 * tables, so moving the data into the database is this class and nothing
 * more — the AI layer above it never learns that Eloquent exists.
 */
final class AiCatalog
{
    /**
     * @param  array<string, class-string>  $drivers  config('ai.drivers')
     * @param  array<string, string|null>  $credentials  config('ai.credentials')
     */
    public function __construct(
        private readonly array $drivers,
        private readonly array $credentials,
        private readonly ?string $defaultProxy = null,
    ) {}

    /**
     * Every usable model, keyed by our model key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function models(): array
    {
        return AiModel::query()
            ->usable()
            ->with('provider')
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (AiModel $model): array => [$model->key => [
                'provider' => $model->provider->slug,
                'api_id' => $model->api_id,
                'capabilities' => $model->capabilities,
                'tier' => $model->tier,
                'streaming' => $model->supports_streaming,
                'sampling' => $model->supports_sampling,
                'max_output_tokens' => $model->max_output_tokens,
                'cost' => [
                    'input' => (float) $model->input_cost_per_million,
                    'output' => (float) $model->output_cost_per_million,
                ],
                'request_options' => $model->settings['request_options'] ?? [],
            ]])
            ->all();
    }

    /**
     * Every active provider, keyed by slug, with its driver class and key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        return AiProvider::query()
            ->active()
            ->get()
            ->mapWithKeys(fn (AiProvider $provider): array => [$provider->slug => [
                ...($provider->settings ?? []),
                'slug' => $provider->slug,
                'driver' => $this->drivers[$provider->driver] ?? throw new \UnexpectedValueException(
                    "Provider '{$provider->slug}' names the unknown driver '{$provider->driver}'. ".
                    'Known drivers are listed under ai.drivers.'
                ),
                'key' => $this->credentials[$provider->slug] ?? null,
                'base_url' => $provider->base_url,
                'proxy' => $provider->proxy ?? $this->defaultProxy,
                'settings' => $provider->settings ?? [],
            ]])
            ->all();
    }
}
