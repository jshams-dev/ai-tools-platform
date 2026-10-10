<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI vendor. See the create_ai_providers_table migration for why `driver`
 * is a short key and why the API key is not a column.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $driver
 * @property string $base_url
 * @property string|null $proxy
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property int $sort_order
 */
final class AiProvider extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'driver',
        'base_url',
        'proxy',
        'settings',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
