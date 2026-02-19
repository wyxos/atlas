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
vendor/bin/pint --dirty     # Format PHP code
php artisan test            # Run Pest tests

# Frontend
npm run build               # Production build
npm run check               # TypeScript + ESLint check
npm run test                # Run Vitest tests
```

Extension gotcha:
- `npm run build:extension` starts with `build:extension:clean`, which deletes `extension/atlas-downloader/dist` before rebuilding. If the build fails early, restore tracked dist files with `git restore extension/atlas-downloader/dist` before continuing.
- Any change under `extension/atlas-downloader/` must also bump `extension/atlas-downloader/manifest.json` `version`, rebuild extension assets (`npm run build:extension`), and regenerate the zip (`php artisan atlas:extension-package --force`) before release.

Database gotcha:
- For long URL fields on MySQL/MariaDB, do not rely on unique indexes directly on `text`/large `varchar` columns. Use a deterministic hash column (e.g. SHA-256) as the unique/upsert key.
- For large `files` table backfills, prefer set-based SQL updates over PHP `chunkById` loops to avoid very long deploy-time migrations.
- `files` is large (million+ rows). Expect `ALTER TABLE`/dedupe migrations to run for a long time in production; start them once, monitor separately, and keep the deploy shell non-blocking while they finish.

---

## Universal Conventions

### Code Style
- **PHP**: Laravel Pint (run `vendor/bin/pint --dirty` before commits) → [see app/AGENTS.md for PHP conventions](app/AGENTS.md)
- **TypeScript/JavaScript**: ESLint + TypeScript strict mode → [see resources/js/AGENTS.md for JS/TS conventions](resources/js/AGENTS.md)
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

### Code edit instructions

After you've finished editing
- Use the jetbrains mcp (if available) to find any problems
- Run format command if available
- Run lint command if available

### How to find problems

- DO THIS FIRST: Check the jetbrains provided MCP server (one of intellij, pycharm, webstorm) using get_file_problems
    - Only provide a file path if you know where the problem is, but not what the problem is. If you don't know where the problem is:
        - Inspect code changes with git
        - Run tests
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

### Testing
- **Test Suite**: `tests/` → [see tests/AGENTS.md](tests/AGENTS.md)
  - Feature tests: `tests/Feature/`
  - Browser tests: `tests/Browser/`
  - Unit tests: `tests/Unit/`
  - JS tests: `resources/js/**/*.test.ts`

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

- [ ] PHP code formatted with Pint: `vendor/bin/pint --dirty`
- [ ] TypeScript/ESLint passes: `npm run check`
- [ ] All relevant tests pass: `php artisan test` and `npm run test`
- [ ] Frontend builds successfully: `npm run build`
- [ ] No linter errors or warnings
- [ ] Code follows existing patterns (check sibling files)
- [ ] No unnecessary wrappers around single method calls
- [ ] No redundant type checks or validation (trust TypeScript and libraries)
- [ ] No dead code (empty functions, unused code)

## AI Agent Requirement

After any code changes, always run frontend linting and report the result:
- `npm run lint` (minimum requirement; do not skip)

---

## Laravel Herd

- Application is served by Laravel Herd
- URL format: `https://atlas.test` (kebab-case project directory)
- No manual HTTP server setup needed
- Use `php artisan route:list` to see all routes

## WSL + Herd Runtime
- Environment assumption: commands run from WSL on a Windows host where Laravel Herd manages primary PHP/Laravel services.
- Before PHP/Laravel tasks, verify runtime resolution (`which php`, `php -v`).
- In this workspace, `php` may resolve to a WSL shim (`/home/wyxos/.local/bin/php`) that forwards to `cmd.exe`; if Windows interop is unavailable, PHP/Artisan/Pint/tests cannot run from this shell.
- If binaries/services are not available in WSL PATH, use Windows/Herd-aware invocation paths as needed.
- For DB/service operations, confirm whether runtime/services are Windows-hosted before executing maintenance/debug commands.
