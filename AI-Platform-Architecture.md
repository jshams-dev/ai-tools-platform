# AI Tools Platform — Architecture Specification

**Version 5**
Laravel · Capability-Based AI Layer · Livewire UI + REST API · Streaming · Usage Quotas

> **Status:** Phases 1 and 2 are built and tested (116 tests). This revision folds
> the implementation back into the specification — every change below came from
> writing the code and finding that the v4 text did not survive contact with a
> compiler.
>
> | Corrected in v5 | Why |
> |---|---|
> | `ProviderFactory::for()` → one typed method per capability | The v4 signature returned the base interface, so the "type error at development time" claim was not true; and the `assert()` that backed it is compiled out in production |
> | Provider methods take `ResolvedModel` | The v4 signatures gave the provider no way to know *which* model to call |
> | `AiRequest` is `abstract readonly` | A readonly class cannot extend a non-readonly one — v4's request classes would not compile |
> | Cache is checked *before* quota is reserved (§5) | v4 reserved first and released on a hit: two writes for every cache hit, for nothing |
> | `$units` is computed in `stream()` (§10.2) | The v4 snippet used a variable it never assigned |
> | `usage_counters.allowance` (§13.1) | v4 denormalised it, so a mid-period upgrade kept the old allowance |
> | `Capability::Vision` → `VisionAnalysis` | The enum case and the interface name collided |
> | Spend guards move to Phase 3 (§18) | v4 shipped a public tool in Phase 3 but the cost circuit breaker in Phase 4 |
> | Sampling parameters are a model property (§8) | Newer reasoning models reject `temperature` with a 400 rather than ignoring it |

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

        $key = $request->cacheKey($model);
        $result = null;

        // Check the cache BEFORE reserving. A hit costs the user nothing, so
        // reserving first would mean two counter writes on every cache hit to
        // arrive back where we started.
        if ($hit = $this->cache->get($key)) {
            $this->logs->record($ctx, $request, $model, $result = $hit, cached: true);

            return $hit;
        }

        $reservation = $this->quota->reserve($ctx->subject(), $units);

        try {
            $result = $request->executeOn($this->factory, $model);   // polymorphic dispatch

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

Note that the gateway calls `executeOn($this->factory, $model)` and never names a capability. It has no `match` statement, no list of capabilities, and no way to find out how many exist. That is the property §6 is protecting.

**Cache and fallback interleave in one pass.** The sketch above resolves a single model; the implementation resolves a *chain* (§8.1) and walks it, consulting each model's cache immediately before that model would be called. Separating the two — scanning every cache first, then attempting each model — looks equivalent and is not: it serves a cached **fallback** answer while the preferred model is perfectly healthy, silently returning worse output. Checking each cache in preference order keeps both properties that matter:

- the first choice wins whenever it can answer;
- a fallback still uses its own cache, so a persistently-down primary does not mean re-buying the fallback on every call.

**Failures are logged per attempt, not per call.** A chain that tries three models writes three rows. Logging once per call would hide a vendor that is quietly failing over on every single request — the cost shows up on the invoice, and nowhere else.

**Fallback is for transport failures only.** A rate limit, an outage, or a timeout might not affect the next vendor. A malformed request or a content refusal will fail identically everywhere, so walking the chain only multiplies the latency of a certain failure — and shopping a refused prompt around other models is precisely the wrong behaviour to build in.

**Why no `complete()` method.** An earlier draft gave the gateway `complete()`, then would have needed `translate()`, `generateVideo()`, `transcribe()`, `describeImage()`… Every new modality would edit a central class — a direct violation of Open/Closed. The gateway must not know how many kinds of AI work exist.

**Cache key** — never just "input + model":

```
sha256( capability | model_key | prompt_version | normalized_payload )
```

`prompt_version` is the field people forget. Without it, editing a prompt in code silently keeps serving results generated by the previous one — and the bug presents as "my change did nothing".

The key is built by the request (`AiRequest::cacheKey()`), because only the request knows which of its fields change the output. The payload is `ksort`ed before hashing so that two identical calls cannot miss each other over key order.

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

    // 'VisionAnalysis', not 'Vision': the capability and the interface that
    // serves it need different names, or the enum case and the interface
    // collide on import.
    case VisionAnalysis  = 'vision_analysis';
}
```

Each request knows its own capability *and* how to execute itself against a provider. The dispatch lives in the request (Command pattern), so the gateway never grows a `match` statement:

```php
// `abstract readonly`, not `abstract`: PHP rejects a readonly class that
// extends a non-readonly one, so the base must be readonly for the concrete
// requests below to be.
abstract readonly class AiRequest
{
    abstract public function capability(): Capability;

    /** @return array<string, mixed> */
    abstract public function cachePayload(): array;

    abstract public function executeOn(ProviderFactory $factory, ResolvedModel $model): AiResult;
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

    public function executeOn(ProviderFactory $factory, ResolvedModel $model): AiResult
    {
        // Asks the factory for exactly the type it needs. No assert, and the
        // call below is checked statically.
        return $factory->translator($model)->translate($this, $model);
    }
}
```

**The request receives the factory, not a provider.** An earlier draft passed the resolved provider in and had the request `assert()` its way back to a usable type. Letting the request ask for what it needs is what makes the dispatch genuinely type-safe — and it keeps the gateway's job to *resolve a model*, never to work out which provider interface this kind of work requires.

Streaming is a separate marker, because only some requests and some models support it:

```php
interface StreamableRequest
{
    public function capability(): Capability;

