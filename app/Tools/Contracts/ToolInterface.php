<?php

declare(strict_types=1);

namespace App\Tools\Contracts;

use App\Tools\Schema\Field;

/**
 * The code half of a tool — what cannot live in a database row.
 *
 * Name, description, status, and which models a tool may use are data in the
 * `tools` table, editable without a deploy. What stays here is behaviour: the
 * fields the tool accepts and the handler that runs it. The two halves meet
 * on `slug()`.
 *
 * A definition must stay cheap to construct: the registry builds every one of
 * them to list tools, without ever running a handler.
 */
interface ToolInterface
{
    /** Matches the `slug` of this tool's row in the `tools` table. */
    public function slug(): string;

    /**
     * @return list<Field> Exactly one of them marked primary().
     */
    public function inputSchema(): array;

    /**
     * Shortcuts shown on an empty conversation. Each one presets settings
     * and optionally fills the message box.
     *
     * @return list<array{label: string, options?: array<string, string>, prompt?: string}>
     */
    public function starters(): array;

    /**
     * A class name, resolved from the container only when the tool runs.
     *
     * @return class-string<ToolHandler>
     */
    public function handler(): string;

    /** A custom Livewire component name, or null for the generic workspace. */
    public function component(): ?string;
}
