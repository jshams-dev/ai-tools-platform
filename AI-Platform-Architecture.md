# AI Tools Platform — Architecture Specification

**Version 3**
Laravel · Service Layer · Livewire UI + REST API · Multi-Provider AI

---

## 1. Governing Principle

**Everything in its place.** Each layer has a single responsibility and stays unaware of the internals of the others. The Controller knows nothing about how AI is called over HTTP; the AI Provider knows nothing about "hotels"; the HTTP Client knows nothing about "AI".

**Two layer types that must never be mixed:**

- **Business Logic (Services)** — *what should happen*: the domain logic of each tool.
- **Infrastructure (AI, Http, Repository)** — *how*: infrastructure concerns like talking to AI, HTTP transport, and database access.

**Modular / micro-service benefit:** each Service is an independent module. Right now this is a modular monolith; if a service ever needs to be extracted into its own micro-service, its boundary is already clean enough to split without rewriting the logic.

---

## 2. Dual Entry Points — One Service Layer

The system exposes **two independent entry points** that both consume the same Service Layer. Neither one calls the other.

```
                    +--> Livewire Component --+
                    |    (main web UI)        |
  Browser / Client -+                         +--> SERVICE LAYER --> AI
                    |                         |
                    +--> API Controller ------+
                         (REST, for mobile /
                          external consumers)
```

**Critical rule:** Livewire must **never** make an HTTP call to our own REST API. Both run on the same server; Livewire calls the Service directly. An internal HTTP round-trip would add latency and complexity for nothing.

**Why this works:** because business logic lives in the Service Layer (not in controllers), any number of "front doors" can be added without duplicating logic. This is the direct payoff of layered architecture.

**Streaming:** Livewire 3 provides `wire:stream` for token-by-token AI output; the REST API uses SSE. Both are fed by the same service.

---

## 3. Full Layer Overview

```
  Blade + Livewire  (UI - server rendered, SEO friendly)
  REST API Controllers  (parallel entry point)
        |
        v
  +-------------------------------------------------------+
  |               SERVICE LAYER (business logic)          |
  |   HotelDescriptionService | RoomNormalizerService     |
  |   TextTransformerService                              |
  +-------------------------------------------------------+
        |                          |
        | uses                     | uses
        v                          v
  +------------------+     +----------------------------+
  |  Repository      |     |   AiCompletionService      |
  |  (only where     |     |  (shared AI concerns:      |
  |   data may       |     |   factory, cache, log,     |
  |   change)        |     |   rate-limit, retry)       |
  +------------------+     +----------------------------+
        |                              |
        v                              v
    Model / DB            AiProviderInterface (Strategy)
                       OpenAI | Gemini | Claude | Free
                                       |
                                       v
                           BaseHttpClient (transport)
                          retry | timeout | headers
                                       |
                                       v
                           Exception Layer (typed errors)
```

---

## 4. Service Layer (the heart of business logic)

Each tool has its own Service. Controllers and Livewire components only receive input and hand off to the Service. All logic lives here.

```php
class HotelDescriptionService
{
    public function __construct(
        private AiCompletionService $ai,
    ) {}

    public function generate(HotelInput $input): ToolResult
    {
        // 1. business validation / rules
        // 2. build the prompt (domain knowledge)
        $prompt = $this->buildPrompt($input);

        // 3. delegate the AI call
        $response = $this->ai->complete(
            new CompletionRequest($prompt, $input->options(),
                                  context: 'hotel-description')
        );

        // 4. post-process + return
        return new ToolResult($response->text());
    }
}
```

> **Note:** This Service knows nothing about HTTP, caching, or provider selection. It only knows "hotel" logic. Everything else is delegated to `AiCompletionService`.

---

## 5. AiCompletionService (the AI mediation layer)

This layer centralizes the concerns shared by *every* tool. Each new tool automatically inherits all of them:

- Correct provider selection via `ProviderFactory`
- Result caching (same input + same model = cached response)
- Rate limiting and usage logging
- Retry logic and translation of provider errors into a uniform format

