<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\Capability;
use App\AI\Exceptions\UnknownModelException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\ModelRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The registry reads plain arrays, so it needs no framework booted — which is
 * itself a sign the boundary is in the right place.
 */
final class ModelRegistryTest extends TestCase
{
    /**
     * @param  array<string, array<string, mixed>>|null  $models
     * @param  array<string, string>  $defaults
     */
    private function registry(?array $models = null, array $defaults = []): ModelRegistry
    {
        return new ModelRegistry($models ?? [
            'fast' => [
                'provider' => 'anthropic',
                'api_id' => 'claude-haiku-4-5',
                'capabilities' => ['text_generation'],
                'tier' => 'free',
                'streaming' => true,
                'max_output_tokens' => 8192,
                'cost' => ['input' => 1.0, 'output' => 5.0],
            ],
            'translate' => [
                'provider' => 'deepl',
                'api_id' => 'v2',
                'capabilities' => ['translation'],
            ],
        ], $defaults);
    }

    #[Test]
    public function it_hydrates_a_model_from_config(): void
    {
        $model = $this->registry()->get('fast');

        $this->assertSame('fast', $model->key);
        $this->assertSame('anthropic', $model->provider);
        $this->assertSame('claude-haiku-4-5', $model->apiId);
        $this->assertSame('free', $model->tier);
        $this->assertTrue($model->supportsStreaming);
        $this->assertSame(8192, $model->maxOutputTokens);
        $this->assertTrue($model->supports(Capability::TextGeneration));
        $this->assertFalse($model->supports(Capability::Translation));
    }

    #[Test]
    public function it_applies_sensible_defaults_for_omitted_fields(): void
    {
        $model = $this->registry()->get('translate');

        $this->assertSame('free', $model->tier);
        $this->assertFalse($model->supportsStreaming);
        $this->assertNull($model->maxOutputTokens);
        // Sampling defaults to allowed: most models accept it, and the ones
        // that do not are the exception that has to be declared.
        $this->assertTrue($model->supportsSampling);
    }

    #[Test]
    public function it_names_the_missing_key_when_a_model_is_unknown(): void
    {
        $this->expectException(UnknownModelException::class);
        $this->expectExceptionMessageMatches("/'gpt-9'/");

        $this->registry()->get('gpt-9');
    }

    #[Test]
    public function it_rejects_a_capability_string_that_is_not_in_the_enum(): void
    {
        // A typo in config would otherwise produce a model that silently
        // matches nothing, and the failure would surface far from its cause.
        $registry = $this->registry([
            'typo' => ['provider' => 'anthropic', 'capabilities' => ['text_generaton']],
        ]);

        $this->expectException(UnknownModelException::class);
        $this->expectExceptionMessageMatches('/unknown capability/');

        $registry->get('typo');
    }

    #[Test]
    public function it_resolves_the_default_model_for_a_capability(): void
    {
        $registry = $this->registry(defaults: ['text_generation' => 'fast']);

        $this->assertSame('fast', $registry->defaultFor(Capability::TextGeneration)->key);
    }

    #[Test]
    public function it_refuses_a_default_that_cannot_do_the_job(): void
    {
        // Pointing the text-generation default at a translation-only model is
        // a config error worth failing loudly on, not a null to propagate.
        $registry = $this->registry(defaults: ['text_generation' => 'translate']);

        $this->expectException(UnsupportedCapabilityException::class);

        $registry->defaultFor(Capability::TextGeneration);
    }

    #[Test]
    public function it_lists_every_model_serving_a_capability(): void
    {
        $keys = array_map(
            fn ($m): string => $m->key,
            $this->registry()->supporting(Capability::TextGeneration),
        );

        $this->assertSame(['fast'], $keys);
    }

    #[Test]
    public function it_caches_hydrated_models(): void
    {
        $registry = $this->registry();

        $this->assertSame($registry->get('fast'), $registry->get('fast'));
    }
}
