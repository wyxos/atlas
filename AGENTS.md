# Atlas v2 - Agent Guidelines

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

---

## Universal Conventions

### Code Style
- **PHP**: Laravel Pint (run `vendor/bin/pint --dirty` before commits) → [see app/AGENTS.md for PHP conventions](app/AGENTS.md)
- **TypeScript/JavaScript**: ESLint + TypeScript strict mode → [see resources/js/AGENTS.md for JS/TS conventions](resources/js/AGENTS.md)
- **Vue**: Composition API only, `<script setup>` syntax → [see resources/js/AGENTS.md for Vue conventions](resources/js/AGENTS.md)
- **CSS**: Tailwind CSS v4 (CSS-first config, no `tailwind.config.js`) → [see resources/js/AGENTS.md for Tailwind conventions](resources/js/AGENTS.md)

### Coding Style Principles

**1. Direct Consumption**
- Use backend API responses as-is. Avoid unnecessary mappings, transformations, or manual object construction when the backend already provides the data in the needed format.
- Example: `const { data } = await axios.get('/api/files'); files.value = data;` (not manual mapping)

**2. Backend Alignment**
- The backend should return data in the exact format the frontend needs. If the frontend needs specific field names or structure, update the backend rather than transforming in the frontend.
- Prefer changing `app/Http/Resources/FileResource.php` over mapping in `resources/js/composables/useFiles.ts`

**3. Minimal Code**
- No intermediate variables when direct assignment works
- Use object spread/assignment directly from API responses: `state.value = { ...state.value, ...response.data }`
- Avoid manual field-by-field object construction when object spread works
- Use destructuring when it simplifies code: `const { data } = await axios.get(...)`

**4. Simplicity Over Abstraction**
- Don't over-engineer. Write straightforward, readable code that does exactly what's needed - nothing more.
- Avoid creating abstractions "just in case" - add them when you have 3+ concrete use cases

**5. No Unnecessary Mappings**
- If the backend returns `params`, use `params`. Don't map it to `queryParams` unless absolutely necessary.
- Align the backend to return what's needed instead of transforming in the frontend.

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

---

## Laravel Herd

- Application is served by Laravel Herd
- URL format: `https://atlas-v2.test` (kebab-case project directory)
- No manual HTTP server setup needed
- Use `php artisan route:list` to see all routes
