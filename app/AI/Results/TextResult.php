<?php

declare(strict_types=1);

namespace App\AI\Results;

final readonly class TextResult implements AiResult
{
    public function __construct(
        public string $text,
        private string $provider,
        private string $model,
        private TokenUsage $usage = new TokenUsage,
        /** Vendor's reason for stopping, e.g. 'end_turn' or 'max_tokens'. */
        public ?string $stopReason = null,
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
     * True when the model was cut off by the token ceiling rather than
     * finishing its thought. A tool that parses structured output should treat
     * this as a failure: the JSON it is about to read is truncated.
     */
    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
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
            'stop_reason' => $this->stopReason,
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
            usage: new TokenUsage(
                inputTokens: isset($data['input_tokens']) ? (int) $data['input_tokens'] : null,
                outputTokens: isset($data['output_tokens']) ? (int) $data['output_tokens'] : null,
            ),
            stopReason: isset($data['stop_reason']) ? (string) $data['stop_reason'] : null,
        );
    }
}
