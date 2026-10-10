<?php

declare(strict_types=1);

namespace App\Tools\Contracts;

use App\Tools\ToolResult;
use App\Usage\RunContext;

/**
 * What a tool *does* — the business logic (§4.3).
 *
 * Streaming is the one shape every entry point can consume: the web UI
 * renders fragments as they arrive, the API relays them as SSE, and a
 * synchronous caller simply drains the generator. A handler that answers
 * instantly — rules only, no AI — yields once.
 */
interface ToolHandler
{
    /**
     * @param  array<string, string|null>  $input  Already validated against the
     *                                             tool's schema, defaults applied.
     *                                             The handler maps it onto its
     *                                             own typed input object.
     * @return \Generator<int, string, mixed, ToolResult|null> Null when the
     *                                                         caller disconnected.
     */
    public function stream(array $input, RunContext $ctx): \Generator;
}
