<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * The model declined to produce the requested output.
 *
 * This arrives as a successful HTTP response, which is exactly why it needs
 * its own type: without one, a refusal reads as an empty-but-valid result and
 * a tool cheerfully saves an empty hotel description.
 */
final class ContentRefusedException extends AiException
{
    public function __construct(
        string $message,
        public readonly ?string $category = null,
    ) {
        parent::__construct($message);
    }
}
