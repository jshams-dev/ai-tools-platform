<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A model the platform can call.
 *
 * This is the storage shape only. Everything that *runs* a model works with
 * App\AI\ResolvedModel, built from this row by the ModelRegistry, so the AI
 * layer never depends on Eloquent.
 *
 * @property int $id
 * @property int $ai_provider_id
 * @property string $key
 * @property string $name
 * @property string $api_id
 * @property list<string> $capabilities
 * @property string $tier
 * @property bool $supports_streaming
 * @property bool $supports_sampling
 * @property int|null $max_output_tokens
 * @property string $input_cost_per_million
 * @property string $output_cost_per_million
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property int $sort_order
 * @property-read AiProvider $provider
 */
final class AiModel extends Model
{
    protected $fillable = [
        'ai_provider_id',
        'key',
        'name',
        'api_id',
        'capabilities',
        'tier',
        'supports_streaming',
        'supports_sampling',
        'max_output_tokens',
        'input_cost_per_million',
        'output_cost_per_million',
        'settings',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'settings' => 'array',
            'supports_streaming' => 'boolean',
            'supports_sampling' => 'boolean',
            'max_output_tokens' => 'integer',
            'input_cost_per_million' => 'decimal:4',
            'output_cost_per_million' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * @return BelongsToMany<Tool, $this>
     */
    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'tool_model')->withPivot('is_default', 'sort_order')->withTimestamps();
    }

    /**
     * Active, and served by an active provider. Deactivating a provider takes
     * all of its models offline without editing each one.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('provider', fn (Builder $provider) => $provider->where('is_active', true));
    }
}
