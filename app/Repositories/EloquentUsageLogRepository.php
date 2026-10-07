<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\UsageLog;
use App\Usage\UsageRecord;
use Illuminate\Support\Facades\Date;

final class EloquentUsageLogRepository implements UsageLogRepository
{
    public function record(UsageRecord $record): void
    {
        UsageLog::create($record->toArray());
    }

    public function spendToday(): float
    {
        return (float) UsageLog::query()
            ->where('created_at', '>=', Date::now()->startOfDay())
            // Only calls that actually reached a vendor cost money. Cached
            // hits already record 0.0, but excluding failures keeps the sum
            // honest if a future row ever carries a partial cost.
            ->whereIn('status', [UsageLog::STATUS_OK, UsageLog::STATUS_ABORTED])
            ->sum('cost_usd');
    }

    public function countForSubject(array $subject, \DateTimeInterface $since): int
    {
        return UsageLog::query()
            ->where('created_at', '>=', $since)
            ->when(
                $subject['type'] === 'user',
                fn ($q) => $q->where('user_id', (int) $subject['id']),
                fn ($q) => $q->where('ip_hash', $subject['id'])->whereNull('user_id'),
            )
            ->count();
    }
}
