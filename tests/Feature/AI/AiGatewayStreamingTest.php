<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\AiGateway;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\ModelPolicy;
use App\AI\Requests\TextGenerationRequest;
use App\Clients\Exceptions\ServiceUnavailableException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\UsageLog;
use App\Usage\RunContext;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

/**
 * Streaming through the gateway.
 *
 * The expensive mistakes all live here: usage that arrives only in the
 * closing events, partial output that must never be cached, and a fallback
 * that must stop being allowed the moment a fragment has left the building.
 */
final class AiGatewayStreamingTest extends TestCase
{
    use SeedsAiCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.credentials.anthropic' => 'sk-ant-test-key',
            'ai.cache.store' => 'array',
        ]);
    }

    private function gateway(): AiGateway
    {
        return $this->app->make(AiGateway::class);
    }

    private function context(?ModelPolicy $policy = null): RunContext
    {
        return new RunContext(
            toolSlug: 'text-transformer',
            policy: $policy ?? ModelPolicy::tiered(free: ['claude-haiku']),
            ipHash: RunContext::hashIp('203.0.113.7'),
            tier: RunContext::TIER_FREE,
        );
    }

    private function sse(): string
    {
        return implode('', [
            "event: message_start\n",
            'data: {"type":"message_start","message":{"usage":{"input_tokens":80}}}'."\n\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Sea"}}'."\n\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":" view"}}'."\n\n",
            "event: message_delta\n",
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":20}}'."\n\n",
            "event: message_stop\n",
            'data: {"type":"message_stop"}'."\n\n",
        ]);
    }

    #[Test]
    public function it_yields_fragments_and_logs_usage_from_the_closing_events(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sse())]);

        $stream = $this->gateway()->stream(
            new TextGenerationRequest(userPrompt: 'Describe it.'),
            $this->context(),
        );

        $fragments = iterator_to_array($stream, preserve_keys: false);

        $this->assertSame(['Sea', ' view'], $fragments);
        $this->assertSame('Sea view', $stream->getReturn()->text);

        $log = UsageLog::sole();
        $this->assertSame(UsageLog::STATUS_OK, $log->status);
        // Neither number was knowable when the first fragment went out. A
        // stream that forgets to collect them bills nobody for the call.
        $this->assertSame(80, $log->input_tokens);
        $this->assertSame(20, $log->output_tokens);
        $this->assertSame('0.000180', $log->cost_usd);   // 80*1e-6 + 20*5e-6
    }

    #[Test]
    public function a_completed_stream_is_cached_and_replayed_whole(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sse())]);

        $request = new TextGenerationRequest(userPrompt: 'Describe it.');

        iterator_to_array($this->gateway()->stream($request, $this->context()));
        $replay = iterator_to_array($this->gateway()->stream($request, $this->context()), preserve_keys: false);

        // Served from cache in one piece. Re-chunking it to look live would be
        // theatre that costs latency.
        $this->assertSame(['Sea view'], $replay);
        Http::assertSentCount(1);

        $logs = UsageLog::orderBy('id')->get();
        $this->assertTrue($logs[1]->was_cached);
        $this->assertSame('0.000000', $logs[1]->cost_usd);
    }

    #[Test]
    public function a_stream_that_fails_midway_is_not_cached(): void
    {
        // Fails after one fragment, with an error event on a 200 response.
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(implode('', [
                "event: content_block_delta\n",
                'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Sea"}}'."\n\n",
                "event: error\n",
                'data: {"type":"error","error":{"message":"Overloaded"}}'."\n\n",
            ]))
            ->push($this->sse())]);

        $request = new TextGenerationRequest(userPrompt: 'Describe it.');

        try {
            iterator_to_array($this->gateway()->stream($request, $this->context()));
            $this->fail('Expected the stream to raise.');
        } catch (\Throwable) {
            // expected
        }

        // The next identical call must reach the vendor again: caching a
        // truncated answer would serve the truncation to everyone after.
        iterator_to_array($this->gateway()->stream($request, $this->context()));
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_does_not_fall_back_once_a_fragment_has_been_sent(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(implode('', [
                "event: content_block_delta\n",
                'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Sea"}}'."\n\n",
                "event: error\n",
                'data: {"type":"error","error":{"message":"Overloaded"}}'."\n\n",
            ]))
            ->push($this->sse())]);

        $received = [];

        try {
            foreach ($this->gateway()->stream(
                new TextGenerationRequest(userPrompt: 'Describe it.'),
                $this->context(ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'])),
            ) as $fragment) {
                $received[] = $fragment;
            }
            $this->fail('Expected the stream to raise.');
        } catch (\Throwable) {
            // Restarting on the second model would re-send "Sea" — text the
            // caller has already rendered — and bill us twice for it.
            $this->assertSame(['Sea'], $received);
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function it_falls_back_when_the_first_model_fails_before_yielding(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push($this->sse())]);

        $stream = $this->gateway()->stream(
            new TextGenerationRequest(userPrompt: 'Describe it.'),
            $this->context(ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'])),
        );

        // Nothing had left yet, so switching models is invisible to the caller
        // and costs them nothing.
        $this->assertSame(['Sea', ' view'], iterator_to_array($stream, preserve_keys: false));

        $logs = UsageLog::orderBy('id')->get();
        $this->assertSame('claude-haiku', $logs[0]->model);
        $this->assertSame(UsageLog::STATUS_FAILED, $logs[0]->status);
        $this->assertSame('claude-sonnet', $logs[1]->model);
        $this->assertSame(UsageLog::STATUS_OK, $logs[1]->status);
    }

    #[Test]
    public function it_logs_a_failed_stream_even_though_nothing_was_returned(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'down']], 503)]);

        $this->expectException(ServiceUnavailableException::class);

        try {
            iterator_to_array($this->gateway()->stream(
                new TextGenerationRequest(userPrompt: 'Hi'),
                $this->context(),
            ));
        } finally {
            // Logged in `finally`, which is why the rows worth investigating
            // are the ones that exist at all.
            $log = UsageLog::sole();
            $this->assertSame(UsageLog::STATUS_FAILED, $log->status);
            $this->assertSame('ServiceUnavailableException', $log->error_code);
        }
    }

    #[Test]
    public function it_refuses_to_stream_when_no_model_in_the_chain_can(): void
    {
        // A model that can generate text but not stream it. Streaming support
        // is a property of the model, so a policy listing this one is not
        // wrong — it just cannot serve this path, and the distinction has to
        // be made before any tokens are bought.
        AiModel::create([
            'ai_provider_id' => AiProvider::where('slug', 'anthropic')->sole()->id,
            'key' => 'batch-only',
            'name' => 'Batch only',
            'api_id' => 'claude-haiku-4-5',
            'capabilities' => ['text_generation'],
            'tier' => 'free',
            'supports_streaming' => false,
        ]);

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageMatches('/No streaming-capable model/');

        iterator_to_array($this->gateway()->stream(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context(ModelPolicy::fixed('batch-only')),
        ));
    }

    #[Test]
    public function it_refuses_a_capability_no_model_in_the_chain_declares(): void
    {
        // A different guard, caught earlier: 'deepl' translates and cannot
        // generate text at all, so the chain is empty before streaming is
        // even considered.
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageMatches('/none of which declares that capability/');

        iterator_to_array($this->gateway()->stream(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context(ModelPolicy::fixed('deepl')),
        ));
    }
}
