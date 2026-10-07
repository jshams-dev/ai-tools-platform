<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Contracts\Translator;
use App\AI\Exceptions\InvalidResponseException;
use App\AI\Requests\TranslationRequest;
use App\AI\ResolvedModel;
use App\AI\Results\TranslationResult;
use App\Clients\BaseHttpClient;
use App\Clients\Exceptions\HttpClientException;

/**
 * DeepL's translation API.
 *
 * This class is the argument for the whole capability design, in about a
 * hundred lines. DeepL is not a chat model: it takes text and a target
 * language, has no concept of a system prompt, and cannot stream. Behind a
 * single shared `complete(string $prompt)` interface, most of this file would
 * have to be a method that throws — and the interface would be advertising
 * behaviour the object does not have.
 *
 * Implementing only Translator means the compiler enforces the truth. Passing
 * a DeepL model to code that generates text does not fail in production; it
 * fails in the editor.
 */
final class DeepLProvider implements Translator
{
    /**
     * @param  array<string, mixed>  $config  This provider's config/ai.php row.
     */
    public function __construct(
        private readonly BaseHttpClient $http,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'deepl';
    }

    public function translate(TranslationRequest $request, ResolvedModel $model): TranslationResult
    {
        $payload = [
            // The endpoint is batch-shaped even for a single string.
            'text' => [$request->text],
            'target_lang' => strtoupper($request->targetLanguage),
        ];

        if ($request->sourceLanguage !== null) {
            $payload['source_lang'] = strtoupper($request->sourceLanguage);
        }

        if ($request->formality !== null) {
            // Our vendor-neutral 'more'/'less' becomes DeepL's own spelling.
            // Preferring the soft variants means a language that does not
            // support formality is ignored rather than rejected outright.
            $payload['formality'] = match ($request->formality) {
                'more' => 'prefer_more',
                'less' => 'prefer_less',
                default => 'default',
            };
        }

        $response = $this->translatingErrors(
            fn (): array => $this->http->json('POST', '/v2/translate', $payload, $this->headers())
        );

        $translation = $response['translations'][0]
            ?? throw InvalidResponseException::missingField($this->name(), 'translations');

        return new TranslationResult(
            text: (string) ($translation['text'] ?? ''),
            provider: $this->name(),
            model: $model->key,
            detectedSourceLanguage: isset($translation['detected_source_language'])
                ? (string) $translation['detected_source_language']
                : null,
            // No TokenUsage: DeepL bills per character. Reporting zero tokens
            // would quietly understate the daily spend the circuit breaker
            // sums, so the absence is left honest.
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'DeepL-Auth-Key '.(string) ($this->config['key'] ?? ''),
        ];
    }

    /**
     * DeepL reports failures in a `message` field, and uses one status code
     * nobody else does — 456 for a used-up quota. Both are facts about this
     * vendor, so both are decoded here rather than in the transport layer.
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
            $detail = $e->statusCode === 456
                ? 'translation quota exceeded for this billing period'
                : $this->errorMessage($e->responseBody);

            throw $detail === null ? $e : $e->withMessage("DeepL: {$detail}");
        }
    }

    private function errorMessage(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, associative: true);

        return is_array($decoded) && isset($decoded['message'])
            ? (string) $decoded['message']
            : null;
    }
}
