# AI Tools Platform — Architecture Specification

**Version 4**
Laravel · Capability-Based AI Layer · Livewire UI + REST API · Streaming · Usage Quotas

---

## 1. Governing Principle

**Everything in its place.** Each layer has a single responsibility and stays unaware of the internals of the others. The tool knows nothing about how AI is called over HTTP; the AI provider knows nothing about "hotels"; the HTTP client knows nothing about "AI".

**Two layer types that must never be mixed:**

- **Business Logic (Tool Handlers)** — *what should happen*: the domain logic of each tool.
- **Infrastructure (AI, Clients, Repositories)** — *how*: talking to vendors, HTTP transport, database access.

**Modular monolith.** Each tool is an independent module with a clean boundary. If one ever needs to become its own service, the seam already exists.

**The three extension axes.** The architecture is designed so that each of these is an additive change, never a refactor:

| Adding… | Costs |
|---|---|
| a new **tool** | 1 definition class + 1 handler class |
| a new **provider** | 1 class + 1 config entry |
| a new **AI capability** (video, audio, vision) | 1 enum case + 1 request class + 1 interface |

No change to `AiGateway`, caching, quota, or logging in any of the three cases. Section 6 is where this is earned.

---

## 2. Dual Entry Points — One Service Layer

The system exposes **two independent entry points** that both consume the same tool layer. Neither one calls the other.

```
                    +--> Livewire Component --+
                    |    (main web UI)        |
  Browser / Client -+                         +--> TOOL HANDLER --> AI GATEWAY
                    |                         |
                    +--> API Controller ------+
                         (REST v1, token-guarded)
```

**Critical rule:** Livewire must **never** make an HTTP call to our own REST API. Both run in the same process; Livewire calls the handler directly. An internal HTTP round-trip would add latency and complexity for nothing.

**Why this works:** because business logic lives below the entry points, any number of "front doors" can be added without duplicating logic. This is the direct payoff of layered architecture — and the single best argument the codebase makes for itself.

---

## 3. Full Layer Overview

```
  Blade + Livewire            REST API v1 (token)
  (main UI, SEO, wire:stream)  (JSON + SSE)
        |                            |
        +------------+---------------+
                     v
        +-------------------------------+
        |   TOOL LAYER                  |
        |   definition  +  handler      |
        |   (business logic lives here) |
        +-------------------------------+
              |                    |
              | may use            | may use
              v                    v
        +-----------+     +--------------------------+
        | Repository|     |      AiGateway           |
        |  (logs)   |     |  model resolution        |
        +-----------+     |  quota reserve/commit    |
              |           |  cache · usage log       |
              v           |  cost circuit breaker    |
          Model / DB      +--------------------------+
                                     |
                                     v
                          ProviderFactory
                    resolve(capability, model)
                                     |
                                     v
                  Capability Interfaces (Strategy)
          TextGenerator | Translator | ImageGenerator | ...
             OpenAI · Google · Anthropic · DeepL · ...
                                     |
                                     v
                          BaseHttpClient (transport)
                         retry · timeout · headers
                                     |
                                     v
                          Typed Exception Layer
```

Note that `AiGateway` is **infrastructure**, not business logic. It lives in `app/AI/`, not `app/Services/`.

---

## 4. Tool Layer

A tool is two classes: a **definition** (metadata — what the catalog and forms need) and a **handler** (behaviour — what actually runs).

They are split because the UI must be able to list, route, and render every tool without instantiating a single handler or its dependency graph.

### 4.1 Tool Definition

```php
interface ToolInterface
{
    public function slug(): string;              // 'hotel-description'
    public function name(): string;
    public function category(): string;          // 'travel' | 'writing' | ...
    public function inputSchema(): array;        // Field[]
    public function handler(): string;           // class-string, resolved from container
    public function modelPolicy(): ModelPolicy;
    public function costUnits(): int;            // base weight, see §11
    public function component(): ?string;        // custom Livewire component, or null
}
```

`handler()` returns a **class name**, not an instance. The definition must stay cheap to construct — the catalog builds hundreds of them per page load and never runs any of them.

### 4.2 Input Schema — declarative, with an escape hatch

Every tool takes different input: one takes a prompt, one takes a spreadsheet, one needs a bespoke form. The schema covers the common case declaratively:

