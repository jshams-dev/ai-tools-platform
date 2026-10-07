<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Requests\AiRequest;
use App\Usage\RunContext;

/**
 * Turns a policy plus a caller into an ordered list of models to try.
 *
 * Resolution is its own class because it is the one place three independent
 * things meet: what the tool allows, what the caller's tier permits, and what
 * the caller asked for. Putting that in the gateway would mix policy with
 * mediation; putting it in the registry would give the registry opinions about
 * tiers it has no business holding.
 *
 * Returns a *chain*, not a single model. The gateway walks it, so one vendor
 * being down degrades quality instead of returning an error.
 */
final class ModelResolver
{
    public function __construct(private readonly ModelRegistry $registry) {}

    /**
     * @return non-empty-list<ResolvedModel> In the order they should be tried.
     *
     * @throws UnsupportedCapabilityException
     */
    public function resolve(AiRequest $request, RunContext $ctx): array
    {
        $capability = $request->capability();
        $chain = $ctx->policy->chainFor($ctx->tier);

        // An explicit choice jumps the queue — but only after being checked
        // against both the tool's list and the caller's tier. `requestedModel`
        // is caller-controlled: honouring it unchecked is how a free user gets
        // to spend pro-tier money.
        if ($ctx->requestedModel !== null && $ctx->policy->allows($ctx->requestedModel, $ctx->tier)) {
            $chain = [
                $ctx->requestedModel,
                ...array_values(array_diff($chain, [$ctx->requestedModel])),
            ];
        }

        $models = [];

        foreach ($chain as $key) {
            $model = $this->registry->get($key);

            // A policy may legitimately list models for several capabilities;
            // only those that can do *this* job belong in the chain.
            if ($model->supports($capability)) {
                $models[] = $model;
            }
        }

        if ($models === []) {
            throw new UnsupportedCapabilityException(sprintf(
                "No model available for '%s' on tier '%s' for tool '%s'. ".
                'The policy lists [%s], none of which declares that capability.',
                $capability->value,
                $ctx->tier,
                $ctx->toolSlug,
                implode(', ', $chain) ?: 'nothing',
            ));
        }

        return $models;
    }

    /**
     * The single model a streaming call should use.
     *
     * Streaming narrows the chain to models that can actually stream, which is
     * a property of the model rather than of the tool — a policy listing a
     * non-streaming model is not wrong, it just cannot serve this path.
     *
     * @return non-empty-list<ResolvedModel>
     *
     * @throws UnsupportedCapabilityException
     */
    public function resolveStreamable(AiRequest $request, RunContext $ctx): array
    {
        $streamable = array_values(array_filter(
            $this->resolve($request, $ctx),
            fn (ResolvedModel $m): bool => $m->supportsStreaming,
        ));

        if ($streamable === []) {
            throw new UnsupportedCapabilityException(sprintf(
                "No streaming-capable model available for '%s' on tier '%s'.",
                $request->capability()->value,
                $ctx->tier,
            ));
        }

        return $streamable;
    }
}
