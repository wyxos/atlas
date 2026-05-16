# Atlas - Agent Guidelines

## Project Snapshot

**Repository Type**: Single Laravel application (not monorepo)  
**Primary Stack**: Laravel 12 (PHP 8.4.15) + Vue 3 (TypeScript) + Tailwind CSS v4  
**Architecture**: Traditional Laravel backend with Vue SPA frontend, served via Laravel Herd  
**Build System**: Vite (frontend), Composer (backend)  
**Testing**: Pest (PHP), Vitest (JS), Playwright (browser)

Sub-packages and major directories have their own detailed AGENTS.md files for specific patterns.

---

## Root Setup Commands

### Initial Setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm run build
```

### Development
```bash
# Start all services (Laravel server, queue worker, Vite)
composer run dev

# Or individually:
php artisan serve          # Laravel server
php artisan queue:listen    # Queue worker
npm run dev                 # Vite dev server
```

### Build & Typecheck
```bash
# Backend
npm run backend:lint        # Verify PHP formatting without changing files
npm run backend:lint:fix    # Apply Pint fixes intentionally
php artisan test            # Run Pest tests
composer lint:php:max-lines # Fail on new/regressed PHP files above 500 lines
composer lint:php:max-lines:report # Audit the remaining legacy >500-line baseline