```php
public function inputSchema(): array
{
    return [
        Field::text('hotel_name')->required()->max(120),
        Field::textarea('facilities')->required()->max(2000),
        Field::file('rate_sheet')->accept(['csv', 'xlsx'])->maxMb(5),
        Field::select('tone')->options(['formal', 'casual', 'luxury']),
        Field::select('language')->options(['fa', 'en', 'ar'])->default('en'),
    ];
}
```

One declaration, **four consumers**: the Livewire form renderer, web validation rules, REST API validation, and generated API docs. This is the entire justification for the registry — without it, the schema is just metadata nobody reads.

**The escape hatch matters as much as the schema.** `component()` lets a tool supply its own Livewire component when the generic renderer isn't enough. Without it, every slightly-unusual tool pressures the generic renderer into becoming a full form-builder framework. That road ends badly. The renderer stays simple *because* there is a way around it.

### 4.3 Tool Handler

The handler receives a typed input DTO — never a raw array — and decides for itself whether AI is involved at all.

```php
final class HotelDescriptionHandler
{
    public function __construct(private AiGateway $ai) {}

    public function handle(HotelInput $input, RunContext $ctx): ToolResult
    {
        $request = new TextGenerationRequest(
            systemPrompt: $this->systemPrompt($input->tone),
            userPrompt:   $this->buildPrompt($input),
            promptVersion: 3,
        );

        return new ToolResult($this->ai->run($request, $ctx)->text());
    }
}
```

**`AiGateway` is an optional dependency, not a mandatory pipeline.** A tool may solve its problem without any AI call. `RoomNormalizerHandler` tries deterministic rules first and only escalates to AI on a miss:

```php
final class RoomNormalizerHandler
{
    public function __construct(
        private RuleBasedClassifier $rules,
        private AiClassifier $ai,
    ) {}

    public function handle(RoomInput $input, RunContext $ctx): ToolResult
    {
        return $this->rules->classify($input->raw)
            ?? $this->ai->classify($input->raw, $ctx);
    }
}
```

Both classifiers implement `RoomClassifierInterface`. The rule path costs nothing and returns in microseconds; the AI path is the fallback. Since units are charged inside the gateway (§11), a rules-only run is free for the user *and* free for us — the incentive and the real cost point the same direction.

---

## 5. AI Gateway

`AiGateway` centralizes every concern shared by all tools and all capabilities:

- model resolution (tool policy + user tier + user choice)
- quota reservation, commit, and release
- result caching
- usage logging and cost accounting
- the daily cost circuit breaker

It has **one** execution method. That is the whole point of §6.

```php
final class AiGateway
{
    public function run(AiRequest $request, RunContext $ctx): AiResult
    {
        $model = $this->models->resolve($request, $ctx);
        $units = $this->pricing->unitsFor($request, $model);

        $this->breaker->assertWithinDailyBudget();

        $reservation = $this->quota->reserve($ctx->subject(), $units);
        $result = null;

        try {
            $key = $this->cacheKey($request, $model);

            if ($hit = $this->cache->get($key)) {
                $reservation->release();          // a cache hit costs the user nothing
                return $result = $hit;
            }

            $provider = $this->factory->for($request->capability(), $model);
            $result   = $request->executeOn($provider);   // polymorphic dispatch

            $this->cache->put($key, $result);
            $reservation->commit();

            return $result;
        } catch (\Throwable $e) {
            $reservation->release();              // refund on failure
            throw $e;
        } finally {
            $this->logs->record($ctx, $request, $model, $result);
        }
    }

    public function stream(StreamableRequest $request, RunContext $ctx): \Generator
    {
        // see §10.2 — same pipeline, buffered while streaming
    }
}
```

**Why no `complete()` method.** An earlier draft gave the gateway `complete()`, then would have needed `translate()`, `generateVideo()`, `transcribe()`, `describeImage()`… Every new modality would edit a central class — a direct violation of Open/Closed. The gateway must not know how many kinds of AI work exist.

**Cache key** — never just "input + model":

```
sha256( capability | model_key | prompt_version | normalized_payload )
```

`prompt_version` is the field people forget. Without it, editing a prompt in code silently keeps serving results generated by the previous one.

---

## 6. Capabilities and Requests

The extensibility of the whole AI layer rests here.

```php
enum Capability: string
{
    case TextGeneration  = 'text_generation';
    case Translation     = 'translation';
    case ImageGeneration = 'image_generation';
    case VideoGeneration = 'video_generation';
    case Transcription   = 'transcription';
    case Vision          = 'vision';
}
```

