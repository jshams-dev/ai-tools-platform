<?php

declare(strict_types=1);

namespace App\AI;

/**
 * One row of the ai_models table, turned into a typed object.
 *
 * Resolution happens once, above the provider layer; the provider is handed
 * the answer rather than looking anything up itself. That is what keeps a
 * provider a translator between our call and one vendor's HTTP API, with no
 * knowledge of tiers, policies, or which tool is calling.
 */
final readonly class ResolvedModel
{
    /**
     * @param  string  $key  Our internal identifier, e.g. 'claude-haiku'.
     *                       Stable across vendor model retirements.
     * @param  string  $provider  Registry key of the vendor, e.g. 'anthropic'.
     * @param  string  $apiId  The vendor's own identifier, e.g.
     *                         'claude-haiku-4-5'. Kept separate from $key so
     *                         that a retired model is one config line to
     *                         change instead of every tool that named it.
     * @param  list<Capability>  $capabilities
     * @param  string  $tier  'free' | 'pro'. This is where "free" lives —
     *                        a property of the model, never a provider class.
     */
    public function __construct(
        public string $key,
        public string $provider,
        public string $apiId,
        public array $capabilities,
        public string $tier = 'free',
        public bool $supportsStreaming = false,
        /**
         * Whether the vendor still accepts sampling parameters (temperature
         * and friends) for this model. Newer reasoning models reject them with
         * a 400 rather than ignoring them, so this cannot be assumed.
         */
        public bool $supportsSampling = true,
        public float $inputCostPerMillion = 0.0,
        public float $outputCostPerMillion = 0.0,
        public ?int $maxOutputTokens = null,
        /**
         * Extra request parameters this model needs, merged into the payload
         * by drivers that support it — e.g. ['reasoning_effort' => 'low'].
         *
         * @var array<string, mixed>
         */
        public array $requestOptions = [],
    ) {}

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, strict: true);
    }
}