    /** @return \Generator<int, string, mixed, AiResult> */
    public function streamOn(ProviderFactory $factory, ResolvedModel $model): \Generator;
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
interface AiProvider
{
    public function name(): string;
}

interface TextGenerator extends AiProvider
{
    public function generateText(TextGenerationRequest $r, ResolvedModel $m): TextResult;

    /** @return \Generator<int, string, mixed, TextResult> */
    public function streamText(TextGenerationRequest $r, ResolvedModel $m): \Generator;
}

interface Translator extends AiProvider
{
    public function translate(TranslationRequest $r, ResolvedModel $m): TranslationResult;
}

interface VideoGenerator extends AiProvider
{
    public function submitVideo(VideoGenerationRequest $r): AiJobHandle;
    public function pollVideo(AiJobHandle $h): AiJobStatus;
}
```

| Provider | Implements |
|---|---|
| `OpenAiProvider` | `TextGenerator`, `Translator`, `VisionAnalyzer` |
| `AnthropicProvider` | `TextGenerator`, `VisionAnalyzer` |
| `GoogleProvider` | `TextGenerator`, `VideoGenerator` |
| `DeepLProvider` | `Translator` **only** |

DeepL is not a completion API — it takes `text + target_lang`, not a prompt. Forcing it behind a `complete(string $prompt)` method would mean writing a `DeepLProvider::complete()` that only throws. The interface would be lying about what the object can do.

**Every method takes the `ResolvedModel` alongside the request.** The request describes *what* to do; it deliberately carries no model, because choosing one is the gateway's job (§8.1) and depends on the user's tier. The provider is handed the answer rather than looking it up — which is what keeps it a translator with no knowledge of tiers, policies, or which tool is calling.

**Streaming returns its result through the generator.** `streamText()` yields text fragments, and its `return` value — reachable via `getReturn()` once the generator is exhausted — is the assembled `TextResult`. Token usage only arrives in a stream's closing events, so without this the gateway would have nothing to log or bill for a streamed call.

The factory returns a correctly-typed provider, so "capability not supported" is a **type error at development time**, not an exception in production — but only if the signature actually says so:

```php
final class ProviderFactory
{
    public function textGenerator(ResolvedModel $m): TextGenerator;
    public function translator(ResolvedModel $m): Translator;
}
```

**Why not a single `for(Capability $c, ResolvedModel $m)`.** It can only declare `AiProvider` as its return type. Every caller would then have to `assert($provider instanceof Translator)` to get anything useful back, and static analysis would verify nothing. Worse, `assert()` is a false comfort: PHP compiles it out entirely under `zend.assertions=-1`, which is the production default — so the check that was supposed to make this safe does not exist where it matters.

One method per capability costs a central edit each time a capability is added. That is the one place this design accepts such a cost, and it buys real static verification at every one of the many call sites. An `assert()` cannot.

Two different mistakes are caught in two different places:

| Mistake | Caught by | When |
|---|---|---|
| Asking a text-only model to translate | `ResolvedModel::supports()` in the factory | Runtime — it is a config error |
| A vendor class missing the interface | `instanceof` in the typed method | Runtime — it is an unfinished implementation |
| Calling `translate()` on a `TextGenerator` | The type system | Development — it never runs |

### 7.1 Error translation belongs in the provider

Each vendor reports failures differently — OpenAI uses `error.code`, others use HTTP status alone, others a custom envelope. **Each provider translates its own vendor errors** into the shared typed exceptions. Only the target exception type is common; the mapping never is. Centralizing this in the gateway would mean one `match` block that knows every vendor's error format — the exact coupling this layer exists to prevent.

### 7.2 Testing

No fake provider class ships in `app/`. Because **all** outbound traffic passes through `BaseHttpClient`, Laravel's `Http::fake()` covers every provider at once. Test doubles live in `tests/`, not in production code.

This is the payoff for the transport rule, and it holds in practice: the phase 1 suite exercises the real providers, the real payload construction, and the real SSE parsing, replacing only the socket. Streaming included — a faked response body is read in chunks exactly as a live one is, so the streaming path is covered by ordinary tests rather than being the untested part of the system.

Worth testing deliberately, because each has a quiet and expensive failure mode:

| Case | What goes wrong if it is missed |
|---|---|
| A thinking block before the text block | Reading `content[0].text` returns empty; the model appears to have said nothing |
| `thinking_delta` during a stream | Reasoning is spliced into the user-visible answer |
| An `error` event mid-stream | A truncated answer looks like a complete short one |
| `stop_reason: refusal` | An empty result is stored as if it were valid |
| `stop_reason: max_tokens` | A tool parses truncated JSON and fails far from the cause |
| Usage from the closing stream events | Streamed calls are never billed and never counted against the spend cap |
| `prompt_version` in the cache key | Editing a prompt silently keeps serving the old one |

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

Three details worth keeping:

- **`api_id` is separate from the internal key.** When a vendor retires a model, one config line changes instead of every tool that referenced it.
- **`tier` is where "free" lives.** This is what replaced the old `FreeProvider` idea.
- **`sampling` is a per-model flag.** Newer reasoning models *reject* `temperature` and friends with a 400 rather than ignoring them, so a provider cannot send sampling parameters unconditionally. Capability is not the only thing that varies per model, and the config is where that variation belongs — not in a `match` inside a provider.

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
    $units = $this->pricing->unitsFor($request, $model);
    $this->breaker->assertWithinDailyBudget();

    $key    = $request->cacheKey($model);
    $buffer = '';
    $status = 'ok';

    // Same ordering as the synchronous path: a cache hit is free, so it is
    // served before any counter is touched.
    if ($hit = $this->cache->get($key)) {
        $this->logs->record($ctx, $request, $model, $hit, cached: true);
        yield $hit->text();

        return;
    }

    $reservation = $this->quota->reserve($ctx->subject(), $units);   // BEFORE the stream opens

    try {
        ignore_user_abort(false);

        $stream = $request->streamOn($this->factory, $model);

        foreach ($stream as $chunk) {
            if (connection_aborted()) {
                $status = 'aborted';
                break;
            }
            $buffer .= $chunk;
            yield $chunk;
        }

        if ($status === 'ok') {
            // The generator's return value carries the token usage that only
            // the closing events knew. Rebuilding a result from $buffer alone
            // would cache the text but lose what the call actually cost.
            $this->cache->put($key, $stream->getReturn());
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

**An aborted stream has no result.** The gateway's `stream()` returns the assembled result through the generator, or `null` when the caller disconnected partway. Inventing a result from the buffer would cache a truncation and serve it to everyone who asks next — so a partial stream is logged with `status = aborted` and deliberately not cached.

**Streaming cannot fall back once a fragment has left.** The chain is walked exactly as in §5, but only until the first `yield`. After that, switching models would re-send text the caller has already rendered, and bill us twice for it. Before the first fragment, a switch is invisible and free.

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
│                               # retry, timeout, headers, proxy/base-url
├── ServerSentEvents.php        # SSE framing — generic, not AI-specific
└── Exceptions/
    ├── HttpClientException.php     # base, with withMessage()
    ├── BadRequestException.php
    ├── RateLimitException.php      # carries Retry-After
    ├── TimeoutException.php
    ├── InvalidApiKeyException.php
    └── ServiceUnavailableException.php
```

**`base_url` and `proxy` are configurable per provider.** Egress can be re-pointed — at a relay, a regional endpoint, or a local mock — without editing a provider class. This has to exist from the first version: retrofitting it later means touching every provider at once.

**SSE framing lives here, not in a provider.** Socket chunks do not respect message boundaries: one event can arrive split across three reads, and one read can carry two and a half events. Every streaming provider would otherwise re-implement the same buffer and get the edge cases wrong in its own way. The *framing* is a transport concern and generic; what an event **means** — `content_block_delta` and the rest — is vendor-specific and stays in the provider.

**Enriching an error keeps its type.** `HttpClientException::withMessage()` returns the same class carrying a better message, so a provider can fold in the vendor's own explanation without the retry semantics or any upstream `catch` block changing. It rebuilds rather than clones, because PHP exceptions are not cloneable — and any subclass with extra state (`RateLimitException` and its `Retry-After`) must override it or that state is silently lost.

Flow: **Provider → BaseHttpClient → vendor API.** Raw HTTP failures become typed exceptions here; vendor-specific error semantics become typed exceptions in the provider (§7.1). AI-level problems — a malformed response body, a content-policy refusal — live in `app/AI/Exceptions/`, separate from transport.

The split is not academic. `ContentRefusedException` exists because a refusal arrives as **HTTP 200 with an empty answer**: nothing in the transport layer can see a problem, and without its own type it reads as a valid-but-empty result and a tool stores a blank record. The same applies to an `error` event that arrives *mid-stream*, after a 200 and after tokens have already been yielded — the consumer would otherwise receive a short answer indistinguishable from a genuinely brief one.

Retryability is a property of the exception (`isRetryable()`), not a list kept by the retry loop. A 400 will be rejected identically forever; retrying it three times only triples the latency of a guaranteed failure.

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
> **`allowance` is denormalised on purpose, and that has a consequence.** It is copied onto the row so the atomic `UPDATE` in §11.2 can compare against it without a join — which is what makes the whole quota check one statement. The cost is that a user upgrading mid-period keeps the allowance their row was created with. Whatever assigns a plan must therefore also update the current period's row; a plan change that only writes `users.plan_id` will appear to do nothing until the next period rolls over.

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
│   ├── Capability.php                   ✅
│   ├── ModelRegistry.php                ✅
│   ├── ResolvedModel.php                ✅
│   ├── ProviderFactory.php              ✅
│   ├── AiGateway.php                    ✅  run() + stream()
│   ├── ModelPolicy.php                  ✅
│   ├── ModelResolver.php                ✅  policy + tier + user choice
│   ├── AiResponseCache.php              ✅
│   ├── Contracts/
│   │   ├── AiProvider.php               ✅
│   │   ├── TextGenerator.php            ✅
│   │   ├── Translator.php               ✅
│   │   └── VideoGenerator.php           ← when the first async tool lands
│   ├── Requests/
│   │   ├── AiRequest.php                ✅
│   │   ├── StreamableRequest.php        ✅
│   │   ├── TextGenerationRequest.php    ✅
│   │   └── TranslationRequest.php       ✅
│   ├── Results/
│   │   ├── AiResult.php                 ✅
│   │   ├── TextResult.php               ✅
│   │   ├── TranslationResult.php        ✅
│   │   ├── TokenUsage.php               ✅
│   │   └── AiJobHandle.php              ← seam only
│   ├── Providers/
│   │   ├── AnthropicProvider.php        ✅  TextGenerator (sync + stream)
│   │   ├── DeepLProvider.php            ✅  Translator only
│   │   ├── OpenAiProvider.php
│   │   └── GoogleProvider.php
│   └── Exceptions/                      ✅
│
├── Clients/                             # generic HTTP transport
│   ├── BaseHttpClient.php               ✅
│   ├── ServerSentEvents.php             ✅
│   └── Exceptions/                      ✅
│
├── Usage/                               # quota + cost control
│   ├── RunContext.php                   ✅  caller, tier, policy
│   ├── UsageRecord.php                  ✅
│   ├── CostCircuitBreaker.php           ← phase 3
│   ├── QuotaGuard.php                   ← phase 4
│   ├── Reservation.php                  ← phase 4
│   └── UnitPricing.php                  ← phase 4
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
├── Repositories/                        ✅  interface + Eloquent impl
└── Providers/
    ├── AiServiceProvider.php            ✅  deferred; factory + model registry
    └── ToolServiceProvider.php          ← phase 3; tool discovery + registry

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
| 1 ✅ | `BaseHttpClient` + `ServerSentEvents` + exception layer + `Capability` + capability interfaces + `AiRequest` + `ModelRegistry` + two real providers (`Anthropic` sync **and** stream, `DeepL` translation-only) + `ProviderFactory` + `config/ai.php` | AI core + transport |
| 2 ✅ | `AiGateway` (sync + stream) + `AiResponseCache` + `ModelPolicy` + `ModelResolver` + `RunContext` + `usage_logs` + `UsageLogRepository` | Mediation layer |
| 3 | `ToolRegistry` + `Field` schema + generic form renderer + first tool (Text Transformer) + Livewire page with `wire:stream` + **`throttle` on tool routes + daily spend cap** | 🎯 **First working, demoable thing** |
| 4 | Auth (Breeze) + `plans` + `usage_counters` + reserve/commit + guest IP limits + per-user quotas | Business model |
| 5 | Catalog UI (grid, categories, tool pages) + content files + SEO (sitemap, JSON-LD, meta) | A real site |
| 6 | REST API v1 behind `X-Api-Key`, including SSE streaming | Second entry point |
| 7 | Hotel Description + Room Normalizer (with `RuleBasedClassifier` fallback chain) | 3 tools |
| 8 | Tests, README, deploy on FrankenPHP | Published |

**Phase 1 is foundational** — transport and the capability model are what everything else rests on. **Phase 3 is the morale checkpoint**: after it, there is a live URL to show.

**Two of the three spend guards moved from phase 4 into phase 3.** Phase 3 is the first phase that puts a working tool on a public URL, and it spends platform-owned API keys to do it. Shipping that with the cost circuit breaker still a phase away means an open endpoint pointed at our own credit card. The per-IP throttle and the daily spend cap are each a few lines — cheap precisely because the gateway is the single choke point — and they must land in the same phase as the first public tool. Per-user quotas can wait for phase 4, because until auth exists there are no users to meter.

**Phase 1 shipped two providers rather than one.** DeepL is roughly a hundred lines and implements `Translator` only, which is what turns the capability argument from a claim into something the compiler enforces. With a single text provider, nothing would have proved that a provider *cannot* be handed work it does not do.

The REST API stays in v1 but lands after the tools exist, because an API designed before its payloads are known ships as `v2` before launch.

---

## 19. Resume Mapping

- **AI Integration** — multi-provider, multi-capability abstraction with streaming, model fallback chains, and cost accounting
- **System Architecture** — capability-based extension model where new tools, providers, and modalities are all additive; dual entry points over one service layer
- **Product Engineering** — starts from a real problem (duplicate OTA content), with a usage-based business model designed in rather than bolted on
- **Operational Awareness** — quota reservation with refund-on-failure, spend circuit breakers, abort detection on streams, Octane-safe design
- **SEO** — server-rendered, registry-driven sitemap and structured data

**Next step:** Phase 3 — `ToolRegistry`, the `Field` schema, a generic form renderer,
the first tool, and a Livewire page with `wire:stream`. Plus the two spend guards that
moved into this phase, because it is the first one to put a public tool on platform keys.

Phases 1 and 2 are complete: transport, the capability model, two providers, the
gateway with caching, model fallback and usage logging — 116 tests covering them.
