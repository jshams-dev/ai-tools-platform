<?php

declare(strict_types=1);

namespace App\Tools\Schema;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One input a tool accepts, declared once (§4.2).
 *
 * The same declaration feeds the web UI, validation, and — once it exists —
 * the REST API and its docs. That is the entire case for having a schema: one
 * list, four consumers, no chance of the API accepting what the UI rejects.
 *
 * The UI is a chat composer, not a form, so the schema says which field is
 * the *message* (`primary()`) and treats the rest as settings the user can
 * leave alone. A tool whose input does not fit that shape supplies its own
 * component instead of pushing this class towards a form-builder framework.
 */
final class Field
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_SELECT = 'select';

    private bool $required = false;

    private bool $primary = false;

    private ?int $maxLength = null;

    private ?string $label = null;

    private ?string $placeholder = null;

    private ?string $default = null;

    /** @var array<string, string> value => label */
    private array $options = [];

    private function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {}

    public static function text(string $name): self
    {
        return new self($name, self::TYPE_TEXT);
    }

    public static function textarea(string $name): self
    {
        return new self($name, self::TYPE_TEXTAREA);
    }

    public static function select(string $name): self
    {
        return new self($name, self::TYPE_SELECT);
    }

    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    /**
     * Marks the field the user types into: the composer's message box.
     */
    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    public function max(int $characters): self
    {
        $this->maxLength = $characters;

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function default(string $value): self
    {
        $this->default = $value;

        return $this;
    }

    /**
     * @param  array<string, string>|list<string>  $options  A plain list is
     *                                                       labelled from its values.
     */
    public function options(array $options): self
    {
        $this->options = array_is_list($options)
            ? array_combine($options, array_map(Str::headline(...), $options))
            : $options;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function maxLength(): ?int
    {
        return $this->maxLength;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function getDefault(): ?string
    {
        return $this->default ?? ($this->type === self::TYPE_SELECT ? array_key_first($this->options) : null);
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function labelFor(string $value): string
    {
        return $this->options[$value] ?? $value;
    }

    /**
     * Laravel validation rules for this field.
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable', 'string'];

        if ($this->maxLength !== null) {
            $rules[] = 'max:'.$this->maxLength;
        }

        if ($this->type === self::TYPE_SELECT) {
            // A select is a closed set. Without this, the schema would happily
            // pass any string through to a prompt that interpolates it.
            $rules[] = Rule::in(array_keys($this->options));
        }

        return $rules;
    }
}