Each request knows its own capability *and* how to execute itself against a provider. The dispatch lives in the request (Command pattern), so the gateway never grows a `match` statement:

```php
abstract class AiRequest
{
    abstract public function capability(): Capability;
    abstract public function cachePayload(): string;
    abstract public function executeOn(AiProviderInterface $provider): AiResult;
}

final readonly class TranslationRequest extends AiRequest
{
    public function __construct(
        public string  $text,
        public string  $targetLanguage,
        public ?string $sourceLanguage = null,
        public ?string $formality = null,
    ) {}

    public function capability(): Capability
    {
        return Capability::Translation;
    }

    public function executeOn(AiProviderInterface $provider): AiResult
    {
        assert($provider instanceof Translator);
        return $provider->translate($this);
    }
}
```

Streaming is a separate marker, because only some requests and some models support it:

```php
interface StreamableRequest
{
    public function capability(): Capability;
}

final readonly class TextGenerationRequest extends AiRequest implements StreamableRequest
{
    public function __construct(
        public string  $userPrompt,
        public ?string $systemPrompt = null,
        public float   $temperature = 0.7,
        public ?int    $maxTokens = null,
        public int     $promptVersion = 1,
    ) {}
    // ...
}
```

**Adding Veo 3 video generation** requires: one enum case, one `VideoGenerationRequest`, one `GoogleProvider implements VideoGenerator`. `AiGateway`, the cache, the quota system, and logging are untouched. That is the test this design has to pass.

---

## 7. Provider Layer

There is no "free provider" and no "fake provider". A provider is one vendor, nothing more — whether its models are cheap or expensive is a property of **the model**, not the class.

Providers are segregated by capability so that no provider is ever forced to implement a method it cannot honour:

```php
interface AiProviderInterface
{
    public function name(): string;
}

interface TextGenerator extends AiProviderInterface
{
    public function generateText(TextGenerationRequest $r): TextResult;
    public function streamText(TextGenerationRequest $r): \Generator;
}

interface Translator extends AiProviderInterface
{
    public function translate(TranslationRequest $r): TranslationResult;
}

interface VideoGenerator extends AiProviderInterface
{
    public function submitVideo(VideoGenerationRequest $r): AiJobHandle;
    public function pollVideo(AiJobHandle $h): AiJobStatus;
}
```

| Provider | Implements |
|---|---|
| `OpenAiProvider` | `TextGenerator`, `Translator`, `Vision` |
| `AnthropicProvider` | `TextGenerator`, `Vision` |
| `GoogleProvider` | `TextGenerator`, `VideoGenerator` |
| `DeepLProvider` | `Translator` **only** |

DeepL is not a completion API — it takes `text + target_lang`, not a prompt. Forcing it behind a `complete(string $prompt)` method would mean writing a `DeepLProvider::complete()` that only throws. The interface would be lying about what the object can do.

The factory returns a correctly-typed provider, so "capability not supported" is a **type error at development time**, not an exception in production:

```php
final class ProviderFactory
{
    public function for(Capability $c, ResolvedModel $m): AiProviderInterface;
}
```

### 7.1 Error translation belongs in the provider

Each vendor reports failures differently — OpenAI uses `error.code`, others use HTTP status alone, others a custom envelope. **Each provider translates its own vendor errors** into the shared typed exceptions. Only the target exception type is common; the mapping never is. Centralizing this in the gateway would mean one `match` block that knows every vendor's error format — the exact coupling this layer exists to prevent.

### 7.2 Testing

No fake provider class ships in `app/`. Because **all** outbound traffic passes through `BaseHttpClient`, Laravel's `Http::fake()` covers every provider at once. Test doubles live in `tests/`, not in production code.

---

## 8. Model Registry

Models live in `config/ai.php` in v1 — version-controlled, zero queries, no admin panel required. The shape deliberately mirrors the future `ai_models` table so the migration is mechanical when an admin panel arrives.