# Frontend
npm run build               # Production build
npm run check               # Non-mutating full validation gate
npm run lint                # Non-mutating ESLint check
npm run lint:fix            # Apply ESLint fixes intentionally
npm run verify:vibe-dependency # Confirm the declared @wyxos/vibe range resolves on npm
npm run test                # Run Vitest tests
```

Database gotcha:
- For long URL fields on MySQL/MariaDB, do not rely on unique indexes directly on `text`/large `varchar` columns. Use a deterministic hash column (e.g. SHA-256) as the unique/upsert key.
- Do not call `Schema::hasColumn()`/`Schema::hasColumns()` in hot request/model paths. Those checks hit `information_schema` and can create recurring slow-query issues in production; rely on completed migrations instead.
- For new indexes on large `files` tables in MySQL/MariaDB, prefer online DDL (`ALTER TABLE ... ADD INDEX ... ALGORITHM=INPLACE, LOCK=NONE`) to reduce blocking during deploy.
- Some MySQL/MariaDB setups reject `ALGORITHM=INPLACE` (error 1846) for certain table definitions; in migrations, catch that and fallback to `ALGORITHM=COPY` instead of failing deploy.
- Hard rule: do not ship migrations that directly alter the `files` table during release. `npm run check` and `npm run release` now run `guard:files-table-migrations`, which blocks changed `database/migrations/*.php` files that touch `files`. Use queued/post-deploy operational commands for `files` table DDL work.
- For large `files` table backfills, prefer set-based SQL updates over PHP `chunkById` loops to avoid very long deploy-time migrations.
- For large tables (especially `files`), do not put row-by-row PHP loops or queued backfill jobs inside migrations. Keep migrations schema-first (fast), and run heavy backfills as separate post-deploy commands/jobs.
- `files` is large (million+ rows). Expect `ALTER TABLE`/dedupe migrations to run for a long time in production; start them once, monitor separately, and keep the deploy shell non-blocking while they finish.
- In moderation services, never do per-file `Reaction::exists()` checks. Batch current-user reaction lookups with a single `whereIn('file_id', ...)` query before iterating files.

Downloads gotcha:
- In queued download jobs, catching `Throwable` and writing `FAILED` prevents Laravel queue retries/backoff from running. For transient network errors (timeouts/5xx/connection issues), update transfer state to retry-visible metadata and call `$this->release($delay)` instead.
- Extension react/download ingests should default to random stored filenames when `filename` is not explicitly provided; do not derive stored filenames from URL/path slugs (can mirror page titles and produce unstable naming).
- Extension download auth cookies must be captured via extension cookie APIs (`chrome.cookies`), not `document.cookie`; send structured cookie metadata and host/path-filter on the server before applying `Cookie` headers or building yt-dlp cookie jars.
- YouTube extractor runs can intermittently fail with yt-dlp `n challenge`/`sig` solver errors (`found 0 n function possibilities`) and then report only storyboard/image formats; keep yt-dlp updated on servers and restart failed transfers after updating.

Services gotcha:
- Spotify tokens are encrypted at rest; if `APP_KEY` changes or legacy plaintext rows exist, token decryption can fail. Treat unreadable token rows as reconnect-required and clear them instead of returning a 500 from settings APIs.

---

## Universal Conventions

### Code Style
- **PHP**: Laravel Pint (`npm run backend:lint` verifies; `npm run backend:lint:fix` formats) → [see app/AGENTS.md for PHP conventions](app/AGENTS.md)
- **TypeScript/JavaScript**: ESLint + TypeScript strict mode (`npm run lint` verifies; `npm run lint:fix` formats) → [see resources/js/AGENTS.md for JS/TS conventions](resources/js/AGENTS.md)
- **Vue**: Composition API only, `<script setup>` syntax → [see resources/js/AGENTS.md for Vue conventions](resources/js/AGENTS.md)
- **CSS**: Tailwind CSS v4 (CSS-first config, no `tailwind.config.js`) → [see resources/js/AGENTS.md for Tailwind conventions](resources/js/AGENTS.md)
- **UI design system**: For component styling/layout/variants, follow [resources/js/components/ui/AGENTS.md](resources/js/components/ui/AGENTS.md) and prefer reusing primitives from `resources/js/components/ui/` before creating new UI components.

### Coding Style Principles

**1. Direct Consumption**
- Use backend API responses as-is. Avoid unnecessary mappings, transformations, or manual object construction when the backend already provides the data in the needed format.
- Example: `const { data } = await axios.get('/api/files'); files.value = data;` (not manual mapping)

**2. Backend Alignment**
- The backend should return data in the exact format the frontend needs. If the frontend needs specific field names or structure, update the backend rather than transforming in the frontend.
- Prefer changing `app/Http/Resources/FileResource.php` over mapping in frontend composables

**3. Minimal Code**
- No intermediate variables when direct assignment works
- Use object spread/assignment directly from API responses: `state.value = { ...state.value, ...response.data }`
- Avoid manual field-by-field object construction when object spread works
- Use destructuring when it simplifies code: `const { data } = await axios.get(...)`

**4. Simplicity Over Abstraction**
- Don't over-engineer. Write straightforward, readable code that does exactly what's needed - nothing more.
- Avoid creating abstractions "just in case" - add them when you have 3+ concrete use cases
- **Don't wrap single method calls** - If a library method exists, call it directly: `masonry.value?.restore(item)` not `useMasonryRestore(masonry, item)`
- **Trust TypeScript** - Don't add runtime `typeof` checks for methods TypeScript guarantees exist
- **Trust library contracts** - Don't duplicate validation the library already performs (duplicate checks, empty array checks, etc.)
- **Delete dead code immediately** - Remove empty functions, don't keep them "for future use" or "backward compatibility"

**5. No Unnecessary Mappings**
- If the backend returns `params`, use `params`. Don't map it to `queryParams` unless absolutely necessary.
- Align the backend to return what's needed instead of transforming in the frontend.

**6. Virtual Lists**
- For long fixed-height lists, reuse `resources/js/components/VirtualList.vue` instead of re-implementing scroll windowing math per page/component.

**7. Refactor Rubric**
- Prefer fixing the root cause over adding another wrapper, adapter, or fallback layer around the current behavior.
- Delete obsolete code and tests as soon as the underlying contract changes. Do not keep legacy branches, TODO handlers, or compatibility residue without a live caller.
- Small functions are only justified when they add a real boundary: bridging emits/props, owning a side effect, encapsulating non-trivial logic, or being reused meaningfully. Do not extract one-line pass-throughs only for naming.
- Long parameter lists are a design signal, not an automatic problem. First ask whether the callee is mixing responsibilities. Split the feature seam before introducing cosmetic grouping objects.
- If parameters still belong to one coherent feature, group them by domain (`services`, `results`, `lifecycle`) rather than as flat unrelated callbacks and refs.
- Prefer backend/service-layer ownership for domain shaping such as container URLs, labels, and browse metadata. The Vue side should consume that shape directly instead of rebuilding it.
- Be cautious with Laravel observers for behavior that must also hold across `upsert`, bulk insert, or queued backfills. Prefer explicit services/actions when those write paths exist.

### Code edit instructions

After you've finished editing
- Run format command if available
- Run lint command if available

### How to find problems

- Run tests
- Run lint
- Inspect changed files

### Rework Expectations (Implicit)
- If a feature or section is messy, fragile, or behaviorally wrong, treat any request to extend or fix it as implicit permission to refactor or rebuild it cleanly.
- Prefer deleting the problematic path and replacing it with a minimal, direct implementation that matches the requested behavior.
- Do not keep legacy logic just because it exists; keep only what is necessary and correct.

### Test Alignment & Auth Policy
- **See `tests/AGENTS.md`** for test alignment rules, auth expectations, and Vitest mocking guidance.

### Commit Format
- Use clear, descriptive commit messages
- No enforced conventional commits format, but clarity is expected
- Create a commit when the user asks for one or when preparing work for handoff/PR.
- Commits can take a while because hooks run lint/typecheck/tests; once `git commit` starts, let it run in the background and avoid frequent status polling.

### Branch Strategy
- Feature branches from `main`
- PR required before merge

---

## Security & Secrets

- **Never commit** `.env` files or tokens
- Secrets go in `.env` (gitignored)
- Use `config()` helper in PHP, never `env()` outside config files
- API keys and sensitive data: store in `.env`, reference via config files

---

## JIT Index (Directory Map)

### Backend (Laravel)
- **App Logic**: `app/` → [see app/AGENTS.md](app/AGENTS.md)
  - Controllers: `app/Http/Controllers/`
  - Models: `app/Models/`
  - Services: `app/Services/`
  - Jobs: `app/Jobs/`
  - Policies: `app/Policies/`
  - Form Requests: `app/Http/Requests/`

### Frontend (Vue)
- **Vue Application**: `resources/js/` → [see resources/js/AGENTS.md](resources/js/AGENTS.md)
  - Components: `resources/js/components/`
  - Pages: `resources/js/pages/`
  - Composables: `resources/js/composables/`
  - Routes: `resources/js/routes/`
  - Types: `resources/js/types/`
  - UI design system: `resources/js/components/ui/` → [see resources/js/components/ui/AGENTS.md](resources/js/components/ui/AGENTS.md)
- **Browser Extension Source**: `extension/` (Vite + Vue + TypeScript, popup entry: `extension/popup.html`)
  - Build + package command: `npm run package:extension` (creates `public/downloads/atlas-extension.zip` and a versioned archive; if `EXTENSION_LOCAL_EXTRACT_DIR` or legacy `EXTENSION_LOCAL_PACKAGE_DIR` is set, it also refreshes a local unpacked extension folder)
  - Version bump helpers: `npm run package:extension -- --bump=patch|minor|major` or set explicit version with `npm run package:extension -- --version=1.2.3`
  - Content script build gotcha: if `extension/vite.content.config.ts` builds a `.vue` import, keep `@vitejs/plugin-vue` enabled there, set `build.lib.cssFileName`, and include the emitted CSS file in `manifest.json` `content_scripts[].css`.
  - Extension automation gotcha: Chrome stable no longer reliably honors `--load-extension` for side-loaded unpacked extensions; use Brave/Chromium with an isolated `userDataDir` for automated extension testing so it does not collide with a live profile.
  - Codex extension automation workflow: use `npm run test:extension:automation` instead of `chrome://extensions` reload. It rebuilds `extension/dist`, copies the unpacked build to `%USERPROFILE%/Downloads/atlas-extension-automation` (override with `ATLAS_EXTENSION_AUTOMATION_DIR`), rewrites local `.playwright-mcp/config.json`, and restarts only the isolated Brave automation profile.

### Testing
- **Test Suite**: `tests/` → [see tests/AGENTS.md](tests/AGENTS.md)
  - Feature tests: `tests/Feature/`
  - Browser tests: `tests/Browser/`
  - Unit tests: `tests/Unit/`
  - JS tests: `resources/js/**/*.test.ts`
- Browser-media test gotcha: do not assume `HTMLMediaElement.play()` returns a promise; jsdom can return `undefined`, so guard the return before chaining `.catch()` in video/audio composables.

### Vibe Dependency Releases

- For Atlas work that bumps `@wyxos/vibe`, verify the Vibe release is visible on npm before deploying Atlas.
- Run `npm run verify:vibe-dependency` before an Atlas release that depends on Vibe. It confirms the tracked semver range resolves on npm and reports local linked-workspace or untracked lockfile state.
- Preserve the local `npm link @wyxos/vibe` workflow unless the user explicitly asks to change it; production depends on the semver range in `package.json`, not the workstation junction.

### Database
- Migrations: `database/migrations/`
- Factories: `database/factories/`
- Seeders: `database/seeders/`

### Configuration
- Laravel config: `config/`
- Routes: `routes/web.php`
- Bootstrap: `bootstrap/app.php` (Laravel 12 streamlined structure)

### Quick Find Commands
```bash
# Find PHP class
rg -n "class.*ClassName" app/

