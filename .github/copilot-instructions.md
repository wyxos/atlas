# Atlas - AI Agent Guidelines

## Project Overview
Atlas is a Laravel 12 media server application with a Vue 3 SPA frontend. It aggregates media from external services (CivitAI, Wallhaven, etc.) through a pluggable service architecture, with sophisticated moderation, reaction tracking, and browsing capabilities.

## Architecture

### Service-Based Browse System
- **Browse Services** (`app/Services/*Service.php`): Each extends `BaseService` and implements a specific external API
- **Browser** (`app/Browser.php`): Central orchestrator that routes requests to appropriate services
- **BrowsePersister** (`app/Services/BrowsePersister.php`): Handles database persistence for fetched items, creates containers, and filters results
- Services define `KEY`, `LABEL`, `SOURCE` constants and implement `containers()` and `decorateOriginalUrl()` methods
- All services use shared `BrowsePersister` via `$this->persists($transformedItems)` for consistent database operations

### Data Flow Pattern
1. Frontend requests browse items → `BrowseController@index`
2. `Browser::handle()` selects service based on request params
3. Service fetches external data, transforms to standard format
4. `BrowsePersister` upserts Files, FileMetadata, Containers in batch
5. Returns filtered files (excludes downloaded/blacklisted/auto-disliked)

### Moderation Architecture
- **Moderation Services** (`*ModerationService.php`): Extend `BaseModerationService` for different scopes (Browse, Container, File)
- **ModerationRule Model**: Stores user-defined rules (type, value, action_type)
- Rules auto-moderate during browse using `toDislike` and `moderatedOut` arrays in response

### Frontend Architecture
- **Vue 3 Composition API** with `<script setup lang="ts">` - never use Options API
- **Composables** (`resources/js/composables/`): Feature-based composition (useTabs, useBrowseService, useFileViewer*)
- **Hybrid Routing**: Blade views for auth pages (`/login`), Vue SPA for app (`/browse`, `/files`, `/users`)
- Vue mounts only when `#app` element is empty (see `resources/js/app.ts`)
- **TabPanel System**: Browse page uses tabs with independent states, filters, and services per tab

## Technology Stack
- **Backend**: Laravel 12, PHP 8.4, Pest 4, Playwright browser tests
- **Frontend**: Vue 3, TypeScript, Vite, Vitest, Tailwind CSS 4, Oruga UI, FontAwesome
- **Database**: SQLite (via Laravel conventions)
- **Dev Server**: Laravel Herd (auto-serves at `https://atlas.test`)
- **Package**: `wyxos/harmonie` for listing utilities (`ListingBase`)

## Development Workflows

### Running the Application
```bash
composer run dev  # Starts server, queue, and vite concurrently
```
Never run `php artisan serve` manually - use the composer script.

### Testing Workflow
1. Write/update tests after code changes
2. Run individual test: `php artisan test --filter=TestName`
3. Once feature verified, run full suite: `php artisan test`
4. Fix any broken tests before proceeding
5. Browser tests use Playwright (`pest-plugin-browser`)

### Frontend Changes Not Reflecting
Ask user to run `npm run build`, `npm run dev`, or `composer run dev`

### Seeder Credentials
- Email: `demo@atlas.test`
- Password: `password`
- Use these for browser testing

## Project-Specific Conventions

### PHP/Laravel
- **Always** use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) {}`
- **Always** explicit return types on methods
- **Always** curly braces for control structures (even single line)
- **Enum keys**: TitleCase (`FavoritePerson`, `Monthly`)
- Use `php artisan make:*` for file generation (pass `--no-interaction`)
- Prefer Eloquent relationships over raw queries

### Vue/TypeScript
- **Critical**: Avoid `watch()` - prefer computed setters, event handlers, or lifecycle hooks
- Use `watch()` only for complex reactive dependencies that cannot be handled otherwise
- Add `data-test` attributes to elements for easier Playwright testing
- Import icons from `lucide-vue-next` (already configured)
- Use `@/` alias for `resources/js/` imports

### Listing Pattern (wyxos/harmonie)
- Extend `ListingBase` for API list endpoints
- Implement `baseQuery()`, `filters()`, `perPage()`, `filterLabels()`
- See `FileListing` and `UserListing` for examples
- Listings auto-handle pagination, filtering, and metadata

### File Reactions & State
- Files track: `downloaded`, `previewed_at`, `seen_at`, `blacklisted_at`, `auto_disliked`
- Reactions stored in `reactions` table (polymorphic)
- Batch operations available: `batchShow`, `batchStore`, `batchIncrementPreview`

## Laravel Boost MCP Integration
This project uses Laravel Boost MCP server with specialized tools:

- **`search-docs`**: Use FIRST before making Laravel-related changes. Pass broad topic queries like `['rate limiting', 'routing']` - don't include package names
- **`tinker`**: Execute PHP for debugging/querying Eloquent models
- **`database-query`**: Read-only database access
- **`browser-logs`**: Read browser errors/logs (use recent logs only)
- **`get-absolute-url`**: Generate correct URLs for Herd environment
- **`list-artisan-commands`**: Check available Artisan parameters before running commands

## Common Patterns

### Creating a New Browse Service
1. Extend `BaseService`, define `KEY`, `LABEL`, `SOURCE` constants
2. Implement `fetch()` method returning transformed items
3. Transform items to standard format: `['file' => [...], 'metadata' => [...]]`
4. Use `$this->persists($transformedItems)` for database operations
5. Register in `Browser` class service map

### Adding a New SPA Page
1. Create Vue component in `resources/js/pages/`
2. Add route to `resources/js/routes.ts`
3. Use DashboardLayout or PublicLayout wrapper
4. Create corresponding Vitest test file (`PageName.test.ts`)

### Testing Browser Interactions
- Use Pest browser plugin (Playwright)
- See `pest-browser.md` for full documentation
- Screenshots saved to `tests/Browser/Screenshots/` (gitignored)
- Run with `php artisan test --filter=BrowserTest`

## Key Files Reference
- **Service Architecture**: `app/Services/BaseService.php`, `app/Browser.php`
- **Persistence Logic**: `app/Services/BrowsePersister.php`
- **Listing Pattern**: `app/Listings/FileListing.php`
- **Frontend Entry**: `resources/js/app.ts`
- **Tab System**: `resources/js/composables/useTabs.ts`
- **Browse Page**: `resources/js/pages/Browse.vue`
- **Routes**: `routes/web.php` (API + SPA catch-all)

## What NOT to Do
- Don't create documentation files unless explicitly requested
- Don't create verification scripts when tests exist
- Don't use `watch()` in Vue without strong justification
- Don't change dependencies or directory structure without approval
- Don't commit browser test screenshots
- Don't run separate `php artisan serve` - use `composer run dev`
