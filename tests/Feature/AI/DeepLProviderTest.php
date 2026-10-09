<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Contracts\Translator;
use App\AI\Exceptions\InvalidResponseException;
use App\AI\ModelRegistry;
use App\AI\ProviderFactory;
use App\AI\Requests\TranslationRequest;
use App\AI\ResolvedModel;
use App\Clients\Exceptions\HttpClientException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsAiCatalog;
use Tests\TestCase;

final class DeepLProviderTest extends TestCase
{
    use SeedsAiCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        // Real-looking credentials: with an empty key the Authorization header
        // collapses to whitespace and the header assertions stop meaning
        // anything, which would hide a genuine auth-header bug.
        config([
            'ai.credentials.anthropic' => 'sk-ant-test-key',
            'ai.credentials.deepl' => 'deepl-test-key',
        ]);
    }

    private function model(): ResolvedModel
    {
        return $this->app->make(ModelRegistry::class)->get('deepl');
    }

    private function provider(): Translator
    {
        return $this->app->make(ProviderFactory::class)->translator($this->model());
    }

    #[Test]
    public function it_translates_text(): void
    {
        Http::fake(['api-free.deepl.com/*' => Http::response([
            'translations' => [
                ['detected_source_language' => 'EN', 'text' => 'سلام دنیا'],
            ],
        ])]);

        $result = $this->provider()->translate(
            new TranslationRequest(text: 'Hello world', targetLanguage: 'fa'),
            $this->model(),
        );

        $this->assertSame('سلام دنیا', $result->text);
        $this->assertSame('EN', $result->detectedSourceLanguage);
        $this->assertSame('deepl', $result->provider());
    }

    #[Test]
    public function it_sends_deepls_wire_format(): void
    {
        Http::fake(['api-free.deepl.com/*' => Http::response([
            'translations' => [['text' => 'Bonjour']],
        ])]);

        $this->provider()->translate(
            new TranslationRequest(
                text: 'Hello',
                targetLanguage: 'fr',
                sourceLanguage: 'en',
                formality: 'more',
            ),
            $this->model(),
        );

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            $this->assertSame('https://api-free.deepl.com/v2/translate', $request->url());
            $this->assertStringStartsWith('DeepL-Auth-Key ', $request->header('Authorization')[0]);

            // DeepL wants uppercase codes and a batch-shaped text field even
            // for one string.
            $this->assertSame(['Hello'], $body['text']);
            $this->assertSame('FR', $body['target_lang']);
            $this->assertSame('EN', $body['source_lang']);

            // Our vendor-neutral 'more' becomes DeepL's own spelling.
            $this->assertSame('prefer_more', $body['formality']);

            return true;
        });
    }

    #[Test]
    public function it_reports_no_token_usage(): void
    {
        Http::fake(['api-free.deepl.com/*' => Http::response([
            'translations' => [['text' => 'Bonjour']],
        ])]);

        $result = $this->provider()->translate(
            new TranslationRequest(text: 'Hello', targetLanguage: 'fr'),
            $this->model(),
        );

        // DeepL bills per character. Reporting zero tokens would look like a
        // free call and quietly understate the daily spend total.
        $this->assertNull($result->usage()->totalTokens());
        $this->assertNull($result->usage()->costUsd($this->model()));
    }

    #[Test]
    public function it_explains_deepls_own_quota_status_code(): void
    {
        // 456 is DeepL's alone. The transport layer maps it to a generic
        // client error; only this provider knows what it means.
        Http::fake(['api-free.deepl.com/*' => Http::response(['message' => 'Quota Exceeded'], 456)]);

        try {
            $this->provider()->translate(
                new TranslationRequest(text: 'Hello', targetLanguage: 'fr'),
                $this->model(),
            );
            $this->fail('Expected an HttpClientException.');
        } catch (HttpClientException $e) {
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
            $this->assertSame(456, $e->statusCode);
        }
    }

    #[Test]
    public function it_rejects_a_response_with_no_translations(): void
    {
        Http::fake(['api-free.deepl.com/*' => Http::response(['translations' => []])]);

        $this->expectException(InvalidResponseException::class);

        $this->provider()->translate(
            new TranslationRequest(text: 'Hello', targetLanguage: 'fr'),
            $this->model(),
        );
    }
}