```php
'providers' => [
    'openai'    => ['driver' => OpenAiProvider::class,    'key' => env('OPENAI_API_KEY')],
    'google'    => ['driver' => GoogleProvider::class,    'key' => env('GOOGLE_AI_KEY')],
    'anthropic' => ['driver' => AnthropicProvider::class, 'key' => env('ANTHROPIC_API_KEY')],
    'deepl'     => ['driver' => DeepLProvider::class,     'key' => env('DEEPL_KEY')],
],

'models' => [
    'gpt-mini' => [
        'provider'     => 'openai',
        'api_id'       => 'gpt-4o-mini',        // vendor-side identifier
        'capabilities' => ['text_generation', 'vision'],
        'tier'         => 'free',
        'execution'    => 'sync',
        'streaming'    => true,
        'cost'         => ['input' => 0.15, 'output' => 0.60],   // USD per 1M tokens
    ],

    'veo-3' => [
        'provider'     => 'google',
        'api_id'       => 'veo-3.0',
        'capabilities' => ['video_generation'],
        'tier'         => 'pro',
        'execution'    => 'async',
        'streaming'    => false,
    ],
],
```

Two details worth keeping:

- **`api_id` is separate from the internal key.** When a vendor retires a model, one config line changes instead of every tool that referenced it.
- **`tier` is where "free" lives.** This is what replaced the old `FreeProvider` idea.

Exact model identifiers change frequently — take them from each vendor's docs at implementation time, not from this document.

### 8.1 Model Policy

Some tools are locked to exactly one model; others let the user choose. Both are declared by the tool:

```php
// A video tool: only one model on earth can do this job
return ModelPolicy::fixed('veo-3');

// A text tool: tiered, user-selectable, with a fallback chain
return ModelPolicy::tiered(
    free: ['gemini-flash', 'gpt-mini'],
    pro:  ['claude-sonnet', 'gpt-full'],
    userSelectable: true,
);
```

Resolution order at runtime:

```
explicit user choice  →  only if userSelectable AND the model is in the user's tier list
        ↓ otherwise
first model in the user's tier list
        ↓ on failure / rate-limit / outage
next model in the chain
```

The fallback chain gives real resilience for free: one vendor going down degrades quality instead of taking the site offline.

> **Security:** the user's model choice must be validated server-side against both the tool's list and the user's tier. Trusting a submitted `model` parameter lets a free user post `model=veo-3` and spend our money.

---

## 9. Execution Models

Not every capability behaves like request/response. Three patterns exist, and the difference is structural:

| Pattern | Example | Mechanism |
|---|---|---|
| **Sync** | translation, short text | request → response |
| **Streaming** | text generation | `wire:stream` (web) / SSE (API) |
| **Async job** | video, image | queue + `ai_jobs` table + polling |

**v1 ships sync and streaming only.** Async jobs are not built — but the seam is left open: `AiJobHandle` exists as a return type and `execution => 'async'` exists in the model config. Adding the first async tool then becomes a feature, not a rewrite. Attempting all three in v1 is how v1 never ships.

---

## 10. Streaming

### 10.1 Is PHP a poor fit for streaming?

Partly, and it is worth being precise about which part.

**The real limitation.** PHP-FPM runs one worker per request. A 20-second AI stream occupies a worker for the full 20 seconds while consuming almost no CPU — it is blocked on I/O. With `pm.max_children = 30`, thirty concurrent streams saturate the server and ordinary page loads queue behind them. Node and Go avoid this with async I/O, where an idle connection is nearly free.

So the honest framing is not "PHP cannot stream" — it can — but "**each concurrent stream is expensive in PHP-FPM**".

**The overstated part.** Most reports of "PHP streaming doesn't work" are buffering misconfiguration, not a language limit. Required settings:

```nginx
fastcgi_buffering off;
proxy_buffering off;
add_header X-Accel-Buffering no;
fastcgi_read_timeout 300;
```
```ini
; php.ini
output_buffering = Off
max_execution_time = 300
```

Without these, nginx buffers the whole response and delivers it at once — the stream works, but the user sees nothing until the end. Cloudflare's 100-second proxy timeout is a separate trap.

**The scaling answer: FrankenPHP** (or Octane with Swoole/RoadRunner). FrankenPHP is a Go-based PHP server and an official Octane driver; because each request is a goroutine, long-lived connections are cheap. Critically this is a **deployment change, not a rewrite** — provided the code is Octane-safe from day one:

- never store request-scoped state in a singleton
- no mutable `static` properties

Both cost nothing to follow now and preserve a ten-fold capacity increase behind a Dockerfile change later.

