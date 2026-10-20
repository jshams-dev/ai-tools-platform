<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Tools\Schema\Field;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One input of a tool. The frontend turns the primary field into the
 * message box and the select fields into settings pills; validation stays
 * on the server, in ToolRunner.
 *
 * @property Field $resource
 */
final class FieldResource extends JsonResource
{
    /**
     * @return array{name: string, type: string, label: string, primary: bool, required: bool, max_length: int|null, placeholder: string|null, default: string|null, options: list<array{value: string, label: string}>}
     */
    public function toArray(Request $request): array
    {
        $field = $this->resource;

        $options = [];
        foreach ($field->getOptions() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return [
            'name' => $field->name,
            'type' => $field->type,
            'label' => $field->getLabel(),
            'primary' => $field->isPrimary(),
            'required' => $field->isRequired(),
            'max_length' => $field->maxLength(),
            'placeholder' => $field->getPlaceholder(),
            'default' => $field->getDefault(),
            // A list, not an object: JSON objects do not keep key order
            // reliably, and the pills show options in schema order.
            'options' => $options,
        ];
    }
}
