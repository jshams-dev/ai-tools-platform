<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Exceptions\AiException;
use App\AI\Requests\AiRequest;
use App\AI\Requests\StreamableRequest;
use App\AI\Results\AiResult;
use App\Clients\Exceptions\HttpClientException;
use App\Clients\Exceptions\RateLimitException;
use App\Models\UsageLog;
use App\Repositories\UsageLogRepository;
use App\Usage\RunContext;
use App\Usage\UsageRecord;

/**
 * The single choke point every AI call passes through.
 *
 * It owns the concerns that are identical for every tool and every
 * capability: model resolution, caching, usage logging, cost accounting, and
 * the fallback chain. Each of those would otherwise be written once per
 * provider (four copies that drift) or once per tool (fifteen copies).
 *
 * Note what it does *not* know. There is no `complete()`, no `translate()`,
 * no match statement over capabilities, and no way for it to find out how
 * many kinds of AI work exist — `$request->executeOn()` does the dispatch.
 * That is what makes adding video generation a new request class plus a new
 * interface, touching nothing in this file.
 *
 * It is also the reason the spend cap, when it arrives, is one `if`: there is
 * exactly one place to put it.
 */
final class AiGateway
{
    public function __construct(
        private readonly ModelResolver $resolver,
        private readonly ProviderFactory $factory,
        private readonly AiResponseCache $cache,
        private readonly UsageLogRepository $logs,
    ) {}

    /**
     * Run a request to completion.
     *
     * @throws AiException|HttpClientException
     */
    public function run(AiRequest $request, RunContext $ctx): AiResult
    {
        $chain = $this->resolver->resolve($request, $ctx);
        $lastFailure = null;

        // One pass, in preference order. Each model's cache is consulted just
        // before that model would be called, which gets two properties that
        // are easy to lose by separating the two concerns:
        //
        //  - the preferred model wins. Scanning every cache up front would
        //    serve a cached *fallback* answer while the first choice was
        //    perfectly healthy — silently returning worse output.
        //  - a fallback still benefits from its own cache. If the first model
        //    is persistently down, the second is not re-bought on every call.
        foreach ($chain as $model) {
            $key = $request->cacheKey($model);

            // Checked before anything is spent: a hit costs nothing, so there
            // is no reason to touch a quota counter or a vendor.
            if ($hit = $this->cache->get($key)) {
                $this->logs->record(UsageRecord::forResult(
                    $ctx, $request->capability(), $model, $hit, durationMs: 0, wasCached: true,
                ));

                return $hit;
            }

            $startedAt = hrtime(true);

            try {
                $result = $request->executeOn($this->factory, $model);

                $this->cache->put($key, $result);

                $this->logs->record(UsageRecord::forResult(
                    $ctx, $request->capability(), $model, $result, $this->elapsedMs($startedAt),
                ));

                return $result;
            } catch (\Throwable $e) {
                // Logged per attempt, not per call. If the chain tries three
                // models, three rows appear — otherwise a vendor that is
                // quietly failing over to a cheaper model every time leaves no
                // trace at all.
                $this->logs->record(UsageRecord::forFailure(
                    $ctx,
                    $request->capability(),
                    $model,
                    $this->statusFor($e),
                    $this->elapsedMs($startedAt),
                    $this->errorCodeFor($e),
                ));

                if (! $this->shouldFallBack($e)) {
                    throw $e;
                }

                $lastFailure = $e;
            }
        }

        // Every model in the chain failed in a way worth retrying elsewhere.
        // The last exception is rethrown rather than a generic one, so the
        // caller still sees what actually went wrong.
        throw $lastFailure ?? new AiException('No model in the chain could serve the request.');
    }

    /**
     * Stream a request, yielding fragments as they arrive.
     *
     * The generator's return value is the assembled result, or **null** when
     * the caller disconnected mid-stream — there is no complete answer in that
     * case, and inventing one would cache a truncation.
     *
     * @return \Generator<int, string, mixed, AiResult|null>
     *
     * @throws AiException|HttpClientException
     */
    public function stream(StreamableRequest $request, RunContext $ctx): \Generator
    {
        /** @var AiRequest&StreamableRequest $request */
        $chain = $this->resolver->resolveStreamable($request, $ctx);
        $lastFailure = null;

        foreach ($chain as $model) {
            $key = $request->cacheKey($model);

            if ($hit = $this->cache->get($key)) {
                $this->logs->record(UsageRecord::forResult(
                    $ctx, $request->capability(), $model, $hit, durationMs: 0, wasCached: true,
                ));

                // A cached stream is yielded whole. Re-chunking it to look
                // live would be theatre that costs latency.
                yield $this->textOf($hit);

                return $hit;
            }

            $startedAt = hrtime(true);
            $yielded = false;
            $status = UsageLog::STATUS_OK;
            $errorCode = null;
            $result = null;

            // Without this pair, a user closing the tab leaves the server
            // buying tokens nobody will ever read.
            ignore_user_abort(false);

            try {
                $stream = $request->streamOn($this->factory, $model);

                foreach ($stream as $fragment) {
                    if (connection_aborted() === 1) {
                        $status = UsageLog::STATUS_ABORTED;
                        break;
                    }

                    $yielded = true;

                    yield $fragment;
                }

                $result = $status === UsageLog::STATUS_ABORTED ? null : $stream->getReturn();

                // Only a complete stream is cached. Caching a partial answer
                // would serve the truncation to everyone who asks next.
                if ($result !== null) {
                    $this->cache->put($key, $result);
                }

                return $result;
            } catch (\Throwable $e) {
                $status = $this->statusFor($e);
                $errorCode = $this->errorCodeFor($e);

                // Falling back is only honest before the first fragment has
                // left. Once the caller has rendered text, restarting on
                // another model would duplicate output they have already seen
                // — and bill us twice for the privilege.
                if ($yielded || ! $this->shouldFallBack($e)) {
                    throw $e;
                }

                $lastFailure = $e;
            } finally {
                // In `finally`, so the rows worth investigating — aborted and
                // failed streams — are the ones that do get written.
                $this->logs->record($result !== null
                    ? UsageRecord::forResult($ctx, $request->capability(), $model, $result, $this->elapsedMs($startedAt))
                    : UsageRecord::forFailure($ctx, $request->capability(), $model, $status, $this->elapsedMs($startedAt), $errorCode));
            }
        }

        throw $lastFailure ?? new AiException('No streaming model in the chain could serve the request.');
    }

    /**
     * Whether another model in the chain deserves a try.
     *
     * Only transport failures that a different vendor might not have: a rate
     * limit, an outage, a timeout. A malformed request or a refusal will fail
     * identically everywhere, and walking the whole chain to prove it just
     * multiplies the latency of a certain failure.
     */
    private function shouldFallBack(\Throwable $e): bool
    {
        return $e instanceof HttpClientException && $e->isRetryable();
    }

    private function statusFor(\Throwable $e): string
    {
        return $e instanceof RateLimitException
            ? UsageLog::STATUS_RATE_LIMITED
            : UsageLog::STATUS_FAILED;
    }

    private function errorCodeFor(\Throwable $e): string
    {
        return class_basename($e);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * A cached streamable result is always text today, but reaching into a
     * concrete class would couple the gateway to one capability.
     */
    private function textOf(AiResult $result): string
    {
        return (string) ($result->toArray()['text'] ?? '');
    }
}
