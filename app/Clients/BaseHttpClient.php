<?php

declare(strict_types=1);

namespace App\Clients;

use App\Clients\Exceptions\BadRequestException;
use App\Clients\Exceptions\HttpClientException;
use App\Clients\Exceptions\InvalidApiKeyException;
use App\Clients\Exceptions\RateLimitException;
use App\Clients\Exceptions\ServiceUnavailableException;
use App\Clients\Exceptions\TimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only place in the application where raw HTTP happens.
 *
 * Everything above this class — providers, the gateway, tools — deals in typed
 * exceptions and decoded arrays, never in status codes or sockets. Two payoffs
 * follow from that single rule:
 *
 *  1. `Http::fake()` in a test intercepts every provider at once, so no fake
 *     provider class has to ship in app/ just to make tests runnable.
 *  2. Timeout, retry, and egress routing (proxy / base URL) are configured in
 *     one place instead of being re-implemented per vendor.
 *
 * This class is deliberately ignorant of AI. A currency or weather API would
 * use it unchanged.
 */
class BaseHttpClient
{
    /**
     * @param  string  $baseUrl  Vendor root, e.g. https://api.anthropic.com.
     *                           Configurable per provider so egress can be
     *                           re-pointed at a relay without touching any
     *                           provider class.
     * @param  array<string, string>  $headers  Sent on every request.
     * @param  string|null  $proxy  Optional per-provider egress proxy.
     * @param  int  $timeout  Seconds to wait for a complete response.
     * @param  int  $streamTimeout  Seconds for streaming responses, which stay
     *                              open far longer than a normal call.
     * @param  int  $maxAttempts  Total attempts, so 3 means 1 try + 2 retries.
     */
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly array $headers = [],
        protected readonly ?string $proxy = null,
        protected readonly int $timeout = 30,
        protected readonly int $streamTimeout = 300,
        protected readonly int $maxAttempts = 3,
        protected readonly int $baseBackoffMs = 500,
        /**
         * Seconds a stream may go silent before it counts as stalled. The
         * total stream timeout is long on purpose, so without this a vendor
         * that stops sending would hold the request open for minutes.
         */
        protected readonly int $streamIdleTimeout = 30,
    ) {}

    /**
     * Send a request and return the decoded JSON body.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  Merged over the client defaults.
     * @return array<string, mixed>
     *
     * @throws HttpClientException
     */
    public function json(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $response = $this->attempt(
            fn (): Response => $this->request($headers)->send($method, $this->url($path), [
                'json' => $payload,
            ])
        );

        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new HttpClientException(
                'Expected a JSON object from '.$this->url($path).', got '.get_debug_type($decoded).'.',
                $response->status(),
                $response->body(),
            );
        }

        return $decoded;
    }

    /**
     * Send a request and yield the raw response body in chunks as it arrives.
     *
     * The chunks are whatever bytes the socket produced — they are not aligned
     * to any protocol boundary. Framing is the caller's job: SSE responses go
     * through {@see ServerSentEvents::parse()}, which handles the RFC-level
     * framing, and the provider then interprets the vendor's event payloads.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return \Generator<int, string>
     *
     * @throws HttpClientException
     */
    public function stream(string $method, string $path, array $payload = [], array $headers = []): \Generator
    {
        // Retry covers connection establishment only. Once the first byte has
        // been handed to the caller, replaying the request would duplicate
        // output that has already been rendered — and, for a metered API, bill
        // us a second time for it.
        $response = $this->attempt(
            fn (): Response => $this->request($headers)
                ->timeout($this->streamTimeout)
                ->withOptions(['stream' => true, 'read_timeout' => $this->streamIdleTimeout])
                ->send($method, $this->url($path), ['json' => $payload])
        );

        $body = $response->toPsrResponse()->getBody();

        while (! $body->eof()) {
            try {
                $chunk = $body->read(8192);
            } catch (\RuntimeException $e) {
                // A read that exceeds read_timeout surfaces as a bare
                // RuntimeException; give it the transport type it deserves.
                throw new TimeoutException(
                    "Stream from {$this->baseUrl} stalled: no data for {$this->streamIdleTimeout} seconds.",
                    previous: $e,
                );
            }

            if ($chunk === '') {
                // A non-eof empty read means the socket has nothing yet.
                // Yielding '' would spin the consumer for no reason.
                continue;
            }

            yield $chunk;
        }
    }

    /**
     * Run a request with backoff, retrying only failures that could plausibly
     * resolve themselves.
     *
     * This is *transport* retry: connection resets, 429s, 5xx. Semantic retry
     * — "the model returned invalid JSON, ask again with a stricter prompt" —
     * belongs in the tool handler, because only the tool knows what valid
     * means. Mixing the two here would retry a malformed prompt three times at
     * full token cost.
     *
     * @param  callable(): Response  $send
     *
     * @throws HttpClientException
     */
    protected function attempt(callable $send): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $send();

                if ($response->successful()) {
                    return $response;
                }

                $exception = $this->translateStatus($response);
            } catch (ConnectionException $e) {
                $exception = new TimeoutException(
                    'Connection to '.$this->baseUrl.' failed: '.$e->getMessage(),
                    previous: $e,
                );
            }

            if (! $exception->isRetryable() || $attempt >= $this->maxAttempts) {
                throw $exception;
            }

            $this->sleep($this->backoffMs($attempt, $exception));
        }
    }

    /**
     * Map an HTTP status onto the typed exception hierarchy.
     *
     * Only generic HTTP semantics are decided here. A vendor's own error
     * envelope — OpenAI's `error.code`, DeepL's message strings — is read by
     * that vendor's provider, because centralising it would mean one class
     * that knows every vendor's error format: exactly the coupling this layer
     * exists to prevent.
     */
    protected function translateStatus(Response $response): HttpClientException
    {
        $status = $response->status();
        $body = $response->body();
        $message = "HTTP {$status} from {$this->baseUrl}";

        return match (true) {
            $status === 401, $status === 403 => new InvalidApiKeyException($message, $status, $body),
            $status === 408 => new TimeoutException($message, $status, $body),
            $status === 429 => new RateLimitException(
                $message,
                $status,
                $body,
                retryAfter: $this->retryAfter($response),
            ),
            $status >= 500 => new ServiceUnavailableException($message, $status, $body),
            default => new BadRequestException($message, $status, $body),
        };
    }

    /**
     * Exponential backoff, but honour Retry-After when the vendor sent one —
     * guessing is strictly worse than being told.
     */
    protected function backoffMs(int $attempt, HttpClientException $exception): int
    {
        if ($exception instanceof RateLimitException && $exception->retryAfter !== null) {
            return $exception->retryAfter * 1000;
        }

        return $this->baseBackoffMs * (2 ** ($attempt - 1));
    }

    protected function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Isolated so tests can subclass and skip the real wait.
     */
    protected function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function request(array $headers = []): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withHeaders([...$this->headers, ...$headers])
            ->timeout($this->timeout)
            ->acceptJson();

        if ($this->proxy !== null && $this->proxy !== '') {
            $request = $request->withOptions(['proxy' => $this->proxy]);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
