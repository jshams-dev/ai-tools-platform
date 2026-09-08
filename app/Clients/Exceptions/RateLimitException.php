<?php

declare(strict_types=1);

namespace App\Clients\Exceptions;

/**
 * HTTP 429. The vendor is asking us to slow down.
 */
final class RateLimitException extends HttpClientException
{
    public function __construct(
        string $message,
        ?int $statusCode = null,
        ?string $responseBody = null,
        /** Seconds to wait, parsed from the Retry-After header when present. */
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $responseBody, $previous);
    }

    public function isRetryable(): bool
    {
        return true;
    }

    /**
     * Overridden so that re-wording the message does not discard the
     * Retry-After value, which is the whole reason this subclass exists.
     */
    public function withMessage(string $message): static
    {
        return new self($message, $this->statusCode, $this->responseBody, $this->retryAfter, $this);
    }
}
