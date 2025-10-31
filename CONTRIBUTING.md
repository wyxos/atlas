# Contributing to ATLAS

Thanks for your interest in contributing to ATLAS! We welcome contributions of all kinds, from bug reports and documentation improvements to new features and code enhancements.

This guide covers everything you need to know to get started as a contributor.

## Table of Contents

- [Ways to Contribute](#ways-to-contribute)
- [Prerequisites](#prerequisites)
- [Development Setup](#development-setup)
  - [Quick Start (Docker)](#quick-start-docker)
  - [Manual Setup](#manual-setup)
- [Development Workflow](#development-workflow)
- [Testing](#testing)
- [Code Style](#code-style)
- [Pull Requests](#pull-requests)
- [Issues](#issues)

## Ways to Contribute

- **Report Bugs**: Found something broken? [Open an issue](https://github.com/wyxos/atlas/issues) with clear reproduction steps
- **Request Features**: Have an idea? [Start a discussion](https://github.com/wyxos/atlas/issues) describing the problem first, then your proposed solution
- **Improve Documentation**: README, code comments, examples, and guides
- **Write Tests**: Increase coverage for existing features
- **Fix Bugs**: Check [good first issue](https://github.com/wyxos/atlas/labels/good%20first%20issue) and [help wanted](https://github.com/wyxos/atlas/labels/help%20wanted) labels
- **Implement Features**: Tackle items from the roadmap or propose new ones

## Prerequisites

### Required
- **PHP 8.2+** with extensions: `mbstring`, `xml`, `json`, `gd`, `pdo_sqlite` (or `pdo_mysql`/`pdo_pgsql`)
- **Composer** 2.x
- **Node.js 22.x** and npm
- **Database**: SQLite (default), MariaDB/MySQL 8.0+, or PostgreSQL 13+

### Optional (for full feature testing)
- **Redis 7+**: For queue, cache, and session management
- **Typesense 0.25+**: For full-text search functionality
- **Docker & Docker Compose**: For containerized development

## Development Setup

### Quick Start (Docker)

The fastest way to get started for development:

```bash
# Clone the repository
git clone https://github.com/wyxos/atlas.git
cd atlas

# Build and start all services
docker compose up -d --build
```

Services will be available at:
- **Web UI**: http://localhost:8080
- **Health Check**: http://localhost:8080/up (should return 200)
- **Reverb (WebSockets)**: ws://localhost:8081
- **Horizon (Queue Dashboard)**: http://localhost:8080/horizon

The Docker setup includes:
- `app`: PHP-FPM application container
- `web`: Nginx web server
- `db`: MariaDB 11 database
- `redis`: Redis for queues/cache/sessions
- `typesense`: Typesense search engine
- `horizon`: Laravel Horizon queue worker
- `reverb`: Laravel Reverb WebSocket server

**Reset Everything:**
```bash
docker compose down -v --remove-orphans
docker compose up -d --build
```

### Manual Setup

For local development without Docker:

**1. Clone and Install Dependencies**

```bash
git clone https://github.com/wyxos/atlas.git
cd atlas

# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install
```

**2. Configure Environment**

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Create SQLite database (if using SQLite)
touch database/database.sqlite
```

Edit `.env` for your local setup:

```env
APP_NAME=ATLAS
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database (SQLite default)
DB_CONNECTION=sqlite

# Or MariaDB/MySQL
# DB_CONNECTION=mariadb
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=atlas
# DB_USERNAME=atlas
# DB_PASSWORD=secret

# Queue (database default, or Redis)
QUEUE_CONNECTION=database
# QUEUE_CONNECTION=redis

# Cache
CACHE_STORE=database
# CACHE_STORE=redis

# Search (optional)
# SCOUT_DRIVER=typesense
# TYPESENSE_HOST=localhost
# TYPESENSE_PORT=8108
# TYPESENSE_API_KEY=your-api-key

# Reverb (real-time)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=atlas
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

**3. Run Migrations**

```bash
php artisan migrate
```

**4. Start Development Servers**

Option A - All-in-one (recommended):
```bash
composer run dev
```

This starts:
- PHP development server (port 8000)
- Queue worker
- Vite dev server (HMR)

Option B - Individual terminals:
```bash
# Terminal 1: Application server
php artisan serve

# Terminal 2: Queue worker
php artisan queue:listen --tries=1

# Terminal 3: Asset bundler
npm run dev

# Terminal 4 (optional): Reverb server
php artisan reverb:start
```

**5. Access the Application**

- Application: http://localhost:8000
- Register first user (becomes admin)
- Configure storage paths in settings

## Development Workflow

### Available Scripts

| Task | Command | Description |
|------|---------|-------------|
| **Start Development** | `composer run dev` | Starts app server, queue worker, and Vite (HMR) |
| **Start with SSR** | `composer run dev:ssr` | Build SSR bundle, then start app, queue, logs, and SSR server |
| **Run Tests** | `composer run test` | Run full test suite (alias for `php artisan test`) |
| **Run Specific Test** | `php artisan test --filter TestName` | Run a single test or test file |
| **Lint PHP** | `vendor/bin/pint` | Format PHP code (Laravel Pint) |
| **Lint JavaScript** | `npm run lint` | Lint and fix JS/Vue files (ESLint) |
| **Format JavaScript** | `npm run format` | Format JS/Vue files (Prettier) |
| **Check Formatting** | `npm run format:check` | Check JS/Vue formatting without changes |
| **Build Assets** | `npm run build` | Production build of frontend assets |
| **Build SSR** | `npm run build:ssr` | Build client + SSR bundles |
| **Run JS Tests** | `npm test` | Run Vitest unit tests |
| **Watch JS Tests** | `npm run test:watch` | Run Vitest in watch mode |
| **Coverage** | `npm run coverage` | Generate test coverage report |

### Daily Development

1. **Pull Latest Changes**
   ```bash
   git pull origin main
   composer install
   npm install
   php artisan migrate
   ```

2. **Create Feature Branch**
   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/bug-description
   ```

3. **Make Changes**
   - Write code
   - Add/update tests
   - Update documentation

4. **Test Your Changes**
   ```bash
   # Run linters
   vendor/bin/pint
   npm run format
   npm run lint
   
   # Run tests
   composer run test
   npm test
   ```

5. **Commit and Push**
   ```bash
   git add .
   git commit -m "feat: add feature description"
   git push origin feature/your-feature-name
   ```

## Testing

### Running Tests

**PHP Tests (Pest)**
```bash
# Run all tests
composer run test
# or
php artisan test

# Run specific test file
php artisan test tests/Feature/AudioTest.php

# Run with filter
php artisan test --filter "test_user_can_stream_audio"

# Run in parallel
php artisan test --parallel
```

**JavaScript Tests (Vitest)**
```bash
# Run once
npm test

# Watch mode
npm run test:watch

# With coverage
npm run coverage
```

**Browser Tests (Pest Browser Plugin)**
```bash
# These tests use Playwright
php artisan test tests/Feature/Browser/
```

### Writing Tests

- **Feature Tests**: Test user-facing flows (authentication, file uploads, API endpoints)
- **Unit Tests**: Test isolated logic (services, helpers, models)
- **Browser Tests**: Test full UI interactions with Playwright

**Example Feature Test:**
```php
test('user can stream audio file', function () {
    $user = User::factory()->create();
    $file = File::factory()->audio()->create();
    
    $this->actingAs($user)
        ->get(route('audio.stream', $file))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');
});
```

## Code Style

### PHP
- Follow Laravel conventions and PSR-12
- Use **type hints** and **return types** for all methods
- Use **strict types**: `declare(strict_types=1);`
- Format with Laravel Pint: `vendor/bin/pint`
- Write descriptive variable and method names
- Keep methods short and focused (single responsibility)

### JavaScript/TypeScript
- Use **Vue 3 Composition API** (not Options API)
- Follow **ESLint** rules (run `npm run lint`)
- Format with **Prettier** (run `npm run format`)
- Use **TypeScript** where beneficial
- Prefer `const` over `let`, avoid `var`

### Vue Components
```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

interface Props {
  title: string
  items: Array<{ id: number; name: string }>
}

const props = defineProps<Props>()
const selectedId = ref<number | null>(null)

const selectedItem = computed(() => 
  props.items.find(item => item.id === selectedId.value)
)
</script>

<template>
  <div class="component">
    <h2>{{ title }}</h2>
    <!-- template content -->
  </div>
</template>
```

### Database
- Use **migrations** for all schema changes
- Use **factories** for test data
- Use **seeders** sparingly (mainly for local dev)
- Write **descriptive migration names**: `2024_01_01_000000_add_spotify_tokens_table.php`

### Commits

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add playlist sharing feature
fix: resolve audio streaming for large files
docs: update installation instructions
refactor: simplify file metadata extraction
test: add tests for photo reactions
chore: upgrade Laravel to 12.1
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

## Pull Requests

### Before Submitting

1. **Fork and Branch**
   - Fork the repository
   - Create a topic branch from `main`: `git checkout -b feat/your-feature`

2. **Keep PRs Focused**
   - One feature/fix per PR
   - Small, reviewable changes (< 400 lines when possible)
   - Split large changes into multiple PRs

3. **Quality Checklist**
   - [ ] Code follows style guidelines
   - [ ] `vendor/bin/pint` passes (PHP formatting)
   - [ ] `npm run format && npm run lint` passes (JS formatting)
   - [ ] `composer run test` passes (all PHP tests)
   - [ ] `npm test` passes (all JS tests)
   - [ ] New features have tests
   - [ ] Documentation is updated (if needed)
   - [ ] No console errors or warnings

### PR Template

```markdown
## Description
[Clear description of what this PR does]

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Why?
[Why is this change needed? What problem does it solve?]

## How?
[How does this change address the problem?]

## Testing
[How was this tested? Steps to verify?]

## Screenshots (if UI change)
[Add screenshots or video]

## Checklist
- [ ] Tests pass
- [ ] Linters pass
- [ ] Documentation updated
- [ ] No breaking changes (or documented)
```

### Review Process

- Maintainers will review within 1-3 days
- Address feedback in new commits (don't force-push)
- Once approved, maintainers will squash and merge

## Issues

### Bug Reports

When reporting bugs, include:

1. **Clear Title**: "Audio streaming fails for files > 50MB"
2. **Environment**:
   - OS (Windows/macOS/Linux)
   - PHP version
   - Browser (if frontend issue)
   - Docker or manual setup
3. **Steps to Reproduce**:
   1. Go to audio page
   2. Click play on large file
   3. See error in console
4. **Expected vs Actual**: What should happen vs what happens
5. **Logs/Screenshots**: Any relevant error messages or screenshots

### Feature Requests

1. **Describe the Problem**: What problem does this solve?
2. **Proposed Solution**: How would you solve it?
3. **Alternatives**: Any alternative approaches you considered?
4. **Use Case**: Real-world scenario where this helps

### Labels

- `bug`: Something isn't working
- `enhancement`: New feature or request
- `good first issue`: Good for newcomers
- `help wanted`: Extra attention needed
- `documentation`: Docs improvements
- `question`: Further information requested

## Troubleshooting

### Common Issues

**"Class not found" errors**
```bash
composer dump-autoload
```

**Database connection failed**
- Check `.env` credentials
- Ensure database is running
- For SQLite: `touch database/database.sqlite`

**Vite not starting**
```bash
rm -rf node_modules package-lock.json
npm install
```

**Queue jobs not processing**
- Ensure queue worker is running: `php artisan queue:work`
- Check `failed_jobs` table for errors

**Typesense connection failed**
- Verify `SCOUT_DRIVER=typesense` in `.env`
- Check Typesense is running on configured port
- Verify API key matches

**Docker issues**
```bash
# Full reset
docker compose down -v --remove-orphans
docker system prune -a
docker compose up -d --build
```

### Platform-Specific Notes

**Windows**
- Use Git Bash or WSL2 for best experience
- File permissions aren't an issue
- Use `127.0.0.1` instead of `localhost` if connection issues

**macOS**
- May need to install PHP extensions via Homebrew
- Docker Desktop required for Docker development

**Linux**
- Ensure user has permissions for `storage/` and `bootstrap/cache/`
- May need `php-fpm` and specific extensions

## Additional Resources

- [README.md](README.md) - Project overview and installation for users
- [Laravel Documentation](https://laravel.com/docs) - Framework documentation
- [Inertia.js Documentation](https://inertiajs.com/) - Frontend framework
- [Pest Documentation](https://pestphp.com/) - Testing framework
- [Vue 3 Documentation](https://vuejs.org/) - Frontend library

## Getting Help

- [GitHub Issues](https://github.com/wyxos/atlas/issues) - Bug reports and feature requests
- [GitHub Discussions](https://github.com/wyxos/atlas/discussions) - Questions and community help

## Security

If you discover a security vulnerability, please do not open a public issue. Email **security@wyxos.com** or contact the maintainer privately.

---

Thank you for contributing to ATLAS! 🎉
