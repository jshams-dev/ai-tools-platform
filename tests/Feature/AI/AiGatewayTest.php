<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\AiGateway;
use App\AI\AiResponseCache;
use App\AI\Exceptions\ContentRefusedException;
use App\AI\ModelPolicy;
use App\AI\ModelRegistry;
use App\AI\Requests\TextGenerationRequest;
use App\AI\Requests\TranslationRequest;
use App\Clients\Exceptions\BadRequestException;
use App\Clients\Exceptions\ServiceUnavailableException;
use App\Models\UsageLog;
use App\Usage\RunContext;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The gateway is the single choke point, so these tests are about the
 * guarantees that only hold if it is written correctly: the cache is consulted
 * before money is spent, every attempt is logged, and the fallback chain
 * degrades quality instead of failing.
 */
final class AiGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.anthropic.key' => 'sk-ant-test-key',
            'ai.providers.deepl.key' => 'deepl-test-key',
            'ai.cache.store' => 'array',
        ]);
    }

    private function gateway(): AiGateway
    {
        return $this->app->make(AiGateway::class);
    }

    private function context(?ModelPolicy $policy = null, string $tier = RunContext::TIER_FREE, ?string $requested = null): RunContext
    {
        return new RunContext(
            toolSlug: 'text-transformer',
            policy: $policy ?? ModelPolicy::tiered(free: ['claude-haiku'], pro: ['claude-sonnet']),
            ipHash: RunContext::hashIp('203.0.113.7'),
            tier: $tier,
            requestedModel: $requested,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function anthropicOk(array $overrides = []): ResponseSequence|PromiseInterface
    {
        return Http::response(array_merge([
            'content' => [['type' => 'text', 'text' => 'A quiet harbour town.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ], $overrides));
    }

    #[Test]
    public function it_runs_a_request_and_logs_the_call(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        $result = $this->gateway()->run(
            new TextGenerationRequest(userPrompt: 'Describe a hotel.'),
            $this->context(),
        );

        $this->assertSame('A quiet harbour town.', $result->toArray()['text']);

        $log = UsageLog::sole();
        $this->assertSame('text-transformer', $log->tool_slug);
        $this->assertSame('text_generation', $log->capability);
        $this->assertSame('anthropic', $log->provider);
        $this->assertSame('claude-haiku', $log->model);
        $this->assertSame(UsageLog::STATUS_OK, $log->status);
        $this->assertSame(100, $log->input_tokens);
        $this->assertSame(50, $log->output_tokens);
        $this->assertFalse($log->was_cached);

        // Haiku: $1/M in, $5/M out -> 100*1e-6 + 50*5e-6 = 0.00035
        $this->assertSame('0.000350', $log->cost_usd);
    }

    #[Test]
    public function it_records_the_caller_as_a_guest_without_a_user_id(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        $ctx = RunContext::forGuest(
            'text-transformer',
            ModelPolicy::tiered(free: ['claude-haiku']),
            '203.0.113.9',
        );

        $this->gateway()->run(new TextGenerationRequest(userPrompt: 'Hi'), $ctx);

        $log = UsageLog::sole();
        $this->assertNull($log->user_id);
        // The raw address must never reach the table.
        $this->assertNotSame('203.0.113.9', $log->ip_hash);
        $this->assertSame(64, strlen($log->ip_hash));
    }

    #[Test]
    public function a_second_identical_call_is_served_from_cache(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        $request = new TextGenerationRequest(userPrompt: 'Describe a hotel.');

        $first = $this->gateway()->run($request, $this->context());
        $second = $this->gateway()->run($request, $this->context());

        $this->assertSame($first->toArray()['text'], $second->toArray()['text']);

        // The vendor was paid once.
        Http::assertSentCount(1);

        $logs = UsageLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertFalse($logs[0]->was_cached);
        $this->assertTrue($logs[1]->was_cached);

        // A cache hit cost nothing, however many tokens the original consumed.
        // Recording the original cost again would double-count it in the daily
        // spend total that the cap is about to depend on.
        $this->assertSame('0.000000', $logs[1]->cost_usd);
    }

    #[Test]
    public function changing_the_prompt_version_bypasses_the_cache(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        $this->gateway()->run(new TextGenerationRequest(userPrompt: 'Same.', promptVersion: 1), $this->context());
        $this->gateway()->run(new TextGenerationRequest(userPrompt: 'Same.', promptVersion: 2), $this->context());

        // Without this, editing a prompt in code keeps serving results the old
        // version produced, and the change looks like it did nothing.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_falls_back_to_the_next_model_when_a_vendor_is_down(): void
    {
        // Both models are Anthropic here, so one fake covers the sequence;
        // what is under test is the gateway walking the chain, not the vendor.
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Overloaded']], 503)
            ->push(['error' => ['message' => 'Overloaded']], 503)
            ->push(['error' => ['message' => 'Overloaded']], 503)
            ->push([
                'content' => [['type' => 'text', 'text' => 'Served by the fallback.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])]);

        $result = $this->gateway()->run(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context(ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'])),
        );

        $this->assertSame('Served by the fallback.', $result->toArray()['text']);

        // One failure row for the first model, one success row for the second.
        // Logging per attempt is what makes a chain that quietly fails over
        // every single time visible at all.
        $logs = UsageLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('claude-haiku', $logs[0]->model);
        $this->assertSame(UsageLog::STATUS_FAILED, $logs[0]->status);
        $this->assertSame('ServiceUnavailableException', $logs[0]->error_code);
        $this->assertSame('claude-sonnet', $logs[1]->model);
        $this->assertSame(UsageLog::STATUS_OK, $logs[1]->status);
    }

    #[Test]
    public function it_does_not_walk_the_chain_for_a_failure_that_will_repeat(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['error' => ['message' => 'max_tokens is too large']], 400,
        )]);

        try {
            $this->gateway()->run(
                new TextGenerationRequest(userPrompt: 'Hi'),
                $this->context(ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'])),
            );
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException) {
            // A malformed request fails identically on every vendor. Trying
            // the whole chain just multiplies the latency of a certain
            // failure — and a 400 is not retried inside the transport either.
            Http::assertSentCount(1);
            $this->assertCount(1, UsageLog::all());
        }
    }

    #[Test]
    public function a_refusal_is_not_retried_on_another_model(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
        ])]);

        $this->expectException(ContentRefusedException::class);

        try {
            $this->gateway()->run(
                new TextGenerationRequest(userPrompt: 'Something disallowed.'),
                $this->context(ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'])),
            );
        } finally {
            // Shopping a refused prompt around other models is both futile and
            // exactly the wrong behaviour to build in.
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function it_rethrows_the_last_failure_when_every_model_fails(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'down']], 503)]);

        $this->expectException(ServiceUnavailableException::class);

        try {
            $this->gateway()->run(
                new TextGenerationRequest(userPrompt: 'Hi'),
                $this->context(ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'])),
            );
        } finally {
            // Two models, each retried up to the transport's 3 attempts.
            Http::assertSentCount(6);
            $this->assertCount(2, UsageLog::all());
        }
    }

    #[Test]
    public function it_honours_a_valid_user_model_choice(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        $policy = ModelPolicy::tiered(
            free: ['claude-haiku', 'claude-sonnet'],
            pro: ['claude-haiku', 'claude-sonnet'],
            userSelectable: true,
        );

        $this->gateway()->run(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context($policy, requested: 'claude-sonnet'),
        );

        $this->assertSame('claude-sonnet', UsageLog::sole()->model);
        // The log records our internal key; the wire carries the vendor's
        // api_id. Keeping them distinct is what makes a vendor model rename
        // one config line instead of a data migration.
        Http::assertSent(fn (Request $r): bool => $r->data()['model'] === 'claude-sonnet-5');
    }

    #[Test]
    public function it_ignores_a_model_the_caller_is_not_entitled_to(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        // A free-tier caller asking for a pro model. `requestedModel` is
        // caller-controlled input: honouring it unchecked is how someone
        // spends our money at ten times the rate their plan allows.
        $this->gateway()->run(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context(
                ModelPolicy::tiered(free: ['claude-haiku'], pro: ['claude-opus'], userSelectable: true),
                tier: RunContext::TIER_FREE,
                requested: 'claude-opus',
            ),
        );

        $this->assertSame('claude-haiku', UsageLog::sole()->model);
        Http::assertSent(fn (Request $r): bool => $r->data()['model'] === 'claude-haiku-4-5');
    }

    #[Test]
    public function it_ignores_a_model_choice_when_the_policy_forbids_choosing(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->anthropicOk()]);

        $this->gateway()->run(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context(
                ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet'], userSelectable: false),
                requested: 'claude-sonnet',
            ),
        );

        $this->assertSame('claude-haiku', UsageLog::sole()->model);
    }

    #[Test]
    public function a_cached_fallback_result_does_not_pre_empt_a_healthy_first_choice(): void
    {
        $policy = ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet']);
        $request = new TextGenerationRequest(userPrompt: 'Describe a hotel.');

        // One sequence covers both calls, because re-faking does not reset an
        // exhausted one.
        //   1-3: the preferred model is down (transport retries 3x)
        //     4: the fallback answers, and is cached under *its* key
        //     5: the preferred model has recovered
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push([
                'content' => [['type' => 'text', 'text' => 'From the fallback.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
            ->push([
                'content' => [['type' => 'text', 'text' => 'From the first choice.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])]);

        $first = $this->gateway()->run($request, $this->context($policy));
        $this->assertSame('From the fallback.', $first->toArray()['text']);

        // Second call: the preferred model is reachable again. It must be used
        // — serving the cached fallback answer here would silently hand back
        // worse output while the first choice was perfectly healthy.
        $second = $this->gateway()->run($request, $this->context($policy));

        $this->assertSame('From the first choice.', $second->toArray()['text']);
        $this->assertSame('claude-haiku', UsageLog::latest('id')->first()->model);
    }

    #[Test]
    public function a_fallback_reuses_its_own_cache_when_the_first_choice_stays_down(): void
    {
        $policy = ModelPolicy::tiered(free: ['claude-haiku', 'claude-sonnet']);
        $request = new TextGenerationRequest(userPrompt: 'Describe a hotel.');

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push([
                'content' => [['type' => 'text', 'text' => 'From the fallback.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
            // The first model is still down on the second call…
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['error' => ['message' => 'down']], 503)]);

        $this->gateway()->run($request, $this->context($policy));
        $second = $this->gateway()->run($request, $this->context($policy));

        // …so the fallback answers again — from its own cache, not re-bought.
        $this->assertSame('From the fallback.', $second->toArray()['text']);
        $this->assertTrue(UsageLog::latest('id')->first()->was_cached);

        // 3 failed attempts + 1 success, then 3 failed attempts. The second
        // call never reached the fallback's vendor.
        Http::assertSentCount(7);
    }

    #[Test]
    public function it_dispatches_a_translation_without_knowing_what_translation_is(): void
    {
        Http::fake(['api-free.deepl.com/*' => Http::response([
            'translations' => [['detected_source_language' => 'EN', 'text' => 'سلام دنیا']],
        ])]);

        // The same gateway method, a different capability, and not one line in
        // AiGateway mentions translation. This is the property that makes
        // adding video generation additive.
        $result = $this->gateway()->run(
            new TranslationRequest(text: 'Hello world', targetLanguage: 'fa'),
            $this->context(ModelPolicy::fixed('deepl')),
        );

        $this->assertSame('سلام دنیا', $result->toArray()['text']);

        $log = UsageLog::sole();
        $this->assertSame('translation', $log->capability);
        $this->assertSame('deepl', $log->provider);
        // DeepL bills per character, so there are no tokens to price.
        $this->assertNull($log->input_tokens);
        $this->assertNull($log->cost_usd);
    }

    #[Test]
    public function the_cache_round_trips_each_result_type(): void
    {
        $cache = $this->app->make(AiResponseCache::class);

        Http::fake([
            'api.anthropic.com/*' => $this->anthropicOk(),
            'api-free.deepl.com/*' => Http::response([
                'translations' => [['detected_source_language' => 'EN', 'text' => 'Bonjour']],
            ]),
        ]);

        $text = $this->gateway()->run(new TextGenerationRequest(userPrompt: 'Hi'), $this->context());
        $translated = $this->gateway()->run(
            new TranslationRequest(text: 'Hello', targetLanguage: 'fr'),
            $this->context(ModelPolicy::fixed('deepl')),
        );

        // Both came back out of the cache as their own concrete type, with
        // their type-specific fields intact — the thing an array-plus-tag
        // serialisation has to get right.
        $this->assertInstanceOf($text::class, $cache->get(
            (new TextGenerationRequest(userPrompt: 'Hi'))->cacheKey(
                $this->app->make(ModelRegistry::class)->get('claude-haiku')
            )
        ));

        $this->assertSame('EN', $translated->detectedSourceLanguage);
    }
}
