<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\Capability;
use App\AI\ProviderFactory;
use App\AI\ResolvedModel;
use App\AI\Results\AiResult;

/**
 * Generate text from a prompt.
 *
 * Named properties rather than an `array $options` bag: an array gives no type
 * safety and no autocompletion, which throws away most of the reason to have a
 * DTO at all. `readonly` because a request that mutates between the cache-key
 * calculation and the provider call is a bug that is very hard to see.
 */
final readonly class TextGenerationRequest extends AiRequest implements StreamableRequest
{
    public function __construct(
        public string $userPrompt,
        public ?string $systemPrompt = null,
        public float $temperature = 0.7,
        public ?int $maxTokens = null,
        private int $promptVersion = 1,
    ) {}

    public function capability(): Capability
    {
        return Capability::TextGeneration;
    }

    public function executeOn(ProviderFactory $factory, ResolvedModel $model): AiResult
    {
        // The factory returns a TextGenerator, so this call is checked
        // statically. No instanceof, and no assert() — which would be compiled
        // out in production anyway and so guarantees nothing where it matters.
        return $factory->textGenerator($model)->generateText($this, $model);
    }

    public function streamOn(ProviderFactory $factory, ResolvedModel $model): \Generator
    {
        return yield from $factory->textGenerator($model)->streamText($this, $model);
    }

    public function promptVersion(): int
    {
        return $this->promptVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function cachePayload(): array
    {
        return [
            'user_prompt' => $this->userPrompt,
            'system_prompt' => $this->systemPrompt,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ];
    }
}