**Clean input via a DTO:**

```php
class CompletionRequest   // DTO
{
    public function __construct(
        public string $prompt,
        public array  $options = [],   // model, temperature
        public ?string $context = null // tool slug, for logging
    ) {}
}

class AiCompletionService
{
    public function __construct(
        private ProviderFactory $factory,
        private CacheRepository $cache,
        private UsageLogRepository $logs,
    ) {}

    public function complete(CompletionRequest $req): AiResponse
    {
        // cache check -> factory->make() -> provider->complete()
        // -> log usage -> return
    }
}
```

**Why a DTO?** Instead of passing 5–6 scattered parameters, one clean object. Extensible, readable, and signals attention to interface design.

---

## 6. AI Provider Layer (Strategy)

Each provider implements one identical interface. Tools never talk to OpenAI directly.

```php
interface AiProviderInterface
{
    public function complete(
        string $prompt, array $options = []
    ): AiResponse;

    public function name(): string;
    public function availableModels(): array;
}

// Implementations:
//   OpenAiProvider | GeminiProvider |
//   ClaudeProvider | FreeProvider

class ProviderFactory
{
    // returns the appropriate provider based on
    // the requested model / tier
    public function make(?string $model = null)
        : AiProviderInterface;
}
```

**Pattern:** Factory + Strategy. Adding a new provider = one new class, with no change to any tool.

**Default tier:** free users are served by fast, cheap models (e.g. `gpt-4o-mini`, `gemini-flash`) with strict rate limiting. No user-supplied API keys are accepted — see §12.

---

## 7. HTTP Client and Exceptions

**Why separate from AI?** HTTP is a generic concern, not AI-specific. Tomorrow a tool might connect to a non-AI API (e.g. DeepL or a weather API). So transport must be independent, and the AI Provider uses it.

```
app/Clients/
|-- BaseHttpClient.php     # the only place raw HTTP happens
|                          # retry, timeout, headers
|-- Exceptions/
    |-- HttpClientException.php   # base
    |-- RateLimitException.php
    |-- TimeoutException.php
    |-- InvalidApiKeyException.php
```

Flow: **Provider → BaseHttpClient → External API.** Every raw HTTP error is translated in this layer into a meaningful, typed exception. This makes precise handling of error code, error message, and error type trivial upstream.

> AI-specific errors (like an invalid response format) live in `app/AI/Exceptions` — separate from transport errors.

---

## 8. Database, Models, and Repositories

**Repository pattern only where it earns its place** — for data sources likely to change (caching, logs). Simple models are used directly to avoid over-engineering.

**Initial tables:**

