<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A model as the picker shows it. Prices, API ids and settings stay private.
 *
 * @mixin AiModel
 */
final class AiModelResource extends JsonResource
{
    /**
     * @return array{key: string, name: string, provider: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'provider' => $this->provider->name,
        ];
    }
}