# Find Vue component
rg -n "export.*ComponentName" resources/js/components

# Find API route
rg -n "Route::(get|post|put|delete)" routes/

# Find composable
rg -n "export.*use[A-Z]" resources/js/composables

# Find test file
find tests -name "*Test.php" | rg "FeatureName"
find resources/js -name "*.test.ts"
```

---

## Definition of Done

Before creating a PR:

- [ ] PHP formatting verified with Pint: `npm run backend:lint`
- [ ] Non-mutating full validation passes: `npm run check`
- [ ] All relevant tests pass: `php artisan test` and `npm run test`
- [ ] Frontend builds successfully: `npm run build`
- [ ] No linter errors or warnings
- [ ] Fix commands (`npm run lint:fix`, `npm run backend:lint:fix`) were used only intentionally, with the resulting diff reviewed
- [ ] Code follows existing patterns (check sibling files)
- [ ] No unnecessary wrappers around single method calls
- [ ] No redundant type checks or validation (trust TypeScript and libraries)
- [ ] No dead code (empty functions, unused code)

## AI Agent Requirement

After any code changes, always run frontend linting and report the result:
- `npm run lint` (minimum requirement; non-mutating; do not skip)

Use `npm run lint:fix` only as an intentional edit step, then rerun `npm run lint` and inspect the diff.

---

## Laravel Herd

- Application is served by Laravel Herd
- URL format: `https://atlas.test` (kebab-case project directory)
- No manual HTTP server setup needed
- Use `php artisan route:list` to see all routes

