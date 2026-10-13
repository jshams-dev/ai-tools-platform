<?php

declare(strict_types=1);

namespace App\Tools;

use App\AI\Exceptions\ContentRefusedException;
use App\AI\Exceptions\SpendCapExceededException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\Clients\Exceptions\HttpClientException;
use App\Tools\Exceptions\SignInRequiredException;
use App\Tools\Exceptions\ToolRateLimitedException;

/**
 * Why a tool run failed, said for the person rather than the log.
 *
 * Shared by every entry point, so the chat and the API tell a caller the
 * same thing about the same failure. Anything unexpected is still reported,
 * so a friendly sentence never hides a real bug.
 */
final readonly class RunError
{
    public const SIGN_IN_REQUIRED = 'sign_in_required';

    public const RATE_LIMITED = 'rate_limited';

    public const SPEND_CAP = 'spend_cap';

    public const REFUSED = 'refused';

    public const UNAVAILABLE = 'unavailable';

    public const FAILED = 'failed';

    /**
     * @param  string  $code  Stable, for programs; one of the constants above.
     * @param  string  $message  For people; may be reworded or translated.
     * @param  int  $status  The HTTP status, when the run fails before it starts.
     */
    public function __construct(
        public string $code,
        public string $message,
        public int $status,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function from(\Throwable $e): self
    {
        return match (true) {
            $e instanceof SignInRequiredException => new self(self::SIGN_IN_REQUIRED, __("You've used today's free messages. Sign in to keep going."), 401),
            $e instanceof ToolRateLimitedException => new self(self::RATE_LIMITED, __("You're sending requests a little fast. Try again in :seconds seconds.", ['seconds' => $e->retryAfterSeconds]), 429, $e->retryAfterSeconds),
            $e instanceof SpendCapExceededException => new self(self::SPEND_CAP, __('The platform has reached its usage limit for today. Please try again tomorrow.'), 503),
            $e instanceof ContentRefusedException => new self(self::REFUSED, __('The model declined to answer this request.'), 422),
            $e instanceof HttpClientException, $e instanceof UnsupportedCapabilityException => self::reported($e, new self(self::UNAVAILABLE, __('The AI service is unavailable right now. Please try again in a moment.'), 503)),
            default => self::reported($e, new self(self::FAILED, __('Something went wrong. Please try again.'), 500)),
        };
    }

    private static function reported(\Throwable $e, self $error): self
    {
        report($e);

        return $error;
    }
}
