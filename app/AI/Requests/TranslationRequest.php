<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\Capability;
use App\AI\ProviderFactory;
use App\AI\ResolvedModel;
use App\AI\Results\AiResult;

/**
 * Translate text into a target language.
 *
 * Note what is absent: there is no prompt. This request carries the shape a
 * translation API actually accepts, which is the whole reason capabilities are
 * separated — a prompt-shaped interface would force every translation provider
 * to pretend to be a chat model.
 *
 * It is not streamable: DeepL returns a complete translation in one response,
 * and there is nothing to yield incrementally.
 */
final readonly class TranslationRequest extends AiRequest
{
    /**
     * @param  string  $targetLanguage  ISO code, e.g. 'FA', 'EN-GB'.
     * @param  string|null  $sourceLanguage  Null asks the vendor to detect it.
     * @param  string|null  $formality  Vendor-neutral hint: 'more' | 'less'.
     */
    public function __construct(
        public string $text,
        public string $targetLanguage,
        public ?string $sourceLanguage = null,
        public ?string $formality = null,
    ) {}

    public function capability(): Capability
    {
        return Capability::Translation;
    }

    public function executeOn(ProviderFactory $factory, ResolvedModel $model): AiResult
    {
        return $factory->translator($model)->translate($this, $model);
    }

    /**
     * @return array<string, mixed>
     */
    public function cachePayload(): array
    {
        return [
            'text' => $this->text,
            'target_language' => $this->targetLanguage,
            'source_language' => $this->sourceLanguage,
            'formality' => $this->formality,
        ];
    }
}
