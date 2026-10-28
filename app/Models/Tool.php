<?php

declare(strict_types=1);

namespace App\Models;

use App\AI\ModelPolicy;
use App\Usage\RunContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The editable half of a tool: name, description, status, and which models it
 * may use. Its fields and behaviour are code — see ToolRegistry.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property int $cost_units
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Collection<int, AiModel> $models
 */
final class Tool extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'cost_units',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cost_units' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The tool's models in fallback order: the default first, then by the
     * order set on the pivot.
     *
     * @return BelongsToMany<AiModel, $this>
     */
    public function models(): BelongsToMany
    {
        return $this->belongsToMany(AiModel::class, 'tool_model')
            ->withPivot('is_default', 'sort_order')
            ->withTimestamps()
            ->orderByPivot('is_default', 'desc')
            ->orderByPivot('sort_order');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The models a caller on this tier may use, in fallback order.
     *
     * A 'pro' model is never offered to a guest or free caller — this is
     * where that line is drawn for the picker and the resolver alike.
     *
     * @return Collection<int, AiModel>
     */
    public function modelsFor(string $tier): Collection
    {
        return $this->usableModels()
            ->filter(fn (AiModel $model): bool => $tier === RunContext::TIER_PRO || $model->tier !== RunContext::TIER_PRO)
            ->values();
    }

    /**
     * The policy the gateway resolves against, built from this tool's rows.
     *
     * The caller may pick any model the policy offers their tier; the
     * resolver re-checks that choice server-side.
     */
    public function modelPolicy(): ModelPolicy
    {
        $free = $this->modelsFor(RunContext::TIER_FREE)->pluck('key')->all();
        $pro = $this->modelsFor(RunContext::TIER_PRO)->pluck('key')->all();

        // An empty free chain is allowed: a tool with only pro models simply
        // has nothing to offer a guest, and the resolver says so.
        return ModelPolicy::perTier(free: $free, pro: $pro, userSelectable: true);
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function usableModels(): Collection
    {
        return $this->models
            ->filter(fn (AiModel $model): bool => $model->is_active && $model->provider->is_active)
            ->values();
    }
}