- **usage_logs** — which tool, which provider, when, how many tokens (no sensitive content stored)
- **cached_results** — persistent result cache (optional; can start with Laravel's regular cache)

```
app/Repositories/
|-- UsageLogRepository.php
|-- CacheRepository.php

app/Models/
|-- UsageLog.php
```

---

## 9. Tool System and Registry

Beyond its Service, each tool has a definition that registers it with the system (name, slug, category, input form). The Registry registers them all automatically.

```php
interface ToolInterface
{
    public function slug(): string;
    public function name(): string;
    public function category(): string;   // general | travel
    public function inputSchema(): array;
    public function service(): object;     // its Service class
}

// ToolRegistry auto-registers all tools via
// a ServiceProvider. Adding a tool = one class,
// no core changes.
```

---

## 10. Frontend — Livewire

Livewire is the right choice for the main UI, without over-engineering:

- Stays inside Laravel/Blade — no build step, no SPA complexity
- Great for tool forms (input → result) that need to be reactive
- **SEO friendly** — server-rendered, unlike a React/Vue SPA which is painful for SEO
- `wire:stream` handles token-by-token AI output
- Stays lightweight and can be strengthened later

The REST API exists in parallel for future consumers (mobile app, external integrations, or a separate JS frontend) — not as a dependency of the web UI.

---

## 11. Full Folder Structure

```
app/
|-- Http/Controllers/Api/     # REST API (parallel entry point)
|-- Livewire/                 # livewire components (main UI)
|
|-- Services/                 # BUSINESS LOGIC
|   |-- AiCompletionService.php
|   |-- Tools/
|       |-- HotelDescriptionService.php
|       |-- RoomNormalizerService.php
|       |-- TextTransformerService.php
|
|-- AI/                       # AI infrastructure
|   |-- Contracts/AiProviderInterface.php
|   |-- Providers/ (OpenAi, Gemini, Claude, Free)
|   |-- ProviderFactory.php
|   |-- DTO/CompletionRequest.php
|   |-- AiResponse.php
|   |-- Exceptions/
|
|-- Clients/                  # generic HTTP transport
|   |-- BaseHttpClient.php
|   |-- Exceptions/
|
|-- Repositories/             # DB access (where justified)
|-- Models/
|-- Tools/                    # tool definitions + registry
|   |-- Contracts/ToolInterface.php
|   |-- ToolRegistry.php
|   |-- Support/RuleBasedClassifier.php
|
|-- Providers/ToolServiceProvider.php

resources/views/  +  livewire components
routes/web.php  +  routes/api.php
```

---

## 12. Explicitly Out of Scope (v1)

Decisions made deliberately to keep v1 shippable:

**BYOK (Bring Your Own Key) — removed.**
Accepting user API keys adds real security burden (encryption at rest, session handling, leak risk in logs) without adding proportional architectural value for a portfolio-stage project. All AI calls use platform-owned keys with server-side rate limiting.

**No payment or billing in v1.** See §13.

---

## 13. Future Work

Items intentionally deferred. Each is a real feature, not a stub — they are listed here so the architecture leaves room for them without building them now.

### 13.1 Credit / Wallet System

The natural monetization path once the platform has real users: free users get fast, cheap models; heavier models require credits.

**What it requires (why it is deferred):**

- `user_wallets` and `credit_transactions` tables with an auditable ledger
- Payment gateway integration
- Credit deduction inside `AiCompletionService` — including **refund-on-failure** when a provider call fails after deduction
- **Concurrency safety** — atomic balance updates to prevent race conditions on simultaneous requests
- Balance display, low-balance warnings, purchase flow in the UI

**Where it plugs in:** `AiCompletionService` is already the single choke point for every AI call, so credit checks and deductions belong there — no other layer needs to change. This is exactly why the mediation layer exists.

### 13.2 Other Deferred Items

- **User accounts / auth** — only needed once credits or saved history exist
- **Saved history** — letting users revisit past tool results
- **Additional tools** — the registry makes each one a single new class
- **Separate JS frontend** — the REST API already exists for this if Livewire is ever outgrown
- **Advanced SEO** — programmatic landing pages per tool and per use case

---

## 14. Build Phases

| Phase | Work | Deliverable |
|-------|------|-------------|
| 1 | `BaseHttpClient` + Exception layer + `AiProviderInterface` + one real provider + Factory | AI core + transport |
| 2 | `AiCompletionService` + DTO + Repositories + Model/migration | Full mediation layer |
| 3 | Tool system (Interface + Registry) + first tool Service (Text Transformer) | First tool live |
| 4 | Livewire UI (tool list, tool page) + REST API endpoints | Both entry points |
| 5 | Two travel-specific tools (Hotel Description + Room Normalizer) | 3 tools |
| 6 | README, tests, basic SEO, deploy | Published |

**Phase 1 is the most foundational.** The transport and provider layers are the core of everything.

---

## 15. Resume Mapping

This project strengthens several resume bullets at once:

- **AI Integration** — multiple providers, Factory/Strategy pattern, streaming
- **System Architecture** — extensible design with interfaces, registry, and dual entry points over one service layer
- **Product Engineering** — starts from a real problem (duplicate content in OTAs)
- **SEO** — if actually deployed and ranking, it becomes a genuine strength

**Next step:** Phase 1 — the transport and provider layers.
