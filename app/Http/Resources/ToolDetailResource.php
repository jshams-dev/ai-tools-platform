<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiModel;
use App\Models\Tool;
use App\Tools\Contracts\ToolInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the frontend needs to draw a tool's chat: its fields (the
 * message box and the settings pills), its starters, and the models this
 * caller may pick from.
 *
 * @mixin Tool
 */
final class ToolDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, AiModel>  $models  Already filtered to the caller's tier, default first.
     */
    public function __construct(
        Tool $tool,
        private readonly ToolInterface $definition,
        private readonly Collection $models,
    ) {
        parent::__construct($tool);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new ToolResource($this->resource))->toArray($request),
            // How replies are shown: null means Markdown (§0.3).
            'view' => $this->definition->component(),
            'fields' => FieldResource::collection($this->definition->inputSchema()),
            'starters' => $this->definition->starters(),
            'models' => AiModelResource::collection($this->models),
            'default_model' => $this->models->first()?->key,
        ];
    }
}
