<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Clients\BaseHttpClient;
use App\Clients\Exceptions\BadRequestException;
use App\Clients\Exceptions\HttpClientException;
use App\Clients\Exceptions\InvalidApiKeyException;
use App\Clients\Exceptions\RateLimitException;
use App\Clients\Exceptions\ServiceUnavailableException;
use App\Clients\Exceptions\TimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The transport layer, tested without any AI in sight — which is the point.
 * A weather API would exercise exactly these behaviours.
 */
final class BaseHttpClientTest extends TestCase
{
    /**
     * Records the backoff it would have waited instead of actually sleeping,
     * so the retry schedule can be asserted without slowing the suite down.
     */
    private function client(int $maxAttempts = 3): BaseHttpClient
    {
        return new class('https://vendor.test', maxAttempts: $maxAttempts) extends BaseHttpClient
        {
            /** @var list<int> */
            public array $slept = [];

            protected function sleep(int $milliseconds): void
            {
                $this->slept[] = $milliseconds;
            }
        };
    }

    #[Test]
    public function it_returns_a_decoded_json_body(): void
    {
        Http::fake(['vendor.test/*' => Http::response(['ok' => true])]);

        $this->assertSame(['ok' => true], $this->client()->json('POST', '/things', ['a' => 1]));

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://vendor.test/things'
            && $r->data() === ['a' => 1]);
    }

    /**
     * @return array<string, array{int, class-string<HttpClientException>}>
     */
    public static function statusCases(): array
    {
        return [
            '401 unauthorized' => [401, InvalidApiKeyException::class],
            '403 forbidden' => [403, InvalidApiKeyException::class],
            '404 not found' => [404, BadRequestException::class],
            '408 request timeout' => [408, TimeoutException::class],
            '422 unprocessable' => [422, BadRequestException::class],
            '429 rate limited' => [429, RateLimitException::class],
            '500 server error' => [500, ServiceUnavailableException::class],
            '503 unavailable' => [503, ServiceUnavailableException::class],
        ];
    }

    /**
     * @param  class-string<HttpClientException>  $expected
     */
    #[Test]
    #[DataProvider('statusCases')]
    public function it_maps_status_codes_onto_typed_exceptions(int $status, string $expected): void
    {
        Http::fake(['vendor.test/*' => Http::response(['e' => 1], $status)]);

        try {
            // maxAttempts 1: this test is about mapping, not retrying.
            $this->client(maxAttempts: 1)->json('GET', '/things');
            $this->fail("Status {$status} should have thrown.");
        } catch (HttpClientException $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertSame($status, $e->statusCode);
        }
    }

    #[Test]
    public function it_retries_a_retryable_failure_and_succeeds(): void
    {
        Http::fake(['vendor.test/*' => Http::sequence()
            ->push(['e' => 1], 503)
            ->push(['e' => 1], 503)
            ->push(['ok' => true], 200)]);

        $client = $this->client();

        $this->assertSame(['ok' => true], $client->json('GET', '/things'));

        // Exponential: 500ms then 1000ms.
        $this->assertSame([500, 1000], $client->slept);
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_does_not_retry_a_failure_that_cannot_resolve_itself(): void
    {
        Http::fake(['vendor.test/*' => Http::response(['e' => 1], 400)]);

        $client = $this->client();

        try {
            $client->json('POST', '/things');
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException) {
            // The same bytes produce the same rejection forever. Retrying a
            // 400 three times just triples the latency of a guaranteed failure.
            Http::assertSentCount(1);
            $this->assertSame([], $client->slept);
        }
    }

    #[Test]
    public function it_gives_up_after_the_configured_number_of_attempts(): void
    {
        Http::fake(['vendor.test/*' => Http::response(['e' => 1], 503)]);

        $this->expectException(ServiceUnavailableException::class);

        try {
            $this->client(maxAttempts: 2)->json('GET', '/things');
        } finally {
            Http::assertSentCount(2);
        }
    }

    #[Test]
    public function it_honours_retry_after_instead_of_guessing(): void
    {
        Http::fake(['vendor.test/*' => Http::sequence()
            ->push(['e' => 1], 429, ['Retry-After' => '7'])
            ->push(['ok' => true], 200)]);

        $client = $this->client();
        $client->json('GET', '/things');

        // Being told beats guessing: 7s from the header, not the 500ms the
        // exponential schedule would have chosen.
        $this->assertSame([7000], $client->slept);
    }

    #[Test]
    public function it_turns_a_connection_failure_into_a_timeout_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        $this->expectException(TimeoutException::class);

        $this->client(maxAttempts: 1)->json('GET', '/things');
    }

    #[Test]
    public function it_rejects_a_body_that_is_not_a_json_object(): void
    {
        Http::fake(['vendor.test/*' => Http::response('<html>gateway error</html>')]);

        // A proxy or WAF returning HTML with a 200 is a real failure mode, and
        // it must not surface as a confusing null further up the stack.
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/Expected a JSON object/');

        $this->client()->json('GET', '/things');
    }

    #[Test]
    public function it_streams_a_body_in_chunks(): void
    {
        Http::fake(['vendor.test/*' => Http::response(str_repeat('x', 20_000))]);

        $chunks = iterator_to_array($this->client()->stream('POST', '/stream'), preserve_keys: false);

        $this->assertGreaterThan(1, count($chunks), 'A large body should arrive in several reads.');
        $this->assertSame(20_000, strlen(implode('', $chunks)));
    }

    /**
     * Exposes the configured request so the transport options can be asserted.
     * Http::fake() records the request but not the Guzzle options applied to
     * it, so there is no way to see the proxy from assertSent().
     */
    private function clientWithProxy(?string $proxy): BaseHttpClient
    {
        return new class('https://vendor.test', proxy: $proxy) extends BaseHttpClient
        {
            /** @return array<string, mixed> */
            public function options(): array
            {
                return $this->request()->getOptions();
            }
        };
    }

    #[Test]
    public function it_routes_through_a_proxy_when_one_is_configured(): void
    {
        // Egress routing is configurable per provider, so traffic can be sent
        // through a relay without any provider class knowing it happened.
        $options = $this->clientWithProxy('http://relay.test:8080')->options();

        $this->assertSame('http://relay.test:8080', $options['proxy']);
    }

    #[Test]
    public function it_omits_the_proxy_option_when_none_is_set(): void
    {
        // An empty env var must not become proxy => '', which Guzzle would
        // treat as a configured proxy and fail every request against.
        $this->assertArrayNotHasKey('proxy', $this->clientWithProxy(null)->options());
        $this->assertArrayNotHasKey('proxy', $this->clientWithProxy('')->options());
    }
}
