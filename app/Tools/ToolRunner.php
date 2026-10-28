<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Contracts\ToolHandler;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Exceptions\SignInRequiredException;
use App\Tools\Exceptions\ToolRateLimitedException;
use App\Tools\Schema\Field;
use App\Usage\RunContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;

/**
 * Runs a tool the same way regardless of which entry point asked.
 *
 * Validation and throttling live here rather than in the Livewire component
 * or a controller, so the web UI and the REST API cannot drift apart on
 * either — and a new entry point inherits both by calling this.
 *
 * The per-IP throttle (§11.3, layer 1) is deliberately not route middleware.
 * Livewire actions arrive on its own update endpoint, not on the page's
 * route, so `throttle` on /tools/{slug} would limit page views and leave the
 * call that spends money completely unguarded.
 *
 * Guests get a few free runs a day (§18.1) — enough to try a tool, not to
 * live on. After that a run asks them to sign in.
 */
final class ToolRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly ValidatorFactory $validator,
        private readonly RateLimiter $limiter,
        private readonly int $maxRunsPerWindow = 20,
        private readonly int $windowSeconds = 60,
        private readonly int $guestRunsPerDay = 3,
    ) {}

    /**
     * How many free runs this guest has left today; null for a signed-in user.
     */
    public function guestRunsLeft(RunContext $ctx): ?int
    {
        if (! $ctx->isGuest()) {
            return null;
        }

        return max(0, $this->guestRunsPerDay - $this->limiter->attempts($this->guestKey($ctx)));
    }

    /**
     * Validate input against the tool's schema and fill in defaults.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    public function validate(ToolInterface $tool, array $input): array
    {
        $schema = $tool->inputSchema();

        $rules = [];
        foreach ($schema as $field) {
            $rules[$field->name] = $field->rules();
        }

        $validated = $this->validator->make($input, $rules)->validate();

        // Only declared fields survive, so nothing the caller invents can
        // reach a prompt.
        $clean = [];
        foreach ($schema as $field) {
            $clean[$field->name] = $this->valueOrDefault($validated[$field->name] ?? null, $field);
        }

        return $clean;
    }

    /**
     * Validate, throttle, and stream the tool's output.
     *
     * @param  array<string, mixed>  $input
     * @return \Generator<int, string, mixed, ToolResult|null>
     *
     * @throws ValidationException|SignInRequiredException|ToolRateLimitedException
     */
    public function stream(ToolInterface $tool, array $input, RunContext $ctx): \Generator
    {
        if ($ctx->toolSlug !== $tool->slug()) {
            throw new \InvalidArgumentException(
                "RunContext is for '{$ctx->toolSlug}' but the tool is '{$tool->slug()}'."
            );
        }

        $validated = $this->validate($tool, $input);

        // After validation: a rejected input never reaches a vendor, so it
        // should not use up one of the caller's runs either.
        $this->throttle($ctx);
        $this->meterGuest($ctx);

        /** @var ToolHandler $handler */
        $handler = $this->container->make($tool->handler());

        return yield from $handler->stream($validated, $ctx);
    }

    /**
     * @throws ToolRateLimitedException
     */
    private function throttle(RunContext $ctx): void
    {
        $subject = $ctx->subject();
        $key = "tool-runs:{$subject['type']}:{$subject['id']}";

        if ($this->limiter->tooManyAttempts($key, $this->maxRunsPerWindow)) {
            throw new ToolRateLimitedException($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $this->windowSeconds);
    }

    /**
     * Count a guest's run against today's allowance, or refuse it.
     *
     * The window starts at the guest's first run and lasts a day. Keyed by
     * IP hash, like the throttle, so clearing cookies does not reset it.
     *
     * @throws SignInRequiredException
     */
    private function meterGuest(RunContext $ctx): void
    {
        if (! $ctx->isGuest()) {
            return;
        }

        // Not tooManyAttempts(): with no run on record it always says no, so
        // an allowance of zero would still let one run through.
        if ($this->guestRunsLeft($ctx) === 0) {
            throw new SignInRequiredException;
        }

        $this->limiter->hit($this->guestKey($ctx), 86400);
    }

    private function guestKey(RunContext $ctx): string
    {
        return 'guest-runs:'.$ctx->ipHash;
    }

    private function valueOrDefault(mixed $value, Field $field): ?string
    {
        return $value === null || $value === '' ? $field->getDefault() : (string) $value;
    }
}