## Windows + Herd Runtime
- Environment assumption: project and Herd commands run from Windows/PowerShell on this machine; WSL is not the active project runtime.
- Laravel Herd manages the primary PHP/Laravel services.
- Before PHP/Laravel tasks, verify runtime resolution (`Get-Command php`, `php -v`) from the current shell.
- Git hooks call `php` directly; if hook-time PHP fails, run the PHP checks explicitly from Windows and commit with `--no-verify` only after those checks pass.
- For DB/service operations, confirm the Windows-hosted Herd/database service before executing maintenance/debug commands.
- Do not route project/Herd work through WSL shims, WSL paths, or `wsl2-ubuntu` unless the user explicitly asks for that path.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.16
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/scout (SCOUT) - v10
- laravel/wayfinder (WAYFINDER) - v0
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- vue (VUE) - v3
- @laravel/vite-plugin-wayfinder (WAYFINDER) - v0
- eslint (ESLINT) - v9
- laravel-echo (ECHO) - v2
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

=== herd rules ===

## Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate URLs for the user to ensure valid URLs.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== wayfinder/core rules ===

## Laravel Wayfinder

Wayfinder generates TypeScript functions and types for Laravel controllers and routes which you can import into your client-side code. It provides type safety and automatic synchronization between backend routes and frontend code.

