<?php

declare(strict_types=1);

namespace App\AI\Results;

/**
 * A fragment of a model's thinking, streamed alongside its answer.
 *
 * Its own type, never a plain string, so it cannot end up in the answer by
 * accident: everything that builds, caches, or bills the answer only ever
 * collects strings. The UI shows it as a live "what the model is doing"
 * status, and nothing stores it.
 */
final readonly class ReasoningDelta
{
    public function __construct(public string $text) {}
}
