<?php

declare(strict_types=1);

namespace App\Clients\Exceptions;

/**
 * The request did not complete within the configured timeout, or the
 * connection failed outright (DNS, refused, reset).
 */
final class TimeoutException extends HttpClientException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
