<?php

declare(strict_types=1);

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\DeepLProvider;
use App\AI\Providers\OpenAiCompatibleProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Providers and models live in the database (ai_providers, ai_models), so
    | they can be added and edited without a deploy. What a database row cannot
    | hold is code: each provider row names one of these driver keys, and this
    | map is the only place a key becomes a class.
    |
    | A short key rather than a class name in the table is deliberate — a class
    | name in a table is one admin form away from instantiating anything.
    |
    */

    'drivers' => [
        'openai_compatible' => OpenAiCompatibleProvider::class,
        'anthropic' => AnthropicProvider::class,
        'deepl' => DeepLProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | API keys by provider slug. Secrets stay in .env and never reach the
    | database or git. A new provider row needs one line here and one in .env.
    |
    | Read through config rather than env() at runtime: once config is cached,
    | env() outside a config file returns null.
    |
    */

    'credentials' => [
        'groq' => env('GROQ_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
        'openrouter' => env('OPENROUTER_API_KEY'),
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'deepl' => env('DEEPL_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Egress proxy
    |--------------------------------------------------------------------------
    |
    | Used for every provider whose row does not set its own proxy, so all
    | vendor traffic can be routed through a relay with one setting.
    |
    */

    'proxy' => env('AI_HTTP_PROXY'),

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
    | Daily spend cap
    |--------------------------------------------------------------------------
    |
    | Platform-owned keys behind a public page are an open door to our own
    | credit card. Once today's recorded spend reaches this ceiling, the gateway
    | refuses every call that would reach a vendor — cached answers are still
    | served, since they cost nothing.
    |
    | The total is read from usage_logs and cached for `recompute_seconds`, so
    | the cap can be overshot by at most one window's worth of traffic. The
    | vendor-side limit in each provider's dashboard is the backstop for that.
    |
    */

    'spend' => [
        'daily_cap_usd' => (float) env('AI_DAILY_SPEND_CAP_USD', 5.0),
        'recompute_seconds' => (int) env('AI_SPEND_RECOMPUTE_SECONDS', 60),
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

        // ...but a stream that goes silent for this long is treated as dead.
        'stream_idle_timeout' => (int) env('AI_STREAM_IDLE_TIMEOUT', 30),

        // Total attempts: 3 means one try plus two retries. Applies to
        // transport failures only (connection reset, 429, 5xx).
        'max_attempts' => (int) env('AI_HTTP_MAX_ATTEMPTS', 3),

        'base_backoff_ms' => (int) env('AI_HTTP_BACKOFF_MS', 500),
    ],

];
