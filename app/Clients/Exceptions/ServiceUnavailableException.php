<?php

declare(strict_types=1);

namespace App\Clients\Exceptions;

/**
 * HTTP 5xx. The vendor is having a bad day; the request itself was fine.
 */
final class ServiceUnavailableException extends HttpClientException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
