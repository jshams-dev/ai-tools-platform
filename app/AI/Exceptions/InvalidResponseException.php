<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * The vendor answered with HTTP 200 and a body we cannot make sense of.
 *
 * Distinct from a transport error: the call succeeded, so retrying the exact
 * same request is unlikely to help. Usually it means the vendor changed its
 * response shape and the provider needs updating.
 */
final class InvalidResponseException extends AiException
{
    public static function missingField(string $provider, string $field): self
    {
        return new self("Response from '{$provider}' is missing the expected field '{$field}'.");
    }
}
