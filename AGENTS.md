<laravel-boost-guidelines>
=== .ai/project rules ===

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

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
