<?php

declare(strict_types=1);

namespace App\AI\Results;

final readonly class TranslationResult implements AiResult
{
    public function __construct(
        public string $text,
        private string $provider,
        private string $model,
        /** Source language the vendor detected, when it reports one. */
        public ?string $detectedSourceLanguage = null,
        private TokenUsage $usage = new TokenUsage,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function usage(): TokenUsage
    {
        return $this->usage;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'provider' => $this->provider,
            'model' => $this->model,
            'detected_source_language' => $this->detectedSourceLanguage,
            'input_tokens' => $this->usage->inputTokens,
            'output_tokens' => $this->usage->outputTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: (string) ($data['text'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            model: (string) ($data['model'] ?? ''),
            detectedSourceLanguage: isset($data['detected_source_language'])
                ? (string) $data['detected_source_language']
                : null,
            usage: new TokenUsage(
                inputTokens: isset($data['input_tokens']) ? (int) $data['input_tokens'] : null,
                outputTokens: isset($data['output_tokens']) ? (int) $data['output_tokens'] : null,
            ),
        );
    }
}
