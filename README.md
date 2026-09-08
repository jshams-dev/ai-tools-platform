# AI Tools Platform

A Laravel platform hosting a set of AI-powered tools — each one solving a real, specific
problem rather than wrapping a chat box. Built around a capability-based AI abstraction
that supports multiple providers (OpenAI, Anthropic, Google, DeepL) behind one clean seam.

> **Status:** Phase 1 (AI core + transport) is built and tested. Phase 2 is the `AiGateway`.

## Why this exists

Most "AI tool" sites are directories linking to other people's products. This one runs the
tools itself. The first tools target a concrete problem: hotels and OTAs publishing
duplicate, low-quality property descriptions that rank badly and convert worse.

## Architecture

The full specification lives in
**[AI-Platform-Architecture.md](AI-Platform-Architecture.md)**.

The design is built so that three kinds of growth are always additive, never a refactor:

| Adding… | Costs |
|---|---|
| a new **tool** | 1 definition class + 1 handler class |
| a new **provider** | 1 class + 1 config entry |
| a new **AI capability** (video, audio, vision) | 1 enum case + 1 request class + 1 interface |

Highlights:

- **Capability-based AI layer** — providers are segregated by what they can actually do,
  so DeepL is never forced to implement a text-completion method it cannot honour
- **Single choke point** — one `AiGateway` owns caching, quota, logging, model fallback,
  and spend limits for every tool and every modality
- **Streaming** — `wire:stream` on the web, SSE on the API, with abort detection so a
  closed tab stops costing money
- **Usage quotas in units, not requests** — priced by real cost, and the same counter
  becomes the credit system later without a rewrite
- **Dual entry points** — Livewire UI and a REST API over one service layer, with the
  UI calling the layer directly rather than round-tripping through our own HTTP

## What exists today

Phase 1 — the AI core and its transport, with 72 tests.

```
app/
├── AI/
│   ├── Capability.php            enum: the axis the layer grows along
│   ├── ModelRegistry.php         config/ai.php -> typed ResolvedModel
│   ├── ProviderFactory.php       one typed method per capability
│   ├── Contracts/                AiProvider, TextGenerator, Translator
│   ├── Requests/                 command objects that dispatch themselves
│   ├── Results/                  TextResult, TranslationResult, TokenUsage
│   └── Providers/                AnthropicProvider, DeepLProvider
└── Clients/
    ├── BaseHttpClient.php        the only place raw HTTP happens
    └── ServerSentEvents.php      SSE framing, vendor-agnostic
```

Two providers rather than one, on purpose: `AnthropicProvider` generates text
(synchronously and streaming), `DeepLProvider` implements `Translator` **only**.
The second is what makes the capability argument enforceable rather than merely
stated — handing a DeepL model to code that generates text is a type error, not
a runtime surprise.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan test          # 72 passing
```

No API key is needed to run the suite. Every provider's traffic goes through
`BaseHttpClient`, so `Http::fake()` stands in for all of them — which is why no
fake-provider class ships in `app/`.

To make real calls, set `ANTHROPIC_API_KEY` (and `DEEPL_API_KEY`) in `.env`.
`*_BASE_URL` and `*_PROXY` are configurable per provider, so egress can be
re-pointed without touching a provider class.

## Stack

Laravel 13 · PHP 8.4 · Livewire 3 · MySQL · FrankenPHP (target deployment)

## Roadmap

See [§18 Build Phases](AI-Platform-Architecture.md#18-build-phases). Phase 3 delivers the
first working tool end to end.
