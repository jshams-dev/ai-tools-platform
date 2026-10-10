<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ToolDetailResource;
use App\Http\Resources\ToolResource;
use App\Tools\ToolRegistry;
use App\Usage\RunContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The tool catalog, read by the frontend to render its pages (§14.2).
 *
 * Public: the frontend's server renders tool pages for search engines
 * without a session. A signed-in browser gets the models of its own tier.
 */
final class ToolController extends Controller
{
    public function __construct(private readonly ToolRegistry $registry) {}

    public function index(): AnonymousResourceCollection
    {
        return ToolResource::collection($this->registry->all());
    }

    public function show(Request $request, string $slug): ToolDetailResource
    {
        $tool = $this->registry->get($slug);
        // The sanctum guard reads the session first, then (phase 10) an API token.
        $tier = RunContext::tierFor($request->user('sanctum'));

        return new ToolDetailResource(
            $tool,
            $this->registry->definitionFor($tool),
            $tool->modelsFor($tier),
        );
    }
}
