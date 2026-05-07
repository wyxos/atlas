# Atlas - Agent Guidelines

## Project Snapshot

**Repository Type**: Single Laravel application (not monorepo)  
**Primary Stack**: Laravel 12 (PHP 8.4.15) + Vue 3 (TypeScript) + Tailwind CSS v4  
**Architecture**: Traditional Laravel backend with Vue SPA frontend, served via Laravel Herd inside stanalone docker container.
**Build System**: Vite (frontend), Composer (backend), docker engine (publish)  
**Testing**: Pest (PHP), Vitest (JS), Playwright (browser)

# Docker Standalone Setup

```
./docker-setup.sh          # One-time setup & start
docker-compose up -d       # Start services
docker-compose down        # Stop services
docker-compose logs -f     # View logs
```

### Docker Management Stack
- **Application**: http://localhost:8080
- **Database**: MariaDB (atlas-mariadb)
- **Caching/Queue**: Redis (atlas-redis)
- **Search**: Typesense (atlas-typesense)
- **WebSockets**: Laravel Reverb (atlas-reverb)
- **Queue Monitor**: Laravel Horizon (atlas-horizon)
- **DB Admin**: phpMyAdmin (http://localhost:8081)

### User & Password Management (Docker)
- **Default Admin**: demo@atlas.test / password
- **Create Admin**: `docker exec -it atlas-php php artisan app:setup`
- **Reset Password**: `docker exec -it atlas-php php artisan tinker`
- **List Users**: `docker exec atlas-php php artisan tinker --execute="echo \App\Models\User::pluck('email')->toJson();"`

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

## Laravel Herd

- Application is served by Laravel Herd
- URL format: `https://atlas.test` (kebab-case project directory)
- No manual HTTP server setup needed
- Use `php artisan route:list` to see all routes
