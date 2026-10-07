<?php

declare(strict_types=1);

namespace App\Clients\Exceptions;

/**
 * HTTP 401/403. Retrying will not help — the credential is wrong.
 */
final class InvalidApiKeyException extends HttpClientException {}