**Decision:** ship on PHP-FPM with a generous worker count; move to FrankenPHP when concurrency justifies it. A separate Node/Go streaming service is explicitly rejected — a second language, a second deployment, and a network boundary to solve a problem this project does not yet have.

### 10.2 Streaming and caching together

Streaming and caching interact, and the interaction has to be decided rather than discovered:

```php
public function stream(StreamableRequest $request, RunContext $ctx): \Generator
{
    $model = $this->models->resolve($request, $ctx);
    $this->breaker->assertWithinDailyBudget();

    $reservation = $this->quota->reserve($ctx->subject(), $units);   // BEFORE the stream opens
    $key    = $this->cacheKey($request, $model);
    $buffer = '';
    $status = 'ok';

    try {
        if ($hit = $this->cache->get($key)) {
            $reservation->release();
            yield $hit->text();
            return;
        }

        ignore_user_abort(false);

        foreach ($this->factory->for(...)->streamText($request) as $chunk) {
            if (connection_aborted()) {
                $status = 'aborted';
                break;
            }
            $buffer .= $chunk;
            yield $chunk;
        }

        if ($status === 'ok') {
            $this->cache->put($key, new TextResult($buffer));
        }

        $reservation->commit();
    } catch (\Throwable $e) {
        $status = 'failed';
        $reservation->release();
        throw $e;
    } finally {
        $this->logs->record($ctx, $request, $model, $buffer, $status);
    }
}
```

Three rules that are easy to get wrong and expensive when you do:

1. **Reserve quota before the stream opens.** Once tokens are flowing there is no way to stop and charge.
2. **`ignore_user_abort(false)` + `connection_aborted()`.** Without them, a user closing the tab leaves the server buying tokens nobody will ever read.
3. **Log in `finally`, not after the loop.** Otherwise aborted and failed streams — the ones worth investigating — are the only ones that never get recorded.

---

## 11. Plans, Quota, and Cost Control

### 11.1 Units, not requests

Tools do not cost the same. Generating a hotel description with a large model is an order of magnitude more expensive than a text transform. Counting *requests* would price them identically.

Each tool declares a weight instead:

```php
public function costUnits(): int;   // TextTransformer: 1  |  HotelDescription: 5
```

Three subjects, one counter:

| Subject | Identified by | Period | Example allowance |
|---|---|---|---|
| guest | `hash(ip)` | daily | 10 units |
| free user | `user_id` | monthly | 100 units |
| pro user | `user_id` | monthly | 3000 units |

**Why units rather than a request count:** when the credit system arrives, nothing in this design changes — a way to *purchase* units is added, and that is all. Same table, same counter, same choke point. It turns the largest deferred feature into a payment-gateway integration.

Units are charged **inside the gateway, per AI call**. A tool that resolves via deterministic rules charges nothing, which matches what it actually cost us. Abuse of free paths is handled separately by a lightweight per-IP HTTP throttle.

### 11.2 Reservation must be atomic

Ten concurrent tabs will defeat a read-then-write quota check. One atomic statement, no locks, no transaction:

```sql
UPDATE usage_counters
   SET used = used + :units
 WHERE subject_type = :type
   AND subject_id   = :id
   AND period_key   = :period
   AND used + :units <= allowance
```

`affected_rows = 0` means the allowance is exhausted. `release()` reverses the increment when the provider call fails — the refund-on-failure requirement, needed in v1 rather than deferred with the credit system.

### 11.3 Cost circuit breaker

Platform-owned API keys plus a public endpoint is an open door to our own credit card. Three independent layers, all required in v1:

1. **Per-IP HTTP throttle** — `throttle:20,1` on tool routes. Weak against NAT and rotating VPNs, but it stops the trivial case.
2. **Daily platform spend cap** — a check inside `AiGateway` that sums today's `usage_logs.cost_usd` (cached, recomputed per minute) and refuses all requests past the ceiling. This is the layer that actually protects the account, and it is one `if` precisely because the gateway is the single choke point.
3. **Vendor-side spend limit** — configured in each provider's dashboard. Outside our code, and the last line of defence when the first two have a bug.

### 11.4 REST API access

The REST API is guarded by a static `X-Api-Key` header issued manually. This is not full authentication, but it takes the endpoint out of the public internet's reach at effectively zero complexity — and an unauthenticated endpoint spending platform keys is not shippable.

---

## 12. HTTP Client and Exceptions

