# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Commands you’ll use often

- Install PHP deps
  - Windows/Herd: composer install
- Install JS deps
  - npm ci
- Serve app (Laravel Herd usually serves automatically; when manually serving):
  - php artisan serve --no-interaction
- Local dev loop (preferred over running npm run dev directly):
  - composer run dev
    - Runs: PHP server, queue listener, and Vite with auto-refresh
- Build frontend (Tailwind v4 + Vite):
  - npm run build
- Server-side rendering (SSR) dev:
  - composer run dev:ssr
- Run queues (if not using the dev script):
  - php artisan queue:listen --tries=1 --no-interaction
- Logs stream (Laravel Pail):
  - php artisan pail --timeout=0 --no-interaction
- Lint/format PHP (Laravel Pint):
  - vendor\bin\pint --dirty
  - To fix everything: vendor\bin\pint
- Run tests (Pest via artisan):
  - All: php artisan test --compact --parallel
  - Single file: php artisan test tests/Feature/SomeTest.php --compact --parallel
  - Filter by name: php artisan test --filter="test name substring" --compact --parallel
- Database migrations (only after tests pass):
  - php artisan migrate --graceful --no-interaction
- Horizon/Reverb (installed; start as needed):
  - Horizon dashboard: php artisan horizon
  - Reverb: php artisan reverb:start --no-interaction

Notes
- Do not run npm run dev. Use composer run dev which orchestrates PHP server, queue worker, and Vite.
- Always run tests before php artisan migrate.
- After creating a new test, run it.

## High-level architecture

Laravel 12 backend
- Streamlined structure (no app/Console/Kernel.php). Middlewares, exception handling, and route files are registered in bootstrap/app.php; providers in bootstrap/providers.php.
- Commands in app/Console/Commands auto-register (no manual registration).
- Key first-party packages in composer.json:
  - inertiajs/inertia-laravel (^2) for server-driven SPA
  - laravel/horizon for queue monitoring, laravel/reverb for realtime websockets
  - laravel/scout with typesense/typesense-php for search indexing
  - php-ffmpeg/php-ffmpeg for media processing
  - tightenco/ziggy for route generation on the frontend
  - wyxos/harmonie custom package used by the domain (review usage before changes)

Frontend (Inertia + Vue 3 + Vite + Tailwind v4)
- Vite config at vite.config.ts:
  - SSR entry: resources/js/ssr.ts
  - Client entry: resources/js/app.ts
  - Vue plugin enabled; alias @ => resources/js
  - Tailwind v4 integrated via @tailwindcss/vite
- Pages/components convention
  - Inertia pages live under resources/js/Pages (check actual tree before adding new pages)
  - Use Inertia::render(...) in routes to return pages
- Tailwind v4 specifics (from rules): use @import "tailwindcss" in CSS, not @tailwind directives; removed utilities have replacements

Runtime processes and dev orchestration
- composer run dev concurrently runs:
  - php artisan serve
  - php artisan queue:listen --tries=1
  - npm run dev (Vite) behind the scenes (do not invoke directly)
- composer run dev:ssr additionally builds SSR, starts logs (pail), and runs php artisan inertia:start-ssr
- Queue workers are expected during local dev for background jobs (media scanning/ffmpeg, indexing, etc.)

Testing
- Pest is the test runner (pestphp/pest, pest-plugin-laravel). Use php artisan make:test --pest to scaffold tests and match existing style.
- Parallel-friendly tests are expected. Use php artisan test --compact --parallel.
- Per guidelines: do not add RefreshDatabase in individual tests if globally configured; prefer factories and datasets; assert with specific helpers (assertForbidden, assertNotFound, etc.).

Conventions and important repo rules
- Use php artisan make:* to scaffold (models, controllers, requests, jobs, etc.).
- Always create the route when adding new functionality or endpoints.
- Prefer axios over fetch in frontend code.
- When editing UI, verify if backend alignments are required (and vice versa).
- PHP types over PHPDoc; keep comments minimal and useful.
- On enums, prefer kebab-case for values.
- Formatting: run vendor\bin\pint before finishing changes.
- Database: project uses MariaDB via Herd; avoid manual env leaks in commands; use config() accessors, not env() in code.

Tooling and docs that matter
- CLAUDE.md and .github/copilot-instructions.md share core guardrails that apply here:
  - Use Boost tools when available
  - Follow Laravel 12 streamlined file layout
  - Use Inertia v2 features (deferred props, polling, prefetching) appropriately
  - Tailwind v4 import style and replaced utilities
  - Prefer Eloquent relationships and eager loading; avoid DB:: when models suffice
- .junie/guidelines.md:
  - Do not run migrations unless php artisan test --compact --parallel passes
  - Use Inertia router or axios; remove debug logging before finishing
  - Tests should be placed in appropriate existing suites; create new files only when necessary

Where to look when extending
- Routes: routes/*.php (web/api/console). Use Inertia::render for page responses.
- Providers/bootstrapping: bootstrap/app.php and bootstrap/providers.php
- Frontend entries: resources/js/app.ts and resources/js/ssr.ts; pages under resources/js/Pages; shared components/utilities also under resources/js
- Queues/Jobs: app/Jobs and Horizon config (config/horizon.php) if present
- Search: check Scout configuration (config/scout.php) and where Typesense client is initialized
- Media: locate services using php-ffmpeg and ensure heavy tasks are queued

Caveats for Warp automation
- Never invoke npm run dev directly; prefer composer run dev.
- Before running schema changes (migrate), ensure the full test suite passes.
- Use php artisan to scaffold; keep to Laravel 12 patterns (no manual Kernel registration).
- Respect Windows pwsh paths and escaping in commands.

