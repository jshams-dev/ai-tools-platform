<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\ProviderFactory;
use App\AI\ResolvedModel;
use App\AI\Results\AiResult;

/**
 * Marks a request that can be served incrementally.
 *
 * Streaming is a separate marker rather than a method on AiRequest because it
 * genuinely is not universal: a translation returns in one piece, and an async
 * video job has nothing to stream. Only requests that carry this interface may
 * be passed to the gateway's streaming path, so the distinction is enforced by
 * the type system instead of by a runtime capability check.
 */
interface StreamableRequest
{
    /**
     * Yields output fragments as they are produced.
     *
     * The generator's return value is the assembled result, which the gateway
     * needs in order to cache the completed output and log token usage that
     * only arrives in the stream's closing events.
     *
     * @return \Generator<int, string, mixed, AiResult>
     */
    public function streamOn(ProviderFactory $factory, ResolvedModel $model): \Generator;
}
