# Changelog

All notable changes to this project are recorded here, newest first.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Entries describe what changed for someone using or running the platform; the
reasoning behind them lives in `SESSION_LOG.md` and the commit messages.

## [Unreleased]

Branch `feature/auth-and-history` (merged into `develop`) — Phase 4 of the build plan.

### Added

- **Accounts**: sign up, sign in, sign out, and password reset by email (Laravel Fortify, with our own pages). Sign-in attempts are limited to five a minute per email and IP; sign-ups and reset-link requests to ten an hour per IP. The sidebar shows the signed-in user, or "Sign in" and "Create a free account" to a visitor.
- **Guests can try tools**: three free messages a day (`TOOLS_GUEST_RUNS_PER_DAY`), counted under the message box. After that a sign-in card appears in the chat, and the unsent message is back in the message box after signing in. A signed-in user's runs are logged under their account.

- **JSON API for the coming Next.js frontend** (Laravel Sanctum, session-cookie sign-in on the same domain): `GET /api/v1/tools`, `GET /api/v1/tools/{slug}` (fields, starters, and the models the caller's tier may use — never prices or vendor ids), and `GET /api/v1/user`. Fortify's sign-in, sign-up and sign-out answer JSON to API clients.
- **Run a tool through the API**: `POST /api/v1/tools/{slug}/runs` streams the answer as Server-Sent Events — `thinking`, `text`, then `done` (with the guest's remaining free runs) or `error`. A run that cannot start answers with a JSON error and a real status: 422 invalid input, 401 free runs used up, 429 too fast (with `Retry-After`), 503 no model available. Guests, the throttle, and the daily spend cap work exactly as in the chat.

### Fixed

- Setting `TOOLS_GUEST_RUNS_PER_DAY` to 0 still let a guest run a tool once.
- Switching between light and dark is smooth instead of juddering.
- The empty chat no longer cuts off its top when its content is taller than the screen.

### Security

- `league/commonmark` updated to 2.10.3 (two advisories, one high).

Branch `feature/text-tools-chat-ui` (merged into `develop`) — Phase 3 of the build plan.

### Added

- **Chat workspace for tools** — a chat-style page instead of a form. A tool's main field is the message box; its other fields are pills under it; replies stream in word by word. Starter suggestions on an empty conversation.
- **Text Transformer**, the first tool: rewrite, summarize, fix grammar, simplify, expand, or bullet-point a text, with tone and output-language settings.
- **Tool layer**: `ToolInterface`, the `Field` schema, `ToolRegistry`, and `ToolRunner`, which validates and throttles every run the same way from any entry point.
- **Providers, models, and tools in the database** (`ai_providers`, `ai_models`, `tools`, `tool_model`). A tool can use any number of models, with a default and a fallback order.
- **`OpenAiCompatibleProvider`** — one driver for every vendor that speaks the OpenAI format. Seven free models seeded: Groq (Qwen 3.8, GPT-OSS 120B and 20B), Google Gemini (3.5 Flash, 3.5 Flash-Lite), OpenRouter (Nemotron 3 Super, Gemma 4).
- **Daily platform spend cap** (`AI_DAILY_SPEND_CAP_USD`); cached answers are still served after the cap is hit.
- **Live model thinking**: the "Thinking…" status shows what the model is thinking, when the model shares it. Thinking is never stored or billed as the answer.
- **Model picker with search** and a provider filter in the settings sheet.
- **Light / dark / system theme** switch in the profile menu; follows the OS by default.
- **Message menu**: tap a sent message to copy it or edit and resend it.
- Settings row under the message box (model, then the tool's top settings, then "⋯" for a sheet with every setting).
- Laravel Boost and Livewire 4.
- Project guidelines for AI agents (`.ai/guidelines/project.md`), this changelog, and `SESSION_LOG.md`.

### Changed

- Visual design: solid surfaces faintly tinted with the brand colour, a blue / yellow / violet palette in OKLCH, and entry, hover, and thinking animations. Motion is disabled when the OS asks for reduced motion.
- Anthropic models are now `pro` tier only.
- A streamed reply may run longer than PHP's 30-second limit; a stream that goes silent for `AI_STREAM_IDLE_TIMEOUT` seconds fails cleanly instead of hanging.

### Fixed

- A reply that took over 30 seconds killed the request and reloaded the page, losing the conversation. Failed replies are now marked failed in place.
- Copy did nothing on plain-HTTP addresses (the clipboard API needs HTTPS).
- Settings pills overlapped when labels were long.
- On a tool's start screen the settings sheet was trapped inside the message box; and Edit on a sent message did nothing, because the next reply was painted over its menu.
- Streamed vendor errors lost the vendor's explanation (both Anthropic and OpenAI-compatible drivers).
- Every streamed fragment is HTML-escaped before it reaches the page.

## Phase 2 — 2026-10-07

### Added

- `AiGateway` with sync and streaming paths, response cache, model policy and resolver, fallback chain, `RunContext`, and `usage_logs` with a repository.

## Phase 1 — 2026-09-08

### Added

- AI core and transport: `BaseHttpClient`, Server-Sent Events parsing, typed exceptions, capability model, `AiRequest`, `ModelRegistry`, `ProviderFactory`, Anthropic (sync and stream) and DeepL providers.

## Architecture specification — 2026-08-15

- Versions 3 and 4 of `AI-Platform-Architecture.md`.
