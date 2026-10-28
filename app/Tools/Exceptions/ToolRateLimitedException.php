<?php

declare(strict_types=1);

namespace App\Tools\Exceptions;

use RuntimeException;

/**
 * The caller has run tools too often in the current window.
 */
final class ToolRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many tool runs. Try again in {$retryAfterSeconds} seconds.");
    }
}
