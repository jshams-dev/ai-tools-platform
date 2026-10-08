<?php

declare(strict_types=1);

namespace Tests\Feature\Usage;

use App\AI\AiGateway;
use App\AI\Exceptions\SpendCapExceededException;
use App\AI\ModelPolicy;
use App\AI\Requests\TextGenerationRequest;
use App\Models\UsageLog;
use App\Usage\RunContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The daily spend cap is the guard that actually protects the account, so
 * the two things worth proving are that it stops vendor calls and that it
 * does not stop the free ones.
 */
final class CostCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.anthropic.key' => 'sk-ant-test-key',
            'ai.cache.store' => 'array',
            'ai.spend.daily_cap_usd' => 1.0,
        ]);
    }

    private function context(): RunContext
    {
        return new RunContext(
            toolSlug: 'text-transformer',
            policy: ModelPolicy::tiered(free: ['claude-haiku']),
            ipHash: RunContext::hashIp('203.0.113.7'),
        );
    }

    private function spend(float $usd): void
    {
        UsageLog::create([
            'ip_hash' => 'x',
            'tool_slug' => 'text-transformer',
            'capability' => 'text_generation',
            'provider' => 'anthropic',
            'model' => 'claude-haiku',
            'cost_usd' => $usd,
            'status' => UsageLog::STATUS_OK,
        ]);
    }

    #[Test]
    public function it_refuses_vendor_calls_once_the_cap_is_reached(): void
    {
        Http::fake();
        $this->spend(1.25);

        $this->expectException(SpendCapExceededException::class);

        try {
            $this->app->make(AiGateway::class)->run(new TextGenerationRequest(userPrompt: 'Hi'), $this->context());
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function it_still_serves_cached_answers_past_the_cap(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Hello.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $gateway = $this->app->make(AiGateway::class);
        $request = new TextGenerationRequest(userPrompt: 'Hi');

        $gateway->run($request, $this->context());

        $this->spend(5.0);
        // Drop only the cached spend total. Both live in the 'array' store in
        // tests, so a flush would also wipe the answer we expect to hit.
        cache()->forget('ai:spend-today:'.now()->toDateString());

        // A cache hit costs nothing, so refusing it would only make the site worse.
        $this->assertSame('Hello.', $gateway->run($request, $this->context())->text);
        Http::assertSentCount(1);
    }

    #[Test]
    public function streaming_is_refused_past_the_cap_too(): void
    {
        Http::fake();
        $this->spend(2.0);

        $this->expectException(SpendCapExceededException::class);

        iterator_to_array($this->app->make(AiGateway::class)->stream(
            new TextGenerationRequest(userPrompt: 'Hi'),
            $this->context(),
        ));
    }
}
