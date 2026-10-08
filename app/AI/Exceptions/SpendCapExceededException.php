<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * The platform has spent its daily budget; no further vendor calls today.
 *
 * Not a transport failure, so the gateway never falls back on it — every
 * model in the chain is paid for from the same account.
 */
final class SpendCapExceededException extends AiException
{
    public static function forCap(float $capUsd): self
    {
        return new self(sprintf('The daily AI spend cap of $%.2f has been reached.', $capUsd));
    }
}
