<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\TextGenerator;
use App\AI\Exceptions\InvalidResponseException;
use App\AI\ModelRegistry;
use App\AI\ProviderFactory;
use App\AI\Requests\TextGenerationRequest;
use App\AI\ResolvedModel;
use App\AI\Results\TextResult;
use App\Clients\Exceptions\BadRequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

/**
 * Streaming, end to end: SSE bytes in, text fragments out.
 *
 * Worth testing carefully because streaming is where the expensive mistakes
 * live. Token usage arrives only in the closing events, so a stream that
 * forgets to collect it produces calls that are never billed to a user and
 * never counted against the daily spend cap.
 */
final class AnthropicStreamingTest extends TestCase
{
    use SeedsAiCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        // Real-looking credentials: with an empty key the Authorization header
        // collapses to whitespace and the header assertions stop meaning
        // anything, which would hide a genuine auth-header bug.
        config([
            'ai.credentials.anthropic' => 'sk-ant-test-key',
            'ai.credentials.deepl' => 'deepl-test-key',
        ]);
    }

    private function model(): ResolvedModel
    {
        return $this->app->make(ModelRegistry::class)->get('claude-haiku');
    }

    private function provider(): TextGenerator
    {
        return $this->app->make(ProviderFactory::class)->textGenerator($this->model());
    }

    private function sseBody(): string
    {
        return implode('', [
            "event: message_start\n",
            'data: {"type":"message_start","message":{"id":"msg_01","usage":{"input_tokens":25,"output_tokens":0}}}'."\n\n",

            "event: content_block_start\n",
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}'."\n\n",

            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Sea"}}'."\n\n",

            ": keep-alive\n\n",

            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" view"}}'."\n\n",

            "event: content_block_stop\n",
            'data: {"type":"content_block_stop","index":0}'."\n\n",

            "event: message_delta\n",
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":9}}'."\n\n",

            "event: message_stop\n",
            'data: {"type":"message_stop"}'."\n\n",
        ]);
    }

    #[Test]
    public function it_yields_text_fragments_as_they_arrive(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody())]);

        $fragments = [];

        foreach ($this->provider()->streamText(new TextGenerationRequest(userPrompt: 'Describe it.'), $this->model()) as $fragment) {
            $fragments[] = $fragment;
        }

        // Only text_delta events become output. The keep-alive comment and the
        // structural events must not leak into what the user reads.
        $this->assertSame(['Sea', ' view'], $fragments);
    }

    #[Test]
    public function it_returns_the_assembled_result_with_usage_from_the_closing_events(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody())]);

        $stream = $this->provider()->streamText(
            new TextGenerationRequest(userPrompt: 'Describe it.'),
            $this->model(),
        );

        // Drain first: a generator's return value only exists once it is done.
        iterator_to_array($stream);

        $result = $stream->getReturn();

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('Sea view', $result->text);
        $this->assertSame('end_turn', $result->stopReason);

        // input_tokens came from message_start, output_tokens from
        // message_delta. Both are needed to price the call, and neither is
        // available at the moment the first fragment is yielded.
        $this->assertSame(25, $result->usage()->inputTokens);
        $this->assertSame(9, $result->usage()->outputTokens);
    }

    #[Test]
    public function it_prices_a_streamed_call_from_the_models_configured_rates(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody())]);

        $stream = $this->provider()->streamText(
            new TextGenerationRequest(userPrompt: 'Describe it.'),
            $this->model(),
        );
        iterator_to_array($stream);

        // Haiku: $1.00 in / $5.00 out per million tokens.
        $expected = round(25 / 1_000_000 * 1.00 + 9 / 1_000_000 * 5.00, 6);

        $this->assertSame($expected, $stream->getReturn()->usage()->costUsd($this->model()));
    }

    #[Test]
    public function it_asks_the_vendor_to_stream(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody())]);

        iterator_to_array($this->provider()->streamText(
            new TextGenerationRequest(userPrompt: 'Describe it.'),
            $this->model(),
        ));

        Http::assertSent(fn (Request $r): bool => ($r->data()['stream'] ?? false) === true);
    }

    #[Test]
    public function it_raises_an_error_event_that_arrives_mid_stream(): void
    {
        // The vendor already answered 200 and sent tokens before failing.
        // Ignoring the error event would hand the caller a short answer that
        // looks complete — the worst possible outcome, because nothing
        // downstream can tell it apart from a genuinely brief reply.
        Http::fake(['api.anthropic.com/*' => Http::response(implode('', [
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Sea"}}'."\n\n",
            "event: error\n",
            'data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'."\n\n",
        ]))]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/Overloaded/');

        iterator_to_array($this->provider()->streamText(
            new TextGenerationRequest(userPrompt: 'Describe it.'),
            $this->model(),
        ));
    }

    #[Test]
    public function a_rejected_stream_carries_the_vendors_explanation(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['type' => 'error', 'error' => ['message' => 'max_tokens: 999999 > 64000']],
            400,
        )]);

        // The request is only sent on the first read of the stream, so error
        // translation must wrap the iteration, not the call that creates it.
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Anthropic: max_tokens: 999999 > 64000');

        iterator_to_array($this->provider()->streamText(new TextGenerationRequest(userPrompt: 'Hi'), $this->model()));
    }

    #[Test]
    public function it_ignores_thinking_deltas(): void
    {
        // Thinking models emit thinking_delta on the same event type. Treating
        // every delta as output would splice reasoning into the answer.
        Http::fake(['api.anthropic.com/*' => Http::response(implode('', [
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"hmm"}}'."\n\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Answer"}}'."\n\n",
        ]))]);

        $fragments = iterator_to_array($this->provider()->streamText(
            new TextGenerationRequest(userPrompt: 'Think.'),
            $this->model(),
        ), preserve_keys: false);

        $this->assertSame(['Answer'], $fragments);
    }
}
