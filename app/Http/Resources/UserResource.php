<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Usage\RunContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user, for the sidebar and the profile menu.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, plan: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'plan' => RunContext::tierFor($this->resource),
        ];
    }
}
