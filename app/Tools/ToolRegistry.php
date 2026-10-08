<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Contracts\ToolInterface;
use App\Tools\Exceptions\ToolNotFoundException;

/**
 * The single source of truth for which tools exist (§14.2).
 *
 * Tools are code, so there is no table: the registry is built from the list
 * of definition classes in config/tools.php. Everything that enumerates tools
 * — routes, navigation, and later the catalog and sitemap — reads it from
 * here, which is what makes it worth more than a config array.
 *
 * Holds definitions only, which are immutable metadata, so it is safe as a
 * singleton under Octane.
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface>|null */
    private ?array $tools = null;

    /**
     * @param  list<class-string<ToolInterface>>  $definitions  In display order.
     */
    public function __construct(private readonly array $definitions) {}

    /**
     * @return array<string, ToolInterface> Keyed by slug, in display order.
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [];

        foreach ($this->definitions as $class) {
            $tool = new $class;
            $slug = $tool->slug();

            // Two tools on one slug would silently shadow each other in every
            // URL and every usage row. Fail at boot instead.
            if (isset($tools[$slug])) {
                throw new \LogicException(sprintf(
                    "Tools %s and %s both claim the slug '%s'.",
                    $tools[$slug]::class, $class, $slug,
                ));
            }

            $tools[$slug] = $tool;
        }

        return $this->tools = $tools;
    }

    public function find(string $slug): ?ToolInterface
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @throws ToolNotFoundException
     */
    public function get(string $slug): ToolInterface
    {
        return $this->find($slug) ?? throw ToolNotFoundException::forSlug($slug);
    }

    /**
     * The tool the homepage opens with — the first one registered.
     *
     * The homepage leads with a working tool rather than a catalog (§14.1).
     */
    public function featured(): ToolInterface
    {
        $tools = $this->all();

        return reset($tools) ?: throw new \LogicException('No tools are registered in config/tools.php.');
    }
}
