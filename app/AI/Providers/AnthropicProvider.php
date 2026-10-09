<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Contracts\TextGenerator;
use App\AI\Exceptions\ContentRefusedException;
use App\AI\Exceptions\InvalidResponseException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Requests\TextGenerationRequest;
use App\AI\ResolvedModel;
use App\AI\Results\TextResult;
use App\AI\Results\TokenUsage;
use App\Clients\BaseHttpClient;
use App\Clients\Exceptions\HttpClientException;
use App\Clients\ServerSentEvents;

/**
 * Anthropic's Messages API.
 *
 * A provider is a translator and nothing else: it turns one of our requests
 * into this vendor's wire format and turns the reply back into our result
 * types. It does not cache, does not log usage, does not count quota — those
 * are identical for every vendor, so they live once in the gateway rather than
 * being written four times and drifting apart.
 */
final class AnthropicProvider implements TextGenerator
{
    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @param  array<string, mixed>  $config  This provider's ai_providers row, plus its key.
     */
    public function __construct(
        private readonly BaseHttpClient $http,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function generateText(TextGenerationRequest $request, ResolvedModel $model): TextResult
    {
        $payload = $this->payload($request, $model);

        $response = $this->translatingErrors(
            fn (): array => $this->http->json('POST', '/v1/messages', $payload, $this->headers())
        );

        return $this->toResult($response, $model);
    }

    public function streamText(TextGenerationRequest $request, ResolvedModel $model): \Generator
    {
        if (! $model->supportsStreaming) {
            throw UnsupportedCapabilityException::forStreaming($model);
        }

        $payload = $this->payload($request, $model) + ['stream' => true];

        $chunks = $this->translatingStreamErrors(
            $this->http->stream('POST', '/v1/messages', $payload, $this->headers())
        );

        $text = '';
        $stopReason = null;
        $inputTokens = null;
        $outputTokens = null;

        foreach (ServerSentEvents::parse($chunks) as $event) {
            $data = json_decode($event['data'], associative: true);

            if (! is_array($data)) {
                continue;
            }

            switch ($data['type'] ?? $event['event']) {
                // Input tokens are known as soon as the prompt is read; output
                // tokens only at the end. Both are needed to price the call.
                case 'message_start':
                    $inputTokens = $data['message']['usage']['input_tokens'] ?? null;
                    break;

                case 'content_block_delta':
                    // Thinking models also emit thinking_delta on this event.
                    // Only text_delta is the answer.
                    if (($data['delta']['type'] ?? null) === 'text_delta') {
                        $fragment = (string) ($data['delta']['text'] ?? '');
                        $text .= $fragment;

                        yield $fragment;
                    }
                    break;

                case 'message_delta':
                    $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
                    $outputTokens = $data['usage']['output_tokens'] ?? $outputTokens;
                    break;

                    // The vendor can fail mid-stream after a 200 OK. Without this
                    // the consumer sees a short but apparently successful answer.
                case 'error':
                    throw new InvalidResponseException(
                        'Anthropic stream failed: '.
                        (string) ($data['error']['message'] ?? 'unknown error')
                    );
            }
        }

        $this->assertNotRefused($stopReason, $text);

        // The generator's return value carries what only the closing events
        // knew, so the gateway can cache and price a streamed call exactly as
        // it would a synchronous one.
        return new TextResult(
            text: $text,
            provider: $this->name(),
            model: $model->key,
            usage: new TokenUsage(
                inputTokens: $inputTokens !== null ? (int) $inputTokens : null,
                outputTokens: $outputTokens !== null ? (int) $outputTokens : null,
            ),
            stopReason: $stopReason !== null ? (string) $stopReason : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TextGenerationRequest $request, ResolvedModel $model): array
    {
        $payload = [
            'model' => $model->apiId,
            'max_tokens' => $this->maxTokens($request, $model),
            'messages' => [
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
        ];

        if ($request->systemPrompt !== null) {
            $payload['system'] = $request->systemPrompt;
        }

        // Newer Anthropic models reject sampling parameters outright with a
        // 400 rather than ignoring them, so temperature is sent only where the
        // model accepts it. This is exactly the kind of per-vendor, per-model
        // quirk that belongs in a provider and nowhere else.
        if ($model->supportsSampling) {
            $payload['temperature'] = $request->temperature;
        }

        return $payload;
    }

    /**
     * The API requires max_tokens, and asking for more than a model can
     * produce is an error, so the model's own ceiling is the upper bound.
     */
    private function maxTokens(TextGenerationRequest $request, ResolvedModel $model): int
    {
        $requested = $request->maxTokens ?? $model->maxOutputTokens ?? self::DEFAULT_MAX_TOKENS;

        return $model->maxOutputTokens !== null
            ? min($requested, $model->maxOutputTokens)
            : $requested;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'x-api-key' => (string) ($this->config['key'] ?? ''),
            'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function toResult(array $response, ResolvedModel $model): TextResult
    {
        if (! isset($response['content']) || ! is_array($response['content'])) {
            throw InvalidResponseException::missingField($this->name(), 'content');
        }

        $stopReason = isset($response['stop_reason']) ? (string) $response['stop_reason'] : null;

        // `content` is a list of polymorphic blocks. Reading content[0].text
        // breaks the moment a thinking block arrives first, so every text
        // block is collected and everything else ignored.
        $text = '';

        foreach ($response['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $this->assertNotRefused($stopReason, $text, $response);

        return new TextResult(
            text: $text,
            provider: $this->name(),
            model: $model->key,
            usage: new TokenUsage(
                inputTokens: isset($response['usage']['input_tokens'])
                    ? (int) $response['usage']['input_tokens']
                    : null,
                outputTokens: isset($response['usage']['output_tokens'])
                    ? (int) $response['usage']['output_tokens']
                    : null,
            ),
            stopReason: $stopReason,
        );
    }

    /**
     * A refusal arrives as HTTP 200 with an empty answer.
     *
     * Left unchecked it reads as a valid-but-empty result, and a tool happily
     * stores a blank description. It has to become an exception here, where we
     * still know why the text is empty.
     *
     * @param  array<string, mixed>  $response
     */
    private function assertNotRefused(?string $stopReason, string $text, array $response = []): void
    {
        if ($stopReason !== 'refusal') {
            return;
        }

        throw new ContentRefusedException(
            'Anthropic declined to answer'.
            ($text !== '' ? ': '.$text : '.'),
            category: isset($response['stop_details']['category'])
                ? (string) $response['stop_details']['category']
                : null,
        );
    }

    /**
     * Turn this vendor's error envelope into a message worth reading.
     *
     * The transport layer already decided *which* typed exception a status
     * maps to — that part is generic HTTP. What it cannot know is that
     * Anthropic explains itself in `error.message`, because every vendor
     * chooses a different shape. Centralising this would mean one class that
     * knows every vendor's error format: the exact coupling the provider layer
     * exists to prevent.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function translatingErrors(callable $call): mixed
    {
        try {
            return $call();
        } catch (HttpClientException $e) {
            throw $this->enriched($e);
        }
    }

    /**
     * The streaming twin of translatingErrors().
     *
     * A generator does nothing until it is iterated, so the request — and any
     * error it raises — happens on the first read, not when stream() is
     * called. Wrapping the *call* in try/catch misses every streamed error;
     * the iteration itself has to happen inside the try.
     *
     * @param  \Generator<int, string>  $chunks
     * @return \Generator<int, string>
     */
    private function translatingStreamErrors(\Generator $chunks): \Generator
    {
        try {
            yield from $chunks;
        } catch (HttpClientException $e) {
            throw $this->enriched($e);
        }
    }

    /**
     * Same class and same subclass state, so retry semantics and every catch
     * block upstream keep working — only the message improves.
     */
    private function enriched(HttpClientException $e): HttpClientException
    {
        $detail = $this->errorMessage($e->responseBody);

        return $detail === null ? $e : $e->withMessage("Anthropic: {$detail}");
    }

    private function errorMessage(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, associative: true);

        return is_array($decoded) && isset($decoded['error']['message'])
            ? (string) $decoded['error']['message']
            : null;
    }
}