HTTP is a generic concern, not an AI-specific one. A tool may one day call a weather or currency API. Transport stays independent and providers consume it.

```
app/Clients/
├── BaseHttpClient.php          # the only place raw HTTP happens
│                               # retry, timeout, headers, logging
└── Exceptions/
    ├── HttpClientException.php     # base
    ├── RateLimitException.php
    ├── TimeoutException.php
    ├── InvalidApiKeyException.php
    └── ServiceUnavailableException.php
```

Flow: **Provider → BaseHttpClient → vendor API.** Raw HTTP failures become typed exceptions here; vendor-specific error semantics become typed exceptions in the provider (§7.1). AI-level problems — a malformed response body, a content-policy refusal — live in `app/AI/Exceptions/`, separate from transport.

Retry is split deliberately: **transport retry** (connection reset, 5xx, backoff) belongs in `BaseHttpClient`; **semantic retry** (the model returned invalid JSON, so retry with a stricter prompt) belongs in the tool handler, because only the tool knows what "valid" means.

---

## 13. Database

Three things deliberately have **no table**: tools (they are code; the registry is the source of truth), models and providers (`config/ai.php`), and API keys (`.env`).

### 13.1 v1 migrations

**Laravel defaults** — `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs`.

**`plans`**
```php
$t->id();
$t->string('slug')->unique();               // 'guest' | 'free' | 'pro'
$t->string('name');
$t->unsignedInteger('units_per_period');
$t->enum('period', ['day', 'month']);
$t->json('allowed_tiers');                  // ['free'] | ['free','pro']
$t->unsignedInteger('price_toman')->default(0);
$t->boolean('is_active')->default(true);
$t->unsignedSmallInteger('sort_order')->default(0);
$t->timestamps();
```
> `guest` is a real row, not a hardcoded branch — anonymous visitors go through the same allowance logic as everyone else.

**`add_plan_to_users`** *(alters `users`)*
```php
$t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
$t->timestamp('plan_expires_at')->nullable();
```
> Plans are assigned manually in v1. Payment is deferred; the quota infrastructure is not.

**`usage_counters`** — quota state
```php
$t->id();
$t->string('subject_type', 16);             // 'ip' | 'user'
$t->string('subject_id', 64);               // ip hash or user id
$t->string('period_key', 10);               // '2026-08-15' or '2026-08'
$t->unsignedInteger('used')->default(0);
$t->unsignedInteger('allowance');
$t->timestamps();
$t->unique(['subject_type', 'subject_id', 'period_key']);
```

**`usage_logs`** — audit and cost
```php
$t->id();
$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
$t->string('ip_hash', 64)->index();         // hashed, never the raw address
$t->string('tool_slug')->index();           // no FK — tools live in code
$t->string('capability', 32);
$t->string('provider', 32);
$t->string('model', 64);
$t->unsignedInteger('input_tokens')->nullable();
$t->unsignedInteger('output_tokens')->nullable();
$t->decimal('cost_usd', 12, 6)->nullable();
$t->unsignedInteger('units_charged')->default(0);
$t->unsignedInteger('duration_ms')->nullable();
$t->boolean('was_cached')->default(false);
$t->string('status', 20);                   // ok | failed | rate_limited
                                            // quota_exceeded | aborted
$t->string('error_code', 64)->nullable();
$t->timestamps();
$t->index(['created_at', 'status']);        // feeds the daily circuit breaker
```
> `was_cached` later answers "how much did caching save us"; `status = aborted` captures streams the user walked away from.

**`ai_cached_results`** — *optional*
```php
$t->string('cache_key', 64)->primary();     // sha256(capability|model|version|payload)
$t->longText('payload');
$t->string('capability', 32);
$t->string('model', 64);
$t->unsignedInteger('hits')->default(0);
$t->timestamp('expires_at')->index();
$t->timestamps();
```
> Start with `Cache::remember()`. This table earns its place only when the cache must survive deploys or hit-rate statistics are wanted.

### 13.2 Deferred migrations

| Table | Built when |
|---|---|
| `ai_jobs` | the first async tool (video / image) |
| `tool_runs` | user history and shareable result pages (good SEO value) |
| `user_wallets`, `credit_transactions` | the credit system |
| `subscriptions` | a payment gateway exists — replaces `users.plan_id` |
| `ai_providers`, `ai_models` | an admin panel exists — until then, `config/ai.php` |

