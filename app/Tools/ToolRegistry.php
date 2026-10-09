<?php

declare(strict_types=1);

namespace App\Tools;

use App\Models\Tool;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Exceptions\ToolNotFoundException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single source of truth for which tools exist (§14.2).
 *
 * A tool has two halves that meet on its slug: a row in `tools` (name,
 * description, status, models — editable without a deploy) and a definition
 * class in config/tools.php (fields and handler — code). A tool is served only
 * when both exist and the row is active, so switching a tool off is a database
 * edit, and a half-finished definition never appears in the UI.
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface>|null */
    private ?array $definitions = null;

    /**
     * @param  list<class-string<ToolInterface>>  $definitionClasses
     */
    public function __construct(private readonly array $definitionClasses) {}

    /**
     * Active tools that have code behind them, in display order, with their
     * models loaded — the model picker needs them on every page.
     *
     * @return Collection<int, Tool>
     */
    public function all(): Collection
    {
        return Tool::query()
            ->active()
            ->whereIn('slug', array_keys($this->definitions()))
            ->with('models.provider')
            ->orderBy('sort_order')
            ->get();
    }

    public function find(string $slug): ?Tool
    {
        if (! isset($this->definitions()[$slug])) {
            return null;
        }

        return Tool::query()->active()->where('slug', $slug)->with('models.provider')->first();
    }

    /**
     * @throws ToolNotFoundException
     */
    public function get(string $slug): Tool
    {
        return $this->find($slug) ?? throw ToolNotFoundException::forSlug($slug);
    }

    /**
     * The tool the homepage opens with — the first in display order.
     *
     * The homepage leads with a working tool rather than a catalog (§14.1).
     *
     * @throws ToolNotFoundException
     */
    public function featured(): Tool
    {
        return $this->all()->first() ?? throw new ToolNotFoundException('No active tools. Run the database seeders.');
    }

    public function definitionFor(Tool $tool): ToolInterface
    {
        return $this->definitions()[$tool->slug]
            ?? throw new \LogicException("Tool '{$tool->slug}' has a database row but no definition in config/tools.php.");
    }

    /**
     * @return array<string, ToolInterface>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach ($this->definitionClasses as $class) {
            $definition = new $class;
            $slug = $definition->slug();

            // Two definitions on one slug would silently shadow each other.
            // Fail loudly instead.
            if (isset($definitions[$slug])) {
                throw new \LogicException(sprintf(
                    "Tools %s and %s both claim the slug '%s'.",
                    $definitions[$slug]::class, $class, $slug,
                ));
            }

            $definitions[$slug] = $definition;
        }

        return $this->definitions = $definitions;
    }
}
