<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Exceptions\ContentRefusedException;
use App\AI\Exceptions\InvalidResponseException;
use App\AI\ModelRegistry;
use App\AI\ProviderFactory;
use App\AI\Requests\TextGenerationRequest;
use App\AI\Results\TextResult;
use App\Clients\Exceptions\RateLimitException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

/**
 * One driver serves Groq, Gemini, OpenRouter, and any other vendor speaking
 * the OpenAI format, so its quirks are tested once here. Each case below is
 * something a real free model did during the first live probe.
 */
final class OpenAiCompatibleProviderTest extends TestCase
{
    use SeedsAiCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.credentials.groq' => 'gsk-test-key', 'ai.credentials.openrouter' => 'sk-or-test-key']);
    }

    /**
     * @return \Generator<int, string, mixed, TextResult>
     */
    private function stream(string $modelKey, string $prompt = 'Hi'): \Generator
    {
        $model = $this->app->make(ModelRegistry::class)->get($modelKey);

        return $this->app->make(ProviderFactory::class)->textGenerator($model)
            ->streamText(new TextGenerationRequest(userPrompt: $prompt, systemPrompt: 'Be brief.'), $model);
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     */
    private function sse(array $chunks): string
    {
        return implode('', array_map(fn (array $c): string => 'data: '.json_encode($c)."\n\n", $chunks))."data: [DONE]\n\n";
    }

    #[Test]
    public function it_sends_the_vendor_model_id_the_key_and_the_models_own_extra_parameters(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3],
        ])]);

        $model = $this->app->make(ModelRegistry::class)->get('groq-gpt-oss-120b');
        $result = $this->app->make(ProviderFactory::class)->textGenerator($model)
            ->generateText(new TextGenerationRequest(userPrompt: 'Hi', systemPrompt: 'Be brief.'), $model);

        $this->assertSame('Hello.', $result->text);
        $this->assertSame('groq', $result->provider());
        $this->assertSame(3, $result->usage()->outputTokens);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer gsk-test-key')
                && $request['model'] === 'openai/gpt-oss-120b'
                && $request['messages'][0] === ['role' => 'system', 'content' => 'Be brief.']
                // From the model's row, not the code: without it this
                // reasoning model can think away a small budget entirely.
                && $request['reasoning_effort'] === 'low';
        });
    }

    #[Test]
    public function streaming_yields_only_the_answer_never_the_reasoning(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->sse([
            ['choices' => [['delta' => ['reasoning' => 'The user wants a greeting.']]]],
            ['choices' => [['delta' => ['content' => 'Hel']]]],
            ['choices' => [['delta' => ['content' => 'lo.']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 7]],
        ]))]);

        $stream = $this->stream('groq-gpt-oss-120b');

        $this->assertSame(['Hel', 'lo.'], iterator_to_array($stream, preserve_keys: false));

        $result = $stream->getReturn();
        $this->assertSame('Hello.', $result->text);
        // Usage arrives in its own final chunk. Missing it would leave every
        // streamed call unpriced and invisible to the spend cap.
        $this->assertSame(20, $result->usage()->inputTokens);
        $this->assertSame(7, $result->usage()->outputTokens);

        Http::assertSent(fn (Request $request): bool => $request['stream_options'] === ['include_usage' => true]);
    }

    #[Test]
    public function an_answer_lost_to_reasoning_is_an_error_not_an_empty_reply(): void
    {
        // What gpt-oss did in the live probe with max_tokens=200.
        Http::fake(['api.groq.com/*' => Http::response($this->sse([
            ['choices' => [['delta' => ['reasoning' => str_repeat('thinking ', 50)]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'length']]],
        ]))]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/whole token budget/');

        iterator_to_array($this->stream('groq-gpt-oss-120b'));
    }

    #[Test]
    public function an_error_in_the_middle_of_a_stream_is_not_mistaken_for_a_short_answer(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'Partial']]]])."\n\n".
            'data: '.json_encode(['error' => ['message' => 'Upstream overloaded']])."\n\n"
        )]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/Upstream overloaded/');

        iterator_to_array($this->stream('groq-qwen3.8-27b'));
    }

    #[Test]
    public function a_content_filter_stop_is_a_refusal(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->sse([
            ['choices' => [['delta' => [], 'finish_reason' => 'content_filter']]],
        ]))]);

        $this->expectException(ContentRefusedException::class);

        iterator_to_array($this->stream('groq-qwen3.8-27b'));
    }

    #[Test]
    public function an_upstream_rate_limit_keeps_its_type_and_gains_the_vendors_explanation(): void
    {
        // OpenRouter's shared free pool, as seen live: a 429 whose useful
        // detail is nested in metadata.raw.
        Http::fake(['openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'Provider returned error',
                'code' => 429,
                'metadata' => ['raw' => 'google/gemma-4-31b-it:free is temporarily rate-limited upstream.'],
            ],
        ], 429)]);

        try {
            iterator_to_array($this->stream('openrouter-gemma-4-31b'));
            $this->fail('Expected a rate limit.');
        } catch (RateLimitException $e) {
            // Still a RateLimitException, so the gateway falls back to the
            // next model exactly as it should.
            $this->assertStringContainsString('rate-limited upstream', $e->getMessage());
        }
    }
}
