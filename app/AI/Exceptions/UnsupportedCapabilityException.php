<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Capability;
use App\AI\ResolvedModel;

/**
 * Raised when a model or provider is asked for work it cannot do.
 *
 * With the factory's typed resolution methods, the common form of this mistake
 * — handing a Translator to code that wants to generate text — is caught by
 * static analysis instead. What remains for runtime are the cases only config
 * can produce: a model whose capability list does not include the one asked
 * for, or a vendor class that has not implemented the matching interface yet.
 */
final class UnsupportedCapabilityException extends AiException
{
    public static function forModel(ResolvedModel $model, Capability $capability): self
    {
        return new self(
            "Model '{$model->key}' does not declare the '{$capability->value}' capability. ".
            'Add it to the model\'s capabilities in the ai_models table, or resolve a different model.'
        );
    }

    public static function forProvider(string $provider, Capability $capability, string $interface): self
    {
        return new self(
            "Provider '{$provider}' cannot serve '{$capability->value}': ".
            "its class does not implement {$interface}."
        );
    }

    public static function forStreaming(ResolvedModel $model): self
    {
        return new self("Model '{$model->key}' does not support streaming.");
    }
}
