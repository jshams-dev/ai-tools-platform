<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Capability;
use App\AI\Contracts\TextGenerator;
use App\AI\Contracts\Translator;
use App\AI\Exceptions\UnknownModelException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\ModelRegistry;
use App\AI\ProviderFactory;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\DeepLProvider;
use App\AI\Requests\TextGenerationRequest;
use App\AI\Requests\TranslationRequest;
use App\AI\ResolvedModel;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

/**
 * The factory is where "this provider cannot do that" is supposed to become a
 * type error rather than a production exception. These tests pin down what it
 * actually guarantees.
 */
final class ProviderFactoryTest extends TestCase
{
    use SeedsAiCatalog;

    private function factory(): ProviderFactory
    {
        return $this->app->make(ProviderFactory::class);
    }

    private function model(string $key): ResolvedModel
    {
        return $this->app->make(ModelRegistry::class)->get($key);
    }

    #[Test]
    public function it_returns_a_narrowly_typed_provider_per_capability(): void
    {
        $textGenerator = $this->factory()->textGenerator($this->model('claude-haiku'));
        $translator = $this->factory()->translator($this->model('deepl'));

        // The return types are what matter here. Because textGenerator() is
        // declared to return TextGenerator rather than the base interface,
        // calling generateText() on it is verified statically — no assert(),
        // which would be compiled out in production and guarantee nothing.
        $this->assertInstanceOf(TextGenerator::class, $textGenerator);
        $this->assertInstanceOf(AnthropicProvider::class, $textGenerator);

        $this->assertInstanceOf(Translator::class, $translator);
        $this->assertInstanceOf(DeepLProvider::class, $translator);
    }

    #[Test]
    public function a_translation_only_model_cannot_be_used_to_generate_text(): void
    {
        // The whole argument for capability segregation, as an assertion:
        // DeepL is not a completion API, and the design refuses to pretend.
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageMatches('/does not declare the .text_generation. capability/');

        $this->factory()->textGenerator($this->model('deepl'));
    }

    #[Test]
    public function a_text_only_model_cannot_be_used_to_translate(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);

        $this->factory()->translator($this->model('claude-haiku'));
    }

    #[Test]
    public function it_reports_a_provider_that_has_not_implemented_the_interface(): void
    {
        // A config error the model list cannot catch: this model claims a
        // capability its vendor class does not implement. The message has to
        // name the missing interface, or the reader has no idea what to write.
        $mislabelled = new ResolvedModel(
            key: 'deepl-pretending',
            provider: 'deepl',
            apiId: 'v2',
            capabilities: [Capability::TextGeneration],
        );

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        $this->factory()->textGenerator($mislabelled);
    }

    #[Test]
    public function it_rejects_a_model_pointing_at_an_unregistered_provider(): void
    {
        $orphan = new ResolvedModel(
            key: 'mistral-large',
            provider: 'mistral',
            apiId: 'mistral-large-latest',
            capabilities: [Capability::TextGeneration],
        );

        // A provider row cannot exist without a class to back it — which is
        // why the provider list lives in config rather than a database table.
        $this->expectException(UnknownModelException::class);
        $this->expectExceptionMessageMatches('/No provider registered under .mistral./');

        $this->factory()->textGenerator($orphan);
    }

    #[Test]
    public function it_reuses_one_provider_instance_per_vendor(): void
    {
        // Providers are stateless translators, so rebuilding one per call
        // would allocate a fresh HTTP client each time for no benefit.
        $this->assertSame(
            $this->factory()->textGenerator($this->model('claude-haiku')),
            $this->factory()->textGenerator($this->model('claude-sonnet')),
        );
    }

    #[Test]
    public function a_request_dispatches_itself_to_the_right_provider(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'generated']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
            'api-free.deepl.com/*' => Http::response([
                'translations' => [['text' => 'translated']],
            ]),
        ]);

        $factory = $this->factory();

        // Neither call names a provider. Each request knows its own capability
        // and asks the factory for the matching type — which is why a gateway
        // above this never needs a match statement over capabilities, and adding
        // video generation would not edit a single line of existing code.
        $generated = (new TextGenerationRequest(userPrompt: 'Write.'))
            ->executeOn($factory, $this->model('claude-haiku'));

        $translated = (new TranslationRequest(text: 'Hello', targetLanguage: 'fr'))
            ->executeOn($factory, $this->model('deepl'));

        $this->assertSame('generated', $generated->toArray()['text']);
        $this->assertSame('anthropic', $generated->provider());

        $this->assertSame('translated', $translated->toArray()['text']);
        $this->assertSame('deepl', $translated->provider());
    }
}
