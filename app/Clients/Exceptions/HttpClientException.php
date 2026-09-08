<?php

declare(strict_types=1);

namespace App\Clients\Exceptions;

use RuntimeException;

/**
 * Base class for every transport-level failure.
 *
 * These describe what happened to the *HTTP request* — not what an AI vendor
 * thought of it. Nothing in this namespace knows the word "AI"; a weather API
 * called through BaseHttpClient throws exactly the same types.
 */
class HttpClientException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * Whether retrying the identical request could plausibly succeed.
     *
     * Consulted by BaseHttpClient's retry loop. A 400 is not retryable —
     * the same malformed request will be rejected the same way forever.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * The same failure, carrying a better message.
     *
     * Providers use this to fold a vendor's own explanation into an otherwise
     * generic "HTTP 400" — see AnthropicProvider::translatingErrors(). The
     * class is preserved, so retry semantics and every catch block upstream
     * keep working; only the wording improves.
     *
     * PHP exceptions cannot be cloned, so this rebuilds. Any subclass that
     * carries state beyond the three base arguments must override this and
     * pass that state through — RateLimitException does, or the Retry-After
     * the transport layer just parsed would be silently dropped.
     */
    public function withMessage(string $message): static
    {
        return new static($message, $this->statusCode, $this->responseBody, $this);
    }
}
