<?php

declare(strict_types=1);

namespace App\Usage;

use App\AI\Exceptions\SpendCapExceededException;
use App\Repositories\UsageLogRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Date;

/**
 * Refuses vendor calls once today's platform spend reaches the cap (§11.3).
 *
 * This is the layer that actually protects the account. A per-IP throttle is
 * defeated by anyone with a few addresses; a ceiling on total spend is not.
 * It costs one `if` in the gateway because the gateway is the only path to a
 * vendor — there is nowhere else a call could slip past it.
 *
 * The total is cached rather than summed per call: summing usage_logs on
 * every request would put an aggregate query on the hot path to save, at
 * most, one window's worth of overshoot.
 */
final class CostCircuitBreaker
{
    public function __construct(
        private readonly UsageLogRepository $logs,
        private readonly Cache $cache,
        private readonly float $dailyCapUsd,
        private readonly int $recomputeSeconds = 60,
    ) {}

    /**
     * @throws SpendCapExceededException
     */
    public function assertWithinDailyBudget(): void
    {
        if ($this->spentToday() >= $this->dailyCapUsd) {
            throw SpendCapExceededException::forCap($this->dailyCapUsd);
        }
    }

    public function spentToday(): float
    {
        // Keyed by date so the total resets at midnight on its own, instead
        // of carrying yesterday's figure for up to one more window.
        return (float) $this->cache->remember(
            'ai:spend-today:'.Date::now()->toDateString(),
            $this->recomputeSeconds,
            fn (): float => $this->logs->spendToday(),
        );
    }
}
