<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Usage\UsageRecord;

/**
 * Where usage rows go.
 *
 * This is the one place the repository pattern earns its keep in this
 * codebase. Usage logs are append-only, high-volume, and read almost entirely
 * in aggregate — the shape of data that outgrows the primary database first.
 * Behind this interface, moving it to a time-series store or a queue is a new
 * implementation rather than a change to the gateway.
 *
 * Everything else in the application uses Eloquent directly, because for
 * ordinary CRUD a repository is a layer that only forwards calls.
 */
interface UsageLogRepository
{
    public function record(UsageRecord $record): void;

    /**
     * Total USD spent today across the whole platform.
     *
     * Feeds the daily spend cap. Cached hits contribute zero, so this is real
     * money rather than notional value.
     */
    public function spendToday(): float;

    /**
     * How many calls a subject has made in a period — guest or user.
     *
     * @param  array{type: string, id: string}  $subject  From RunContext::subject().
     */
    public function countForSubject(array $subject, \DateTimeInterface $since): int;
}