### 13.3 Repositories

Repository pattern **only where it earns its place**. `UsageLogRepository` exists because usage data will likely move to a different store as volume grows. Everything else uses Eloquent directly. The cache is not a repository — it is `AiResponseCache` in `app/AI/`, because a cache is not a database.

---

## 14. Frontend, Catalog, and SEO

### 14.1 Positioning

The reference point for the catalog UI is a directory site like Futurepedia — but the difference in nature drives a different homepage.

**A directory's asset is volume**: thousands of other people's tools. The visitor arrives, takes a link, and leaves.
**This platform's asset is function**: a handful of tools that actually do the work. The visitor arrives and stays.

So the category-grid homepage is *not* copied. A grid showing `(1)` beside each subcategory looks abandoned; that pattern only works at a thousand rows. **The homepage leads with a working tool** — a live input box, above the fold, no signup. Time-to-value is zero clicks, which is the only advantage available against a site with a thousand times more content. The category grid lives on `/tools`.

What *is* borrowed: the URL hierarchy, the card component, and the SEO discipline.

### 14.2 The registry powers the catalog

`ToolRegistry` is the single source of truth for `/tools`, category pages, `/tools/{slug}`, the navigation, `sitemap.xml`, and per-page JSON-LD. This is what makes the registry worth building rather than a `config/tools.php` array.

### 14.3 Behaviour and content are separate

```
app/Tools/Definitions/HotelDescriptionTool.php   → behaviour: slug, schema, models, cost
resources/content/tools/hotel-description.md     → content: SEO copy, use cases, FAQ
```

Marketing copy must not live inside a PHP class — otherwise fixing one sentence requires a deploy. The registry merges the two. This split is also what makes programmatic landing pages possible later.

SEO baseline: `sitemap.xml` generated from the registry, `SoftwareApplication` and `FAQPage` JSON-LD per tool page, unique meta per page, server-rendered throughout.

### 14.4 Livewire

- Stays inside Laravel/Blade — no build step, no SPA complexity
- Server-rendered, so SEO works by default
- `wire:stream` handles token-by-token output
- The REST API exists in parallel for future consumers, never as a dependency of the web UI

---

## 15. Folder Structure

```
app/
├── AI/                                  # AI infrastructure
│   ├── AiGateway.php
│   ├── Capability.php
│   ├── ProviderFactory.php
│   ├── ModelRegistry.php
│   ├── ModelPolicy.php
│   ├── AiResponseCache.php
│   ├── Contracts/
│   │   ├── AiProviderInterface.php
│   │   ├── TextGenerator.php
│   │   ├── Translator.php
│   │   └── VideoGenerator.php
│   ├── Requests/
│   │   ├── AiRequest.php
│   │   ├── StreamableRequest.php
│   │   ├── TextGenerationRequest.php
│   │   └── TranslationRequest.php
│   ├── Results/
│   │   ├── AiResult.php
│   │   ├── TextResult.php
│   │   └── AiJobHandle.php
│   ├── Providers/
│   │   ├── OpenAiProvider.php
│   │   ├── AnthropicProvider.php
│   │   ├── GoogleProvider.php
│   │   └── DeepLProvider.php
│   └── Exceptions/
│
├── Clients/                             # generic HTTP transport
│   ├── BaseHttpClient.php
│   └── Exceptions/
│
├── Usage/                               # quota + cost control
│   ├── QuotaGuard.php
│   ├── Reservation.php
│   ├── UnitPricing.php
│   ├── CostCircuitBreaker.php
│   └── RunContext.php
│
├── Tools/                               # tool definitions + registry
│   ├── Contracts/ToolInterface.php
│   ├── ToolRegistry.php
│   ├── Schema/Field.php
│   └── Definitions/
│       ├── TextTransformerTool.php
│       ├── HotelDescriptionTool.php
│       └── RoomNormalizerTool.php
│
├── Services/Tools/                      # BUSINESS LOGIC
│   ├── TextTransformer/
│   ├── HotelDescription/
│   │   ├── HotelDescriptionHandler.php
│   │   └── HotelInput.php
│   └── RoomNormalizer/
│       ├── RoomNormalizerHandler.php
│       ├── Contracts/RoomClassifierInterface.php
│       ├── RuleBasedClassifier.php
│       └── AiClassifier.php
│
├── Http/Controllers/Api/V1/
├── Livewire/
├── Models/
├── Repositories/UsageLogRepository.php
└── Providers/
    ├── AiServiceProvider.php            # providers, factory, model registry
    └── ToolServiceProvider.php          # tool discovery + registry

config/ai.php
resources/content/tools/*.md
routes/web.php · routes/api.php
```

