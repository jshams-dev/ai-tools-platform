<?php

declare(strict_types=1);

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\DeepLProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | One entry per vendor. A provider row cannot exist without a PHP class to
    | back it, which is exactly why this lives in config rather than a database
    | table: inserting ('mistral', 'Mistral AI') into a table would create a
    | row that nothing can execute.
    |
    | base_url and proxy are configurable per vendor so that egress can be
    | re-pointed — at a relay, a regional endpoint, or a local mock — without
    | editing a single provider class.
    |
    */

    'providers' => [

        'anthropic' => [
            'driver' => AnthropicProvider::class,
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'proxy' => env('ANTHROPIC_PROXY', env('AI_HTTP_PROXY')),
            'version' => '2023-06-01',
        ],

        'deepl' => [
            'driver' => DeepLProvider::class,
            'key' => env('DEEPL_API_KEY'),
            'base_url' => env('DEEPL_BASE_URL', 'https://api-free.deepl.com'),
            'proxy' => env('DEEPL_PROXY', env('AI_HTTP_PROXY')),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The shape here deliberately mirrors the future `ai_models` table, so that
    | moving this into a database once an admin panel exists is a mechanical
    | migration rather than a redesign.
    |
    | Two fields earn their keep:
    |
    |   api_id  The vendor's own identifier, kept separate from our key. When a
    |           vendor retires a model, one line here changes instead of every
    |           tool that named it.
    |
    |   tier    Where "free" lives. Whether a model is cheap is a property of
    |           the model, never of the provider class — which is why there is
    |           no FreeProvider anywhere in this codebase.
    |
    | Vendor model identifiers change often. Treat the values below as current
    | at the time of writing and check the vendor's docs before relying on one.
    |
    */

    'models' => [

        'claude-haiku' => [
            'provider' => 'anthropic',
            'api_id' => 'claude-haiku-4-5',
            'capabilities' => ['text_generation'],
            'tier' => 'free',
            'streaming' => true,
            'sampling' => true,
            'max_output_tokens' => 8192,
            'cost' => ['input' => 1.00, 'output' => 5.00],   // USD per 1M tokens
        ],

        'claude-sonnet' => [
            'provider' => 'anthropic',
            'api_id' => 'claude-sonnet-5',
            'capabilities' => ['text_generation'],
            'tier' => 'pro',
            'streaming' => true,
            // Rejects temperature with a 400 — see AnthropicProvider::payload().
            'sampling' => false,
            'max_output_tokens' => 64000,
            'cost' => ['input' => 2.00, 'output' => 10.00],
        ],

        'claude-opus' => [
            'provider' => 'anthropic',
            'api_id' => 'claude-opus-5',
            'capabilities' => ['text_generation'],
            'tier' => 'pro',
            'streaming' => true,
            'sampling' => false,
            'max_output_tokens' => 64000,
            'cost' => ['input' => 5.00, 'output' => 25.00],
        ],

        // Proves the point of capability segregation: this model serves only
        // translation, and no amount of prompting will make it generate text.
        'deepl' => [
            'provider' => 'deepl',
            'api_id' => 'v2',
            'capabilities' => ['translation'],
            'tier' => 'free',
            'streaming' => false,
            // DeepL bills per character, not per token, so token pricing does
            // not apply. TokenUsage reports null rather than a misleading 0.
            'cost' => ['input' => 0.0, 'output' => 0.0],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Used when nothing higher up the chain expresses a preference. Once tools
    | exist, a tool's ModelPolicy and the user's tier both take precedence over
    | this — see §8.1 of the architecture specification.
    |
    */

    'defaults' => [
        'text_generation' => env('AI_DEFAULT_TEXT_MODEL', 'claude-haiku'),
        'translation' => 'deepl',
    ],

    /*
    |--------------------------------------------------------------------------
    | Response cache
    |--------------------------------------------------------------------------
    |
    | An identical call should not be paid for twice. The store is named
    | explicitly rather than inherited from the default: AI results are large
    | and long-lived, and sharing a store with sessions means one flush takes
    | out both.
    |
    | Note that this reads CACHE_STORE rather than calling config('cache.default') —
    | config files are loaded before any of them can read another, so that call
    | would resolve to null here.
    |
    */

    'cache' => [
        'store' => env('AI_CACHE_STORE', env('CACHE_STORE', 'database')),
        'ttl' => (int) env('AI_CACHE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP transport
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('AI_HTTP_TIMEOUT', 30),

        // Streams stay open far longer than a normal call, so they get their
        // own ceiling rather than forcing the sync timeout up for everyone.
        'stream_timeout' => (int) env('AI_STREAM_TIMEOUT', 300),

        // Total attempts: 3 means one try plus two retries. Applies to
        // transport failures only (connection reset, 429, 5xx).
        'max_attempts' => (int) env('AI_HTTP_MAX_ATTEMPTS', 3),

        'base_backoff_ms' => (int) env('AI_HTTP_BACKOFF_MS', 500),
    ],

];
