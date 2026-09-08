<?php

declare(strict_types=1);

namespace App\AI\Results;

/**
 * The outcome of one AI call.
 *
 * Every capability returns its own concrete result type; what they share is
 * the metadata the gateway needs to log, price, and cache a call without
 * knowing which capability produced it.
 */
interface AiResult
{
    /** Registry key of the vendor that served the call, e.g. 'anthropic'. */
    public function provider(): string;

    /** Internal model key, e.g. 'claude-haiku' — not the vendor's api_id. */
    public function model(): string;

    public function usage(): TokenUsage;

    /**
     * Serialised form for the response cache.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
