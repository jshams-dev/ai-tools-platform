<?php

declare(strict_types=1);

namespace App\Tools\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A slug that names no registered tool.
 *
 * Extends the HTTP 404 so that an unknown /tools/{slug} renders as a normal
 * not-found page without every entry point translating it.
 */
final class ToolNotFoundException extends NotFoundHttpException
{
    public static function forSlug(string $slug): self
    {
        return new self("No tool is registered under the slug '{$slug}'.");
    }
}
