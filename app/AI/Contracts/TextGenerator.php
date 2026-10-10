<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Requests\TextGenerationRequest;
use App\AI\ResolvedModel;
use App\AI\Results\ReasoningDelta;
use App\AI\Results\TextResult;

/**
 * A provider that can turn a prompt into text.
 *
 * Streaming is declared here from the first version rather than bolted on
 * later. Retrofitting it into an interface whose only method returns a
 * finished result would mean touching every provider, the gateway, and the
 * Livewire layer at once — so the shape is fixed now even though the first
 * tools may only ever call the synchronous path.
 */
interface TextGenerator extends AiProvider
{
    public function generateText(TextGenerationRequest $request, ResolvedModel $model): TextResult;

    /**
     * Yields answer fragments as strings and, for models that think out loud,
     * ReasoningDelta objects for their thinking.
     *
     * The two are different types on purpose: the answer is collected,
     * cached, and billed; thinking is only ever shown as live status. A
     * consumer that just wants text can skip anything that is not a string.
     *
     * The generator's *return* value (available via `getReturn()` once it is
     * exhausted) is the assembled TextResult, carrying token usage that only
     * arrives in the stream's final events. Callers that need to log cost read
     * it there; callers that only render text can ignore it.
     *
     * @return \Generator<int, string|ReasoningDelta, mixed, TextResult>
     */
    public function streamText(TextGenerationRequest $request, ResolvedModel $model): \Generator;
}
