<?php

declare(strict_types=1);

namespace App\Tools\Exceptions;

use RuntimeException;

/**
 * A guest has used today's free runs; signing in is the way to continue.
 */
final class SignInRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The free runs for guests are used up for today. Sign in to continue.');
    }
}
