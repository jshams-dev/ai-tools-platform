# Session log

One entry per working session, newest first. Read the top entry before starting
work; add one before ending a session or clearing context. Each entry records
what was done, the decisions made and why, what is still open, and the next
step — the things a fresh session cannot recover from the code or git history.

---

## 2026-10-11 → 2026-10-20 — Phase 4 done: the API

**Branch:** `feature/auth-and-history`, merged into `develop`. 167 tests passing.

### Done

- Spec v6 (written 2026-10-10, not logged then): product definition, Next.js frontend over a Laravel JSON + SSE API, phases re-planned (§0, §2, §18).
- Sanctum SPA auth and the catalog endpoints (`/api/v1/tools`, `/api/v1/tools/{slug}`, `/api/v1/user`).
- `RunError`: the exception → message mapping moved out of `ToolWorkspace`, with a stable code and HTTP status, shared by the chat and the API.
- `POST /api/v1/tools/{slug}/runs` streams `thinking` / `text` / `done` / `error` events. Checked end to end with curl against Groq.
- Sign-up and reset-link requests limited to 10 an hour per IP.
- Fix: a guest allowance of 0 still let one run through (`tooManyAttempts()` is false when nothing is on record).

### Decisions (and why)

- **The run endpoint advances the generator to its first fragment before responding.** Validation, allowance, throttle and the fallback chain then fail with a real status (422 / 401 / 429 / 503) instead of a 200 with an error event; the cost is that headers wait for the first token.
- **Stream data is JSON-encoded plain text, not escaped HTML.** The Next.js client renders it as text; `e()` stays a Livewire concern.
- **Validation errors are keyed by field name** (`text`, `mode`), not `input.text`, so the client maps them straight onto its fields.
- **Sign-up / reset throttles redefine Fortify's POST routes in `routes/web.php`** — Fortify has no limiter setting for them, and patching routes at boot would not survive `route:cache`.

### Open

- `user/confirm-password` route still has no view (unused while two-factor is off).
- Dependabot opened a branch on GitHub bumping `shivammathur/setup-php` in the CI workflow; not reviewed.
- The test account `test-user@example.test` / `a-long-password` is still in the local database.

### Next step

Phase 5: the Next.js app in `frontend/` — start outside-in with the tool page's chat calling the run endpoint, then layout, theme and auth pages.

---

## 2026-10-10 → 2026-10-11 — Phase 4 begins: accounts and the guest allowance

**Branch:** `feature/auth-and-history` (from `develop`, after merging and pushing phase 3). 151 tests passing. Not yet pushed.

### Done

- Fortify: sign up, sign in, sign out, password reset, with our own Blade pages (`resources/views/auth`, `components/layouts/auth`). Two-factor, passkeys, and profile updates disabled until the profile page exists.
- Guests get 3 free runs a day, metered in `ToolRunner` by IP hash; then a sign-in card in the chat. The unsent message is kept in the session and restored after sign-in (`url.intended` points back to the tool).
- Signed-in users run under `RunContext::forUser` (free tier).
- Theme switch uses a View Transition instead of transitioning every element.
- `league/commonmark` 2.10.1 → 2.10.3 (security).

### Decisions (and why)

- **Guests may try, then sign in** — replaces "no guests". The user did not want visitors to land on a bare login page; modelled on ChatGPT, Copilot, Perplexity. Spec §18.1 updated.
- **Fortify, not hand-rolled auth** — user's choice; we write only the pages.
- **Guest allowance in `ToolRunner`, not the component** — so the future REST API inherits it.

### Open

- Signup and forgot-password POSTs have no throttle yet (only login does); spec §18.1 says they should.
- `user/confirm-password` route exists without a view (unused while two-factor is off).
- A test account exists in the local database: `test-user@example.test` / `a-long-password`.

### Next step

Free plan on signup (`plans`), then saved chat history (`conversations`, `conversation_messages`) in the sidebar.

---

## 2026-10-08 → 2026-10-10 — Phase 3: chat workspace, database-driven AI, free models

