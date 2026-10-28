<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of usage_logs.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $ip_hash
 * @property string $tool_slug
 * @property string $capability
 * @property string $provider
 * @property string $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $cost_usd
 * @property int $units_charged
 * @property int|null $duration_ms
 * @property bool $was_cached
 * @property string $status
 * @property string|null $error_code
 */
final class UsageLog extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_QUOTA_EXCEEDED = 'quota_exceeded';

    /** A stream the user walked away from — see §10.2. */
    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'user_id',
        'ip_hash',
        'tool_slug',
        'capability',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'units_charged',
        'duration_ms',
        'was_cached',
        'status',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            // Decimal, not float: summing float dollars across a day drifts,
            // and this column feeds a spend cap that must not drift.
            'cost_usd' => 'decimal:6',
            'units_charged' => 'integer',
            'duration_ms' => 'integer',
            'was_cached' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