### Development Guidelines
- Always use the `search-docs` tool to check Wayfinder correct usage before implementing any features.
- Always prefer named imports for tree-shaking (e.g., `import { show } from '@/actions/...'`).
- Avoid default controller imports (prevents tree-shaking).
- Run `php artisan wayfinder:generate` after route changes if Vite plugin isn't installed.

### Feature Overview
- Form Support: Use `.form()` with `--with-form` flag for HTML form attributes — `<form {...store.form()}>` → `action="/posts" method="post"`.
- HTTP Methods: Call `.get()`, `.post()`, `.patch()`, `.put()`, `.delete()` for specific methods — `show.head(1)` → `{ url: "/posts/1", method: "head" }`.
- Invokable Controllers: Import and invoke directly as functions. For example, `import StorePost from '@/actions/.../StorePostController'; StorePost()`.
- Named Routes: Import from `@/routes/` for non-controller routes. For example, `import { show } from '@/routes/post'; show(1)` for route name `post.show`.
- Parameter Binding: Detects route keys (e.g., `{post:slug}`) and accepts matching object properties — `show("my-post")` or `show({ slug: "my-post" })`.
- Query Merging: Use `mergeQuery` to merge with `window.location.search`, set values to `null` to remove — `show(1, { mergeQuery: { page: 2, sort: null } })`.
- Query Parameters: Pass `{ query: {...} }` in options to append params — `show(1, { query: { page: 1 } })` → `"/posts/1?page=1"`.
- Route Objects: Functions return `{ url, method }` shaped objects — `show(1)` → `{ url: "/posts/1", method: "get" }`.
- URL Extraction: Use `.url()` to get URL string — `show.url(1)` → `"/posts/1"`.

### Example Usage

<code-snippet name="Wayfinder Basic Usage" lang="typescript">
    // Import controller methods (tree-shakable)...
    import { show, store, update } from '@/actions/App/Http/Controllers/PostController'

    // Get route object with URL and method...
    show(1) // { url: "/posts/1", method: "get" }

    // Get just the URL...
    show.url(1) // "/posts/1"

    // Use specific HTTP methods...
    show.get(1) // { url: "/posts/1", method: "get" }
    show.head(1) // { url: "/posts/1", method: "head" }

    // Import named routes...
    import { show as postShow } from '@/routes/post' // For route name 'post.show'
    postShow(1) // { url: "/posts/1", method: "get" }
</code-snippet>

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== pest/v4 rules ===

## Pest 4

- Pest 4 is a huge upgrade to Pest and offers: browser testing, smoke testing, visual regression testing, test sharding, and faster type coverage.
- Browser testing is incredibly powerful and useful for this project.
- Browser tests should live in `tests/Browser/`.
- Use the `search-docs` tool for detailed guidance on utilizing these features.

### Browser Testing
- You can use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories within Pest 4 browser tests, as well as `RefreshDatabase` (when needed) to ensure a clean state for each test.
- Interact with the page (click, type, scroll, select, submit, drag-and-drop, touch gestures, etc.) when appropriate to complete the test.
- If requested, test on multiple browsers (Chrome, Firefox, Safari).
- If requested, test on different devices and viewports (like iPhone 14 Pro, tablets, or custom breakpoints).
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging when appropriate.

### Example Tests

<code-snippet name="Pest Browser Test Example" lang="php">
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in'); // Visit on a real browser...

    $page->assertSee('Sign In')
        ->assertNoJavascriptErrors() // or ->assertNoConsoleLogs()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!')

    Notification::assertSent(ResetPassword::class);
});
</code-snippet>

<code-snippet name="Pest Smoke Testing Example" lang="php">
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavascriptErrors()->assertNoConsoleLogs();
</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |
</laravel-boost-guidelines>