**Branch:** `feature/text-tools-chat-ui` (from `develop`; old `claude/ai-architecture-status-kzbf70` deleted locally and remotely). 141 tests passing. Not yet merged or pushed.

### Done

- Laravel Boost and Livewire 4 installed.
- Daily spend cap in `AiGateway` (checked after the cache, so cached answers stay free).
- Tool layer: `ToolInterface`, `Field`, `ToolRegistry`, `ToolRunner`, Text Transformer.
- Chat-style workspace (`app/Livewire/ToolWorkspace.php`) with streaming, starter chips, settings row + "⋯" sheet, message menu (copy / edit), failed-reply recovery.
- Providers, models, tools moved to the database; `AiCatalog` feeds `ModelRegistry` and `ProviderFactory` the same array shapes they always took.
- `OpenAiCompatibleProvider` + seven free models (Groq, Gemini, OpenRouter); keys in `.env`.
- Live model thinking (`ReasoningDelta`, `LeadingThoughtSplitter` for Gemini's `<thought>` blocks).
- Visual system: OKLCH palette (`brand` blue, `sun` yellow, `bloom` violet), solid tinted `surface`, animations, light/dark/system theme.
- `.ai/guidelines/project.md`, `CHANGELOG.md`, this log.

### Decisions (and why)

- **UI is a chat, never a form** — user requirement. The `Field` schema still drives validation; the UI maps the primary field to the message box and the rest to pills.
- **Providers/models/tools in the database**, overriding the spec's config-file approach — the user wants everything manageable from a future admin panel (a CMS/CRM-like back office). Tool behaviour stays in code because a row cannot execute anything.
- **Driver keys, not class names, in `ai_providers.driver`** — a class name in a table is one admin form away from instantiating anything.
- **Keys stay in `.env`** for now; encrypted DB storage is a later option.
- **Free models for development; Anthropic is `pro` only** — user asked not to spend on Anthropic while testing.
- **Throttle lives in `ToolRunner`, not route middleware** — Livewire actions bypass page-route middleware.
- **No see-through glass** — user rejected it (text showed through menus). Solid surfaces with a faint tint instead, after Bootstrap 6 alpha's OKLCH / color-mix / light-dark() approach (Bootstrap 6 itself has no glass).
- **Phase 4 requires login (no guests)** and **saved chat history per user** — recorded in the spec, §18.1.

### Lessons (bugs worth not repeating)

- PHP's 30 s limit killed long streams mid-reply → `set_time_limit` in `respond()` plus a stream idle timeout.
- Animations with fill-mode `both` keep a transform → element becomes a paint layer and a containing block; this trapped the settings sheet and let a reply cover a message's menu (Edit "did nothing"). Use `backwards`.
- `navigator.clipboard` is undefined on plain-HTTP origins; `copyText()` falls back to `execCommand`.
- Streamed errors must be translated around the *iteration* of a generator, not its creation.
- A Blade view variable named `$component` collides with Blade's own inside a component.
- In-memory SQLite + `RefreshDatabase` seeds once per run with `$seed = true`; seed per test instead.

### Outside the repository (this machine)

- `C:\php84\php.ini`: `curl.cainfo` and `openssl.cafile` now point to `C:\php84\extras\ssl\cacert.pem` (from curl.se). Without it every HTTPS call from PHP failed. Backup: `C:\php84\php.ini.bak-2026-10-09`.
- `.env`: added `DB_CONNECTION=mariadb` and the Groq / Gemini / OpenRouter keys.
- The firewall rule for port 8000 needs to be added by the user as administrator (command in the guidelines).

### Open

- Not yet merged into `develop` or pushed.
- Gemini 3.5 Flash is slow (~4–9 s) and OpenRouter's free pool is often rate-limited; the fallback chain covers it.
- Profile and Settings items in the profile menu are placeholders ("Soon").

### Next step

**Phase 4** (spec §18.1), outside-in: login required (no guests) with a free plan on signup, then saved chat history per user (`conversations`, `conversation_messages`) shown in the sidebar.
