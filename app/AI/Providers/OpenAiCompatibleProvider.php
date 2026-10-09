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
 * Any vendor that speaks the OpenAI chat-completions format.
 *
 * That is most of them — Groq, Google's Gemini endpoint, OpenRouter, Mistral,
 * NVIDIA, Cloudflare, a local Ollama — which is why this class is one driver
 * shared by many provider rows rather than one class per vendor. Adding such
 * a vendor is a database row: a base URL and a name.
 *
 * "Compatible" is not "identical", so the differences that do exist are data:
 * extra headers on the provider row, extra request parameters on the model row
 * (e.g. `reasoning_effort` for reasoning models).
 */
final class OpenAiCompatibleProvider implements TextGenerator
{
    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @param  array<string, mixed>  $config  The provider row: slug, key, settings.
     */
    public function __construct(
        private readonly BaseHttpClient $http,
        private readonly array $config,
    ) {}

    /**
     * The provider row's slug, not a fixed string: this one class serves many
     * vendors, and the usage log must say which one actually answered.
     */
    public function name(): string
    {
        return (string) ($this->config['slug'] ?? 'openai_compatible');
    }

    public function generateText(TextGenerationRequest $request, ResolvedModel $model): TextResult
    {
        $response = $this->translatingErrors(
            fn (): array => $this->http->json('POST', 'chat/completions', $this->payload($request, $model), $this->headers())
        );

        $choice = $response['choices'][0] ?? throw InvalidResponseException::missingField($this->name(), 'choices');
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $stopReason = $this->normaliseStopReason($choice['finish_reason'] ?? null);

        $this->assertUsable($text, $stopReason, $message['refusal'] ?? null);

        return new TextResult(
            text: $text,
            provider: $this->name(),
            model: $model->key,
            usage: $this->usageFrom($response['usage'] ?? null),
            stopReason: $stopReason,
        );
    }

    public function streamText(TextGenerationRequest $request, ResolvedModel $model): \Generator
    {
        if (! $model->supportsStreaming) {
            throw UnsupportedCapabilityException::forStreaming($model);
        }

        $payload = $this->payload($request, $model) + [
            'stream' => true,
            // Without this, a streamed call reports no token usage at all —
            // and an unpriced call is invisible to the daily spend cap.
            'stream_options' => ['include_usage' => true],
        ];

        $chunks = $this->translatingStreamErrors(
            $this->http->stream('POST', 'chat/completions', $payload, $this->headers())
        );

        $text = '';
        $stopReason = null;
        $refusal = null;
        $usage = null;

        foreach (ServerSentEvents::parse($chunks) as $event) {
            if (trim($event['data']) === '[DONE]') {
                break;
            }

            $data = json_decode($event['data'], associative: true);

            if (! is_array($data)) {
                continue;
            }

            // The vendor can fail after a 200 OK and some fragments. Without
            // this, the caller would see a short answer that looks complete.
            if (isset($data['error'])) {
                throw new InvalidResponseException("{$this->name()} stream failed: ".$this->describeError($data));
            }

            $choice = $data['choices'][0] ?? null;

            if (is_array($choice)) {
                $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

                // Only `content` is the answer. Reasoning models stream their
                // thinking in `reasoning` / `reasoning_content`, which must
                // never be spliced into what the user reads.
                $fragment = $delta['content'] ?? null;

                if (is_string($fragment) && $fragment !== '') {
                    $text .= $fragment;

                    yield $fragment;
                }

                $refusal = $delta['refusal'] ?? $refusal;
                $stopReason = $this->normaliseStopReason($choice['finish_reason'] ?? null) ?? $stopReason;
            }

            // Usage arrives in a final chunk of its own, with no choices.
            $usage = $data['usage'] ?? $usage;
        }

        $this->assertUsable($text, $stopReason, $refusal);

        return new TextResult(
            text: $text,
            provider: $this->name(),
            model: $model->key,
            usage: $this->usageFrom($usage),
            stopReason: $stopReason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TextGenerationRequest $request, ResolvedModel $model): array
    {
        $messages = [];

        if ($request->systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $request->userPrompt];

        $payload = [
            'model' => $model->apiId,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens($request, $model),
        ];

        if ($model->supportsSampling) {
            $payload['temperature'] = $request->temperature;
        }

        // Per-model extras from the database win, so a quirk of one model is
        // fixed by editing its row rather than this class.
        return [...$payload, ...$model->requestOptions];
    }

    private function maxTokens(TextGenerationRequest $request, ResolvedModel $model): int
    {
        $requested = $request->maxTokens ?? $model->maxOutputTokens ?? self::DEFAULT_MAX_TOKENS;

        return $model->maxOutputTokens !== null ? min($requested, $model->maxOutputTokens) : $requested;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        /** @var array<string, string> $extra */
        $extra = $this->config['settings']['headers'] ?? [];

        return ['Authorization' => 'Bearer '.(string) ($this->config['key'] ?? ''), ...$extra];
    }

    /**
     * 'length' is this format's word for running out of tokens. Mapping it to
     * the shared 'max_tokens' keeps TextResult::wasTruncated() vendor-neutral.
     */
    private function normaliseStopReason(mixed $finishReason): ?string
    {
        return match ($finishReason) {
            null, '' => null,
            'length' => 'max_tokens',
            default => (string) $finishReason,
        };
    }

    /**
     * Two ways a 200 OK carries no usable answer.
     *
     * A refusal or content filter is the model declining. An empty answer cut
     * off by the token limit is a reasoning model that spent its whole budget
     * thinking — it happens with small limits, and stored as-is it would look
     * like the model had nothing to say.
     */
    private function assertUsable(string $text, ?string $stopReason, mixed $refusal): void
    {
        if ((is_string($refusal) && $refusal !== '') || $stopReason === 'content_filter') {
            throw new ContentRefusedException(
                "{$this->name()} declined to answer".(is_string($refusal) && $refusal !== '' ? ": {$refusal}" : '.'),
            );
        }

        if ($text === '' && $stopReason === 'max_tokens') {
            throw new InvalidResponseException(
                "{$this->name()} used its whole token budget before answering. Raise max_tokens or lower the model's reasoning effort."
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    private function usageFrom(?array $usage): TokenUsage
    {
        return new TokenUsage(
            inputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
        );
    }

    /**
     * Same idea as AnthropicProvider: keep the transport's exception type,
     * improve its message with what the vendor actually said.
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
     * called. Wrapping the *call* in try/catch would miss every error; the
     * iteration has to happen inside the try.
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

    private function enriched(HttpClientException $e): HttpClientException
    {
        $decoded = json_decode((string) $e->responseBody, associative: true);

        return is_array($decoded) && isset($decoded['error'])
            ? $e->withMessage("{$this->name()}: ".$this->describeError($decoded))
            : $e;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function describeError(array $body): string
    {
        $error = $body['error'];

        if (is_string($error)) {
            return $error;
        }

        $message = (string) ($error['message'] ?? 'unknown error');

        // OpenRouter wraps the upstream vendor's own explanation here, which is
        // usually the more useful half ("rate-limited upstream", ...).
        $upstream = $error['metadata']['raw'] ?? null;

        return is_string($upstream) && $upstream !== '' ? "{$message} ({$upstream})" : $message;
    }
}
