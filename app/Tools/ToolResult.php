<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * The finished output of one tool run.
 */
final readonly class ToolResult
{
    public function __construct(
        public string $text,
    ) {}
}
