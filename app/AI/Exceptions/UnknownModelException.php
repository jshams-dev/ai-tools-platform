<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

final class UnknownModelException extends AiException
{
    public static function forKey(string $key): self
    {
        return new self("No model registered under the key '{$key}'. Check the ai_models table (is it seeded and active?).");
    }
}
