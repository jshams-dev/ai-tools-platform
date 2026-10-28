# AI Tools Platform

A Laravel platform hosting a set of AI-powered tools — each one solving a real, specific
problem rather than wrapping a chat box. Built around a capability-based AI abstraction
that supports multiple providers (OpenAI, Anthropic, Google, DeepL) behind one clean seam.

> **Status:** Phases 1–4 built and tested (167 tests): the AI core, the gateway, a chat-style
> workspace with the first tool, seven free models, providers/models/tools in the database,
> accounts with a guest allowance, and a JSON + SSE API. Phase 5 — the Next.js frontend — is in
> progress on a feature branch and not merged yet. See `CHANGELOG.md` and `SESSION_LOG.md`.

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
- **Streaming** — Server-Sent Events from the API (`thinking` / `text` / `done` / `error`),
  with abort detection so a closed tab stops costing money
- **Usage quotas in units, not requests** — priced by real cost, and the same counter
  becomes the credit system later without a rewrite
- **One API, two kinds of client** — the Next.js frontend talks to Laravel only through its
  JSON + SSE API; third-party API access comes later over the same endpoints

## What exists today

Phases 1–4 — the AI core and its transport, the gateway that mediates them, the tool
layer, accounts, and the API. 167 tests. Phase 5 (the Next.js frontend) is in progress.

```
app/
├── AI/
│   ├── Capability.php            enum: the axis the layer grows along
│   ├── AiGateway.php             the single choke point: cache, fallback, logging, spend cap
│   ├── ModelPolicy.php           which models a tool may use, in what order
│   ├── ModelResolver.php         policy + tier + user choice -> a model chain
│   ├── ModelRegistry.php         the catalog -> typed ResolvedModel
│   ├── AiResponseCache.php       a cache is not a repository
│   ├── ProviderFactory.php       one typed method per capability
│   ├── Contracts/                AiProvider, TextGenerator, Translator
│   ├── Requests/                 command objects that dispatch themselves
│   ├── Results/                  TextResult, TranslationResult, ReasoningDelta, TokenUsage
│   └── Providers/                Anthropic, DeepL, OpenAiCompatible (Groq, Gemini, OpenRouter)
├── Tools/                        ToolInterface, Field schema, ToolRegistry, ToolRunner, RunError
├── Services/Tools/               tool handlers (Text Transformer)
├── Usage/                        RunContext, UsageRecord, CostCircuitBreaker
├── Repositories/                 AiCatalog, UsageLogRepository (+ Eloquent impl)
├── Http/                         API controllers and resources (/api/v1)
├── Livewire/ToolWorkspace.php    the phase 3 chat, replaced by the Next.js app in phase 5
└── Clients/
    ├── BaseHttpClient.php        the only place raw HTTP happens
    └── ServerSentEvents.php      SSE framing, vendor-agnostic
```

The gateway has **one** execution method and never names a capability — the
request dispatches itself. Adding video generation is a new request class and a
new interface; `AiGateway` is not edited. Caching, model fallback, cost
accounting and usage logging live there once instead of once per provider (four
copies that drift) or once per tool (fifteen copies).

Providers, models and tools live in the database, so they can be managed from a
future admin panel; tool *behaviour* stays in code. `DeepLProvider` implements
`Translator` **only** — handing a DeepL model to code that generates text is a
type error, not a runtime surprise.

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test          # 167 passing
```

No API key is needed to run the suite. Every provider's traffic goes through
`BaseHttpClient`, so `Http::fake()` stands in for all of them — which is why no
fake-provider class ships in `app/`.

To make real calls, set the provider keys in `.env` (Groq, Gemini and OpenRouter for the
free models; `ANTHROPIC_API_KEY` and `DEEPL_API_KEY` for the others).
`*_BASE_URL` and `*_PROXY` are configurable per provider, so egress can be
re-pointed without touching a provider class.

## Stack

Laravel 13 · PHP 8.4 · Sanctum + Fortify · MySQL / MariaDB · Next.js 16 · React 19 · Tailwind 4 ·
FrankenPHP + Node on one server (target deployment)

## Roadmap

See [§18 Build Phases](AI-Platform-Architecture.md#18-build-phases). Next: finish phase 5
(auth pages in Next.js), then phase 6 — saved chat history, plans and per-user quotas.
