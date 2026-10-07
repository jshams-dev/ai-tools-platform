<?php

declare(strict_types=1);

namespace App\AI\Results;

use App\AI\ResolvedModel;

/**
 * What a call consumed, and what that cost us.
 *
 * Token counts are parsed by each provider from its own response shape — the
 * field names differ per vendor, so only the resulting type is shared.
 */
final readonly class TokenUsage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    /**
     * Cost in USD, from the per-million-token prices in config/ai.php.
     *
     * Returns null when the vendor reported no usage — a translation API that
     * bills per character has no token counts to price, and inventing a zero
     * would understate the daily spend the circuit breaker sums.
     */
    public function costUsd(ResolvedModel $model): ?float
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        $input = ($this->inputTokens ?? 0) / 1_000_000 * $model->inputCostPerMillion;
        $output = ($this->outputTokens ?? 0) / 1_000_000 * $model->outputCostPerMillion;

        return round($input + $output, 6);
    }

    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        return ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
    }
}