Two placements worth noting:

- **`AiGateway` is in `app/AI/`, not `app/Services/`.** `app/Services/` means business logic — code that changes when the business changes. `app/AI/` and `app/Clients/` change when a *vendor* changes.
- **`RuleBasedClassifier` sits beside the handler that uses it**, not in a `Tools/Support/` folder. Rules about room names are domain knowledge, and `Support/` directories reliably become junk drawers.

---

## 16. Explicitly Out of Scope (v1)

**BYOK (bring your own key) — removed.** Accepting user API keys means encryption at rest, session handling, and real leak risk in logs, without proportional architectural value at this stage. All calls use platform-owned keys with server-side quotas.

**Payment gateway — deferred.** Plans and quotas ship; the `pro` plan is assigned manually. This keeps the full monetization structure testable without integrating a gateway.

**Async job capabilities (video, image) — deferred.** The seam is designed (§9); the queue infrastructure is not built.

---

## 17. Future Work

Each item is a real feature, listed so the architecture leaves room for it rather than pretending it does not exist.

### 17.1 Credit / wallet system

Free users get cheap models; heavier models require purchased credits.

Requires: `user_wallets` and `credit_transactions` with an auditable ledger, a payment gateway, and purchase/balance UI.

**Where it plugs in:** `AiGateway` is already the single choke point for every AI call, and the unit reservation mechanism (§11.2) already handles atomicity and refund-on-failure. Buying units is the only genuinely new part.

### 17.2 Other deferred items

- **Async capabilities** — video and image generation via queue + `ai_jobs`
- **Saved history** — `tool_runs`, plus shareable public result pages with SEO value
- **Admin panel** — moves model metadata from `config/ai.php` into `ai_models`
- **Additional tools** — one definition class and one handler each
- **Separate JS frontend** — the REST API already exists if Livewire is outgrown
- **Programmatic SEO** — generated landing pages per tool and per use case

---

## 18. Build Phases

Ordered so that something demonstrable exists early. Running out of momentum before anything is visible is the most common way a portfolio project dies.

| Phase | Work | Deliverable |
|---|---|---|
| 1 | `BaseHttpClient` + exception layer + `Capability` + capability interfaces + `AiRequest` + one real provider (sync **and** stream) + `ProviderFactory` + `config/ai.php` | AI core + transport |
| 2 | `AiGateway` + request/result DTOs + `AiResponseCache` + `usage_logs` + `UsageLogRepository` | Mediation layer |
| 3 | `ToolRegistry` + `Field` schema + generic form renderer + first tool (Text Transformer) + Livewire page with `wire:stream` | 🎯 **First working, demoable thing** |
| 4 | Auth (Breeze) + `plans` + `usage_counters` + reserve/commit + guest IP limits + cost circuit breaker | Business model |
| 5 | Catalog UI (grid, categories, tool pages) + content files + SEO (sitemap, JSON-LD, meta) | A real site |
| 6 | REST API v1 behind `X-Api-Key`, including SSE streaming | Second entry point |
| 7 | Hotel Description + Room Normalizer (with `RuleBasedClassifier` fallback chain) | 3 tools |
| 8 | Tests, README, deploy on FrankenPHP | Published |

**Phase 1 is foundational** — transport and the capability model are what everything else rests on. **Phase 3 is the morale checkpoint**: after it, there is a live URL to show.

The REST API stays in v1 but lands after the tools exist, because an API designed before its payloads are known ships as `v2` before launch.

---

## 19. Resume Mapping

- **AI Integration** — multi-provider, multi-capability abstraction with streaming, model fallback chains, and cost accounting
- **System Architecture** — capability-based extension model where new tools, providers, and modalities are all additive; dual entry points over one service layer
- **Product Engineering** — starts from a real problem (duplicate OTA content), with a usage-based business model designed in rather than bolted on
- **Operational Awareness** — quota reservation with refund-on-failure, spend circuit breakers, abort detection on streams, Octane-safe design
- **SEO** — server-rendered, registry-driven sitemap and structured data

**Next step:** Phase 1 — transport and the capability model.
