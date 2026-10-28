<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\AI\Results\ReasoningDelta;
use App\Http\Controllers\Controller;
use App\Tools\RunError;
use App\Tools\ToolRegistry;
use App\Tools\ToolRunner;
use App\Usage\RunContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs a tool and streams the answer as Server-Sent Events (§2, §10).
 *
 * Body: `{"input": {<field>: <value>, …}, "model": <key>|null}`.
 *
 * A run that cannot start is an ordinary JSON error with an HTTP status:
 * 422 for invalid input (keyed by field name), 401 when a guest's free runs
 * are used up, 429 when throttled, 503 or 500 when no model could answer.
 * Once it has started, the response is a stream of events:
 *
 * - `thinking` `{"text"}` — the model's thinking, for live status; never stored
 * - `text` `{"text"}` — the next piece of the answer; plain text, not HTML
 * - `done` `{"guest_runs_left"}` — the answer is complete; null for a signed-in user
 * - `error` `{"code", "message"}` — the answer broke off; what arrived is all there is
 *
 * Guests may call it: the allowance and throttle live in ToolRunner.
 */
final class ToolRunController extends Controller
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolRunner $runner,
    ) {}

    public function __invoke(Request $request, string $slug): JsonResponse|StreamedResponse
    {
        $tool = $this->registry->get($slug);

        $payload = $request->validate([
            'input' => ['required', 'array'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        $ctx = RunContext::forCaller(
            toolSlug: $slug,
            policy: $tool->modelPolicy(),
            user: $request->user('sanctum'),
            ip: (string) $request->ip(),
            requestedModel: $payload['model'] ?? null,
        );

        // As in the chat: a slow model may stream past PHP's 30 seconds, and
        // the HTTP client's own stream timeout is the limit that should win.
        set_time_limit((int) config('ai.http.stream_timeout', 300) + 30);

        $run = $this->runner->stream($this->registry->definitionFor($tool), $payload['input'], $ctx);

        // Run up to the first fragment before answering. Validation, the
        // allowance, the throttle and the whole fallback chain all happen
        // there, so a run that cannot start gets a real HTTP status instead
        // of a 200 with an error inside it.
        try {
            $run->current();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed(RunError::from($e));
        }

        return response()->eventStream(function () use ($run, $ctx): \Generator {
            try {
                foreach ($run as $fragment) {
                    yield $fragment instanceof ReasoningDelta
                        ? new StreamedEvent('thinking', ['text' => $fragment->text])
                        : new StreamedEvent('text', ['text' => $fragment]);
                }
            } catch (\Throwable $e) {
                $error = RunError::from($e);

                yield new StreamedEvent('error', ['code' => $error->code, 'message' => $error->message]);

                return;
            }

            yield new StreamedEvent('done', ['guest_runs_left' => $this->runner->guestRunsLeft($ctx)]);
        }, endStreamWith: null);
    }

    private function failed(RunError $error): JsonResponse
    {
        $headers = $error->retryAfterSeconds === null ? [] : ['Retry-After' => (string) $error->retryAfterSeconds];

        return response()->json(['message' => $error->message, 'code' => $error->code], $error->status, $headers);
    }
}
