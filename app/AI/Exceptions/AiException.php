<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * Base for problems that are about *AI*, not about HTTP.
 *
 * The split from App\Clients\Exceptions is deliberate. A 429 is a transport
 * fact and means the same thing for a weather API. "The model refused" or "the
 * model returned prose where JSON was required" only makes sense here.
 */
class AiException extends RuntimeException {}
