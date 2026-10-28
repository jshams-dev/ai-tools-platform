<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tool as the catalog shows it: enough for a card and a link.
 *
 * @mixin Tool
 */
final class ToolResource extends JsonResource
{
    /**
     * @return array{slug: string, name: string, description: string|null, category: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
        ];
    }
}
