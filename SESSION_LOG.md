# Session log

One entry per working session, newest first. Read the top entry before starting
work; add one before ending a session or clearing context. Each entry records
what was done, the decisions made and why, what is still open, and the next
step — the things a fresh session cannot recover from the code or git history.

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
