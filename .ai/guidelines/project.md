# AI Tools Platform — project guidelines

These are this project's own rules. Laravel Boost merges this file into `CLAUDE.md` and `AGENTS.md`; edit it here and run `php artisan boost:install --guidelines --no-interaction` to regenerate them. Never edit those two files by hand — Boost overwrites them. (`boost:update` refuses to run with this project's minimal `boost.json`.)

## Start of every session

1. Read the latest entry in `SESSION_LOG.md` — where work stopped, open issues, the next step.
2. The design is specified in `AI-Platform-Architecture.md`; the build phases and current phase are in §18.
3. Check the dev environment is up (below) before running the app or migrations.

## End of every session (or before a context clear)

1. Add an entry at the top of `SESSION_LOG.md`: what was done, decisions made and why, open issues, next step.
2. Add user-visible changes to `## [Unreleased]` in `CHANGELOG.md`.
3. Commit, so the next session starts from a clean tree.

## Working with the user

- **Reply in Persian, formatted for right-to-left reading.** Never put English words or code in the middle of a Persian sentence: mixed directions scramble the word order. English terms, file names and identifiers go on their own line or in a code block; if one must appear in a sentence, put it at the end in parentheses. Prefer the Persian word when one exists.
- **Build outside-in, in small steps.** Start from what the user sees (the chat UI), then the class that drives it, and extract smaller classes only when the parent actually gets crowded or a second user appears. Before each step say in 2–3 lines what and why; explain the reason before any refactor; one small commit per step; pause for questions. Stable foundations (transport, providers) may still be built bottom-up.
- **Verify visuals yourself before reporting.** Drive the app with headless Chrome (`C:/Program Files/Google/Chrome/Application/chrome.exe`) via `puppeteer-core` installed in a scratch folder — never in the project — at a phone viewport (390×844), in light and dark, and look at the screenshots.

## Dev environment (Windows)

- Database: XAMPP's MariaDB 10.4, started with `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`. `.env` uses `DB_CONNECTION=mariadb`, database `ai_tools_platform`.
- PHP: use `C:\php84` (8.4). XAMPP's PHP is 8.2 and too old, so XAMPP's Apache cannot serve this app.
- Serve for phone testing: `php artisan serve --host=0.0.0.0 --port=8000`, then `http://<wifi-ip>:8000`. Windows Firewall needs an inbound rule for TCP 8000 (admin).
- The built-in server handles one request at a time: while a reply streams, other requests wait.
- After any change to CSS, JS, or Tailwind classes in Blade: `npm run build`.

## Architecture rules that are easy to break

- **AI providers, models, and tools live in the database** (`ai_providers`, `ai_models`, `tools`, `tool_model`). A tool's *behaviour* — input fields and handler — is code in `app/Tools/Definitions`, joined to its row by `slug` and registered in `config/tools.php`.
- A provider row names a **driver key** from `config/ai.php` (`openai_compatible`, `anthropic`, `deepl`) — never a class name. Most vendors are `openai_compatible`, so a new one is a row plus a key.
- **API keys stay in `.env`**, mapped by provider slug under `ai.credentials`. Never put a key in the database, a seeder, a test, or a commit.
- Free models are for development; Anthropic models are `pro` tier and never reach guests.
- Every AI call goes through `AiGateway` (fallback chain, cache, usage log, daily spend cap). Tools never call providers directly.
- Streams yield answer text as `string` and model thinking as `ReasoningDelta`. Only strings are collected, cached, and billed; thinking is shown as live status and never stored.
- `wire:stream` inserts content as **HTML**: always `e()` every fragment.
- A generator sends its HTTP request on first read, not when created — wrap the iteration, not the call, to translate stream errors.
- Livewire actions arrive on Livewire's own endpoint, so route middleware (e.g. `throttle`) does not protect them; throttling lives in `ToolRunner`.
- Public methods on a Livewire component are callable from the browser. Helpers must be `private`.

## UI rules

- The UI is a chat, never a form. Settings sit in one row under the message box: model first, then the tool's fields in schema order (max two visible), then "⋯" for a sheet with every setting.
- Palette tokens in `resources/css/app.css`: `brand` (blue, leads), `sun` (yellow, warm accent), `bloom` (a little violet-pink). Colours are OKLCH; surfaces are solid with a faint brand tint (`surface` utility) — **no see-through glass**.
- Entry animations use fill-mode `backwards`, **never `both`**: a retained transform turns an element into a paint layer and containing block, which trapped the settings sheet and let replies cover message menus.
- Dark mode is the `.dark` class on `<html>`, applied before first paint; "system" is the default.
- Respect `prefers-reduced-motion`.

## Testing

- `php artisan test --compact`; seed AI data in tests with the `Tests\Concerns\SeedsAiCatalog` trait (it seeds per test — `$seed = true` only seeds once per run with in-memory SQLite).
- Fake vendors with `Http::fake()`; there is no fake provider class.
- To capture what a Livewire action streamed, use an `ob_start()` callback — Livewire calls `ob_flush()` per chunk, which bypasses a plain buffer.
