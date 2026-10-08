<?php

declare(strict_types=1);

namespace App\Tools\Contracts;

use App\AI\ModelPolicy;
use App\Tools\Schema\Field;

/**
 * What a tool *is* — metadata only (§4.1).
 *
 * Kept apart from the handler so that the catalog, routing, and the UI can
 * describe every tool without building a single handler or its dependency
 * graph. A definition must stay cheap to construct for that reason.
 */
interface ToolInterface
{
    /** URL-safe identifier, e.g. 'text-transformer'. Recorded on every usage row. */
    public function slug(): string;

    public function name(): string;

    /** One sentence for the catalog card and the page header. */
    public function description(): string;

    /** 'writing' | 'travel' | ... */
    public function category(): string;

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

    public function modelPolicy(): ModelPolicy;

    /** Base weight for quota accounting — see §11.1. */
    public function costUnits(): int;

    /** A custom Livewire component name, or null for the generic workspace. */
    public function component(): ?string;
}
