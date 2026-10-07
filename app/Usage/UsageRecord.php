<?php

declare(strict_types=1);

namespace App\Usage;

use App\AI\Capability;
use App\AI\ResolvedModel;
use App\AI\Results\AiResult;
use App\Models\UsageLog;

/**
 * One usage row, before it reaches a store.
 *
 * A value object rather than fourteen arguments: the call site is readable,
 * the order of the columns stops mattering, and adding a fifteenth field
 * later does not touch every caller.
 */
final readonly class UsageRecord
{
    public function __construct(
        public string $toolSlug,
        public string $capability,
        public string $provider,
        public string $model,
        public string $status,
        public string $ipHash,
        public ?int $userId = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $costUsd = null,
        public int $unitsCharged = 0,
        public ?int $durationMs = null,
        public bool $wasCached = false,
        public ?string $errorCode = null,
    ) {}

    /**
     * Build a row for a call that produced a result.
     *
     * Cost is derived here, from the model's configured rates and the usage
     * the provider parsed — so pricing lives in exactly one place and a cached
     * hit records the tokens it *would* have cost at zero actual spend.
     */
    public static function forResult(
        RunContext $ctx,
        Capability $capability,
        ResolvedModel $model,
        AiResult $result,
        int $durationMs,
        bool $wasCached = false,
    ): self {
        $usage = $result->usage();

        return new self(
            toolSlug: $ctx->toolSlug,
            capability: $capability->value,
            provider: $result->provider(),
            model: $model->key,
            status: UsageLog::STATUS_OK,
            ipHash: $ctx->ipHash,
            userId: $ctx->userId,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            // A cache hit cost nothing, however many tokens the original call
            // consumed. Recording the original cost again would double-count
            // it in the daily spend total.
            costUsd: $wasCached ? 0.0 : $usage->costUsd($model),
            durationMs: $durationMs,
            wasCached: $wasCached,
        );
    }

    /**
     * Build a row for a call that failed.
     *
     * Failures are logged as deliberately as successes: the rows nobody
     * records are exactly the ones worth investigating later.
     */
    public static function forFailure(
        RunContext $ctx,
        Capability $capability,
        ResolvedModel $model,
        string $status,
        int $durationMs,
        ?string $errorCode = null,
    ): self {
        return new self(
            toolSlug: $ctx->toolSlug,
            capability: $capability->value,
            provider: $model->provider,
            model: $model->key,
            status: $status,
            ipHash: $ctx->ipHash,
            userId: $ctx->userId,
            durationMs: $durationMs,
            errorCode: $errorCode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'ip_hash' => $this->ipHash,
            'tool_slug' => $this->toolSlug,
            'capability' => $this->capability,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_usd' => $this->costUsd,
            'units_charged' => $this->unitsCharged,
            'duration_ms' => $this->durationMs,
            'was_cached' => $this->wasCached,
            'status' => $this->status,
            'error_code' => $this->errorCode,
        ];
    }
}
