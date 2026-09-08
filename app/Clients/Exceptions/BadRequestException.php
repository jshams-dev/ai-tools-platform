<?php

declare(strict_types=1);

namespace App\Clients\Exceptions;

/**
 * HTTP 4xx that is not 401/403/429 — we sent something the vendor rejected.
 *
 * Never retryable: the same bytes produce the same rejection.
 */
final class BadRequestException extends HttpClientException {}
