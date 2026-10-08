<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Tools\ToolRegistry;
use Illuminate\Contracts\View\View;

/**
 * Renders a tool's page. With no slug, the homepage — which leads with a
 * working tool rather than a catalog (§14.1).
 */
final class ToolPageController extends Controller
{
    public function __invoke(ToolRegistry $registry, ?string $slug = null): View
    {
        $tool = $slug === null ? $registry->featured() : $registry->get($slug);

        return view('tools.show', [
            'tool' => $tool,
            'tools' => $registry->all(),
        ]);
    }
}
