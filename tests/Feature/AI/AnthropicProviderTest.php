<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Capability;
use App\AI\Contracts\TextGenerator;
use App\AI\Exceptions\ContentRefusedException;
use App\AI\Exceptions\InvalidResponseException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\ModelRegistry;
use App\AI\ProviderFactory;
use App\AI\Requests\TextGenerationRequest;
use App\AI\ResolvedModel;
use App\Clients\Exceptions\BadRequestException;
use App\Clients\Exceptions\InvalidApiKeyException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every one of these tests fakes HTTP rather than the provider.
 *
 * That is possible because all outbound traffic goes through BaseHttpClient,
 * and it is the reason no FakeProvider class ships in app/. The test exercises
 * the real provider, the real payload construction, and the real response
 * parsing — only the socket is replaced.
 */
final class AnthropicProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Real-looking credentials: with an empty key the Authorization header
        // collapses to whitespace and the header assertions stop meaning
        // anything, which would hide a genuine auth-header bug.
        config([
            'ai.providers.anthropic.key' => 'sk-ant-test-key',
            'ai.providers.deepl.key' => 'deepl-test-key',
        ]);
    }

    private function provider(): TextGenerator
    {
        return $this->app->make(ProviderFactory::class)->textGenerator($this->model());
    }

    private function model(string $key = 'claude-haiku'): ResolvedModel
    {
        return $this->app->make(ModelRegistry::class)->get($key);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function messageResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5',
            'content' => [['type' => 'text', 'text' => 'A quiet harbour town.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 42, 'output_tokens' => 17],
        ], $overrides);
    }

    #[Test]
    public function it_generates_text_and_reports_usage(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse())]);

        $result = $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Describe a hotel.'),
            $this->model(),
        );

        $this->assertSame('A quiet harbour town.', $result->text);
        $this->assertSame('anthropic', $result->provider());
        // The internal key, not the vendor's api_id — so a model rename is one
        // config line rather than a data migration of every logged row.
        $this->assertSame('claude-haiku', $result->model());
        $this->assertSame(42, $result->usage()->inputTokens);
        $this->assertSame(17, $result->usage()->outputTokens);
        $this->assertFalse($result->wasTruncated());
    }

    #[Test]
    public function it_sends_the_vendors_wire_format(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse())]);

        $this->provider()->generateText(
            new TextGenerationRequest(
                userPrompt: 'Describe a hotel.',
                systemPrompt: 'You write concisely.',
                maxTokens: 500,
            ),
            $this->model(),
        );

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertSame('2023-06-01', $request->header('anthropic-version')[0]);

            // api_id goes on the wire, never our internal key.
            $this->assertSame('claude-haiku-4-5', $body['model']);
            $this->assertSame('You write concisely.', $body['system']);
            $this->assertSame(500, $body['max_tokens']);
            $this->assertSame(
                [['role' => 'user', 'content' => 'Describe a hotel.']],
                $body['messages'],
            );

            return true;
        });
    }

    #[Test]
    public function it_caps_max_tokens_at_the_models_ceiling(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse())]);

        // Haiku tops out at 8192; asking for more is an API error, so the
        // model's own limit wins over the caller's optimism.
        $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Hi', maxTokens: 999_999),
            $this->model(),
        );

        Http::assertSent(fn (Request $r): bool => $r->data()['max_tokens'] === 8192);
    }

    #[Test]
    public function it_omits_temperature_for_models_that_reject_sampling(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse())]);

        $sonnet = $this->model('claude-sonnet');
        $factory = $this->app->make(ProviderFactory::class);

        $factory->textGenerator($sonnet)->generateText(
            new TextGenerationRequest(userPrompt: 'Hi', temperature: 0.9),
            $sonnet,
        );

        // Newer models return a 400 for sampling parameters rather than
        // ignoring them, so sending temperature unconditionally would make
        // every pro-tier call fail.
        Http::assertSent(fn (Request $r): bool => ! array_key_exists('temperature', $r->data()));
    }

    #[Test]
    public function it_sends_temperature_for_models_that_accept_it(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse())]);

        $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Hi', temperature: 0.25),
            $this->model(),
        );

        Http::assertSent(fn (Request $r): bool => $r->data()['temperature'] === 0.25);
    }

    #[Test]
    public function it_reads_past_non_text_content_blocks(): void
    {
        // A thinking model puts a thinking block first. Code that reads
        // content[0].text returns an empty string here and looks like the
        // model said nothing.
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse([
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'abc'],
                ['type' => 'text', 'text' => 'The answer.'],
            ],
        ]))]);

        $result = $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Think first.'),
            $this->model(),
        );

        $this->assertSame('The answer.', $result->text);
    }

    #[Test]
    public function it_flags_a_truncated_response(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse([
            'stop_reason' => 'max_tokens',
        ]))]);

        $result = $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Write forever.'),
            $this->model(),
        );

        // A tool parsing JSON out of this must know the text is cut off.
        $this->assertTrue($result->wasTruncated());
    }

    #[Test]
    public function it_turns_a_refusal_into_an_exception(): void
    {
        // A refusal is HTTP 200 with no answer. Without its own exception type
        // it reads as a valid empty result and a tool stores a blank record.
        Http::fake(['api.anthropic.com/*' => Http::response($this->messageResponse([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
        ]))]);

        try {
            $this->provider()->generateText(
                new TextGenerationRequest(userPrompt: 'Something disallowed.'),
                $this->model(),
            );
            $this->fail('Expected a ContentRefusedException.');
        } catch (ContentRefusedException $e) {
            $this->assertSame('cyber', $e->category);
        }
    }

    #[Test]
    public function it_rejects_a_response_missing_the_content_field(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_01'])]);

        $this->expectException(InvalidResponseException::class);

        $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->model(),
        );
    }

    #[Test]
    public function it_folds_the_vendor_error_message_into_the_exception(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens is too large'],
        ], 400)]);

        try {
            $this->provider()->generateText(
                new TextGenerationRequest(userPrompt: 'Hi'),
                $this->model(),
            );
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            // The transport layer chose the class from the status code; the
            // provider supplied the explanation, because only it knows that
            // this vendor puts one in error.message.
            $this->assertStringContainsString('max_tokens is too large', $e->getMessage());
            $this->assertSame(400, $e->statusCode);
        }
    }

    #[Test]
    public function it_maps_an_unauthorized_response_to_an_invalid_key_exception(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'invalid x-api-key'],
        ], 401)]);

        $this->expectException(InvalidApiKeyException::class);

        $this->provider()->generateText(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->model(),
        );
    }

    #[Test]
    public function it_refuses_to_stream_a_model_that_cannot(): void
    {
        $fixed = new ResolvedModel(
            key: 'no-stream',
            provider: 'anthropic',
            apiId: 'claude-haiku-4-5',
            capabilities: [Capability::TextGeneration],
            supportsStreaming: false,
        );

        $this->expectException(UnsupportedCapabilityException::class);

        // The generator body does not run until it is iterated.
        iterator_to_array($this->provider()->streamText(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $fixed,
        ));
    }
}
