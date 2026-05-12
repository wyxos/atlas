# Docker Deployment Hardening

## TL;DR

> **Quick Summary**: Harden the Atlas Docker deployment for production-readiness by adding Opcache, health checks, X-Accel-Redirect media serving, multi-stage build, resource limits, PHP-FPM/Nginx tuning, and security hardening — all while preserving existing media-serving behavior and non-Docker development compatibility.
>
> **Deliverables**: Opcache-enabled Docker image, health-checked containers, X-Accel media serving with PHP fallback, production-safe .env defaults, tuned PHP-FPM/Nginx configs, hardened container security
>
> **Estimated Effort**: Medium
> **Parallel Execution**: YES — 3 waves
> **Critical Path**: Task 1 → Task 5 → Task 11 (media QA)

---

## Context

### Original Request
Review the Docker deployment for stability, robustness, and best practices for containerizing a media browser app with heavy image/video file loading and search. Propose improvements, refactors, and stability enhancements.

### Interview Summary
**Key Discussions**:
- Opcache is entirely missing — critical performance gap
- Media files served through PHP `response()->stream()` ties up FPM workers, risking pool exhaustion under concurrent downloads
- No application health checks on php/horizon/reverb containers
- `.env.docker` uses `APP_DEBUG=true`, `APP_ENV=local` — inappropriate for standalone containerized app
- Single-stage Dockerfile includes build tools (node, npm, composer) in runtime image
- No resource limits set — Horizon can theoretically consume 5.7GB
- No custom PHP-FPM pool config (defaults to 5 children)
- No Nginx performance tuning (sendfile, open_file_cache, gzip)
- `SESSION_DRIVER=database` instead of available Redis

**Research Findings**:
- `FileStorageResponseService::serveDiskPath()` manually implements byte-range streaming — ideal candidate for X-Accel-Redirect offload to Nginx
- `ATLAS_STORAGE` resolves to `storage/app/atlas` and media lands in `atlas-app` disk (`.app/` subdirectory)
- Horizon configures 5 supervisors, max 23 workers in production mode
- Scout connects to Typesense with 2s timeout, 3 retries
- All media routes are behind `auth` middleware — authorization is required
- Laravel 12 has built-in `/up` health route via `->withRouting(health: '/up')`

### Metis Review
**Identified Gaps** (addressed):
- **X-Accel auth bypass risk**: Preserved by keeping `internal;` directive + existing auth middleware
- **Behavior regression**: Preserve byte-range, HEAD, Content-Type, Content-Disposition, 206/416 behavior
- **Non-Docker fallback**: `FileStorageResponseService` detects nginx reverse-proxy and falls back to PHP streaming
- **Read-only/security breakage**: All writable paths enumerated before enabling security hardening
- **Health check restart loops**: Liveness-only health checks (no dependency checks)
- **Scheduler duplication**: Dedicated singleton scheduler container
- **Nginx alias trailing-slash**: Explicit edge case test in QA scenarios
- **Gzip on media**: Exclude image/video MIME types from `gzip_types`
- **Mac/Windows bind-mount permissions**: Entrypoint `chown` already handles this

---

## Work Objectives

### Core Objective
Harden the Atlas Docker deployment so it is safe, performant, and stable for standalone containerized operation — with Opcache, health checks, efficient media serving, resource limits, and secure container defaults.

### Concrete Deliverables
- `docker/php/Dockerfile` — multi-stage build with Opcache, non-root user
- `docker/php/opcache.ini` — tuned Opcache configuration
- `docker/php/zz-docker.conf` — PHP-FPM pool tuning
- `docker-compose.yml` — health checks, resource limits, scheduler container, security options
- `docker/nginx/default.conf` — X-Accel media location, caching headers, performance tuning
- `docker/php/entrypoint.sh` — simplified to runtime-only concerns
- `.env.docker` — production-safe defaults (`APP_DEBUG=false`, `APP_ENV=production`, `SESSION_DRIVER=redis`)
- `bootstrap/app.php` — `/up` health route (already present, verify)
- `app/Services/FileStorageResponseService.php` — X-Accel-Redirect support with non-Docker fallback
- `scripts/test-docker.sh` — comprehensive Docker integration test suite (12 sections, ~50 checks)

### Definition of Done
- [ ] `docker compose build --no-cache` succeeds
- [ ] `docker compose up -d` starts all 8 services healthy
- [ ] `curl -fsS http://localhost:8080/up` returns `200`
- [ ] `curl -fsS http://localhost:8080/api/browse?feed=local` returns `200` (Typesense available)
- [ ] Authenticated media download via Nginx X-Accel returns correct byte range
- [ ] Direct access to `/_media/` returns `404` (internal-only)
- [ ] `docker compose exec php php -m | grep -i opcache` shows `Zend OPcache`
- [ ] `docker compose exec php php artisan tinker --execute="echo config('app.debug')?'debug':'no-debug';"` outputs `no-debug`
- [ ] `scripts/test-docker.sh` passes all 12 test sections from a clean Docker deploy
- [ ] Nginx `X-Forwarded-Port` is set so Laravel generates URLs with correct port

### Must Have
- Opcache installed and enabled in Docker image
- All app containers have working health checks
- `APP_DEBUG=false` in Docker environment
- Media files served via Nginx X-Accel (not PHP streaming) when behind nginx
- Resource limits on all services
- PHP-FPM pool tuned for media workload
- Docker image does not contain Node/npm/runtime build tools

### Must NOT Have (Guardrails)
- MUST NOT change storage paths, volume names, or media directory layout
- MUST NOT expose media files through Nginx public locations
- MUST NOT break non-Docker (Herd) development media serving
- MUST NOT add CDN, S3, TLS, monitoring, Kubernetes, or transcoding infrastructure
- MUST NOT add Secrets to committed env files
- MUST NOT change application code beyond `FileStorageResponseService` and health route
- MUST NOT use `immutable` caching headers on user media or API responses
- MUST NOT apply `read_only: true` until all writable paths are enumerated per service
- MUST NOT run scheduler in multiple containers simultaneously

### Known Issues (Found During Task 11 Diagnosis)

- **Nginx X-Forwarded-Port missing**: `docker/nginx/default.conf` sets `HTTP_X_FORWARDED_HOST`, `HTTP_X_FORWARDED_PROTO`, and `HTTP_X_FORWARDED_FOR` in the FastCGI params, but does NOT set `HTTP_X_FORWARDED_PORT`. Since the app is served on port 8080 (mapped from Nginx's port 80), Laravel generates redirect URLs and asset URLs pointing to `http://localhost/` (port 80) instead of `http://localhost:8080/`. This causes broken redirects after login and incorrect asset URLs in the SPA. Fix: add `fastcgi_param HTTP_X_FORWARDED_PORT 8080;` to the `location ~ \.php$` block.

---

## Verification Strategy

> **ZERO HUMAN INTERVENTION** — ALL verification is agent-executed. No exceptions.

### Test Decision
- **Infrastructure exists**: YES (Pest for PHP, Vitest for JS, Playwright for browser)
- **Automated tests**: Tests-after (Docker-level verification via inspection commands, curl, and Playwright for browser flows)
- **Framework**: Pest + bash/curl for Docker config verification
- **No TDD**: Docker configuration changes are verified by building and inspecting, not unit tests

### QA Policy
Every task MUST include agent-executed QA scenarios.
Evidence saved to `.sisyphus/evidence/task-{N}-{scenario-slug}.{ext}`.

- **Docker verification**: Use `docker compose` commands, `docker inspect`, `curl`
- **Browser/UI**: Use Playwright for end-to-end media browse and download flow
- **CLI checks**: Use `docker compose exec` for artisan commands and process checks

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately — foundation, all parallel):
├── Task 1: Add Opcache to Dockerfile + opcache.ini [quick]
├── Task 2: Production-safe .env.docker defaults [quick]
├── Task 3: PHP-FPM pool tuning config [quick]
├── Task 4: Entrypoint simplification [quick]

Wave 2 (After Wave 1 — security + config, 2 parallel groups):
├── Task 5: Multi-stage Dockerfile refactor [deep]
├── Task 6: Docker Compose health checks + resource limits + security [unspecified-high]
├── Task 7: Nginx X-Accel media location + performance tuning [unspecified-high]
├── Task 8: Scheduler container [quick]

Wave 3 (After Wave 2 — application change, all parallel):
├── Task 9: FileStorageResponseService X-Accel support [deep]
├── Task 10: Verify /up health route in bootstrap/app.php [quick]

Wave FINAL (After ALL tasks — integration verification):
├── Task 11: Build and end-to-end Docker verification [unspecified-high]
├── Task F1: Plan compliance audit (oracle)
├── Task F2: Code quality review (unspecified-high)
├── Task F3: Real manual QA (unspecified-high)
├── Task F4: Scope fidelity check (deep)

Critical Path: Task 1 → Task 5 → Task 7 → Task 9 → Task 11
Parallel Speedup: ~60% faster than sequential
Max Concurrent: 6 (Wave 1)
```

---

## TODOs

### Wave 1 — Foundation (all parallel, no dependencies)

- [x] 1. Add Opcache to Dockerfile and create tuned opcache.ini

  **What to do**:
  - In `docker/php/Dockerfile`, add `opcache` to `docker-php-ext-install` and add `docker-php-ext-enable opcache`
  - Create `docker/php/opcache.ini` with production settings:
    ```ini
    opcache.enable=1
    opcache.enable_cli=0
    opcache.memory_consumption=256
    opcache.interned_strings_buffer=16
    opcache.max_accelerated_files=65407
    opcache.validate_timestamps=0
    opcache.revalidate_freq=0
    opcache.save_comments=1
    opcache.max_wasted_percentage=10
    ```
  - Copy the ini file into the container in the Dockerfile: `COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini`
  - Verify the existing `entrypoint.sh` line order — the `exec "$@"` at end must be the final line

  **Must NOT do**:
  - Do NOT change any other PHP extension installs
  - Do NOT modify FPM pool config (that's Task 3)
  - Do NOT enable opcache.enable_cli (wastes memory on CLI processes)

  **Recommended Agent Profile**:
  > Quick Dockerfile and ini config change — straightforward, single-concern.
  - **Category**: `quick`
    - Reason: Single Dockerfile line addition + one new ini file — no complex logic
  - **Skills**: `[]`
    - No specialized skills needed — plain Dockerfile editing
  - **Skills Evaluated but Omitted**:
    - `git-master`: Not committing yet
    - `playwright`: No browser testing needed

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 2, 3, 4)
  - **Blocks**: Task 5 (multi-stage build needs Opcache in place)
  - **Blocked By**: None (can start immediately)

  **References** (CRITICAL):
  - `docker/php/Dockerfile:1-32` — Current Dockerfile (add opcache to ext install line, add COPY for ini)
  - `docker/php/entrypoint.sh:1-33` — Entrypoint to verify exec "$@" is final line
  - PHP Opcache docs: `https://www.php.net/manual/en/opcache.configuration.php` — Reference for ini values

  **Acceptance Criteria**:
  - [ ] `docker compose build --no-cache php` succeeds
  - [ ] `docker compose run --rm php php -m | grep -i opcache` outputs `Zend OPcache`
  - [ ] `docker compose run --rm php php -i | grep 'opcache.enable'` shows `On`
  - [ ] `docker compose run --rm php php -i | grep 'opcache.validate_timestamps'` shows `Off`
  - [ ] `docker compose run --rm php php -i | grep 'opcache.memory_consumption'` shows `256`

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Opcache is installed and configured in Docker image
    Tool: Bash (docker compose)
    Preconditions: None (fresh build)
    Steps:
      1. docker compose build --no-cache php
      2. docker compose run --rm php php -m | grep -i opcache
      3. docker compose run --rm php php -i | grep 'opcache.enable => On'
      4. docker compose run --rm php php -i | grep 'opcache.validate_timestamps => Off'
      5. docker compose run --rm php php -i | grep 'opcache.memory_consumption => 256'
    Expected Result: All greps return matching lines; build succeeds with exit 0
    Failure Indicators: grep returns empty (opcache not installed/enabled), build fails
    Evidence: .sisyphus/evidence/task-1-opcache.{txt,log}

  Scenario: Opcache not enabled in CLI mode
    Tool: Bash (docker compose)
    Preconditions: Image built from Task 1
    Steps:
      1. docker compose run --rm php php -i | grep 'opcache.enable_cli => Off'
    Expected Result: Shows Off (saves memory on artisan/queue processes)
    Failure Indicators: Shows On (wastes memory)
    Evidence: .sisyphus/evidence/task-1-opcache-cli.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-1-opcache.txt` — php -m output showing Zend OPcache
  - [ ] `task-1-opcache-cli.txt` — php -i output showing enable_cli=Off

  **Commit**: YES (groups with Task 1 only)
  - Message: `fix(docker): add Opcache extension and tuned ini`
  - Files: `docker/php/Dockerfile`, `docker/php/opcache.ini`

- [x] 2. Production-safe .env.docker defaults

  **What to do**:
  - Change `APP_ENV=local` → `APP_ENV=production`
  - Change `APP_DEBUG=true` → `APP_DEBUG=false`
  - Change `SESSION_DRIVER=database` → `SESSION_DRIVER=redis`
  - Verify `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` are already set
  - Verify `FILESYSTEM_DISK=local` is appropriate (app uses atl as-app disk for media, local is the framework default)
  - Verify `SCOUT_DRIVER` is not set (defaults to `typesense` in `config/scout.php`)

  **Must NOT do**:
  - Do NOT hardcode any secret key values
  - Do NOT change `APP_KEY=` behavior (placeholder filled by `docker-setup.sh`)
  - Do NOT change database connection settings
  - Do NOT remove existing Typesense or Redis env vars

  **Recommended Agent Profile**:
  > Simple env file value changes — rote, single-file.
  - **Category**: `quick`
    - Reason: Straightforward key-value replacements in .env.docker
  - **Skills**: `[]`
  - **Skills Evaluated but Omitted**:
    - `git-master`: Not committing yet

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 3, 4)
  - **Blocks**: Task 6 (health checks need correct env), Task 7 (Nginx config)
  - **Blocked By**: None

  **References** (CRITICAL):
  - `.env.docker:1-10` — Current APP_ENV/APP_DEBUG/APP_KEY values
  - `.env.docker:55-60` — Current SESSION_DRIVER/queue/cache settings
  - `config/session.php:21` — SESSION_DRIVER default to verify change is needed

  **Acceptance Criteria**:
  - [ ] `.env.docker` contains `APP_ENV=production`
  - [ ] `.env.docker` contains `APP_DEBUG=false`
  - [ ] `.env.docker` contains `SESSION_DRIVER=redis`

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: .env.docker has production-safe defaults
    Tool: Bash (grep)
    Preconditions: .env.docker exists
    Steps:
      1. grep ^APP_ENV= .env.docker — assert output is APP_ENV=production
      2. grep ^APP_DEBUG= .env.docker — assert output is APP_DEBUG=false
      3. grep ^SESSION_DRIVER= .env.docker — assert output is SESSION_DRIVER=redis
      4. grep ^QUEUE_CONNECTION= .env.docker — assert output is QUEUE_CONNECTION=redis
      5. grep ^CACHE_STORE= .env.docker — assert output is CACHE_STORE=redis
    Expected Result: All 5 grep commands output the correct production values
    Failure Indicators: Any grep shows local/dev values (debug=true, env=local)
    Evidence: .sisyphus/evidence/task-2-env.txt

  Scenario: No debug mode in Docker runtime
    Tool: Bash (docker compose)
    Preconditions: docker compose up -d php
    Steps:
      1. docker compose exec php php artisan tinker --execute="echo config('app.debug')?'debug':'no-debug';"
    Expected Result: Outputs "no-debug"
    Failure Indicators: Outputs "debug"
    Evidence: .sisyphus/evidence/task-2-debug-check.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-2-env.txt` — grep output of all 5 env vars
  - [ ] `task-2-debug-check.txt` — artisan tinker output

  **Commit**: YES
  - Message: `fix(docker): production-safe environment defaults`
  - Files: `.env.docker`

- [x] 3. PHP-FPM pool tuning config

  **What to do**:
  - Create `docker/php/zz-docker.conf` with PHP-FPM pool overrides:
    ```ini
    [www]
    pm = dynamic
    pm.max_children = 16
    pm.start_servers = 4
    pm.min_spare_servers = 4
    pm.max_spare_servers = 8
    pm.max_requests = 500

    request_terminate_timeout = 120s
    request_slowlog_timeout = 10s
    slowlog = /proc/self/fd/2

    php_admin_value[memory_limit] = 256M
    ```
  - In `docker/php/Dockerfile`, add: `COPY docker/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf`
  - The `[www]` section name matches the default pool from the base image

  **Must NOT do**:
  - Do NOT modify the base image's `www.conf` directly — use override file
  - Do NOT set `pm = static` (dynamic is safer for bursty media traffic)
  - Do NOT set `pm = ondemand` (cold start penalty hurts media-heavy apps)
  - Do NOT remove the `[www]` pool name — must match default

  **Recommended Agent Profile**:
  > Single new config file + one-line Dockerfile addition.
  - **Category**: `quick`
    - Reason: Well-defined FPM config values, one new file, one Dockerfile COPY
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2, 4)
  - **Blocks**: Task 5 (multi-stage build needs FPM config)
  - **Blocked By**: None

  **References** (CRITICAL):
  - `docker/php/Dockerfile:1-32` — Current Dockerfile for COPY placement
  - PHP-FPM config docs: `https://www.php.net/manual/en/install.fpm.configuration.php` — Reference for pool directives

  **Acceptance Criteria**:
  - [ ] `docker compose build --no-cache php` succeeds
  - [ ] `docker compose run --rm php php-fpm -t` passes config test
  - [ ] `docker compose run --rm php cat /usr/local/etc/php-fpm.d/zz-docker.conf` shows `pm.max_children = 16`

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: FPM pool config applied and valid
    Tool: Bash (docker compose)
    Preconditions: Image built with zz-docker.conf
    Steps:
      1. docker compose build --no-cache php
      2. docker compose run --rm php php-fpm -t
      3. docker compose run --rm php cat /usr/local/etc/php-fpm.d/zz-docker.conf | grep 'pm.max_children = 16'
      4. docker compose run --rm php cat /usr/local/etc/php-fpm.d/zz-docker.conf | grep 'pm.max_requests = 500'
      5. docker compose run --rm php cat /usr/local/etc/php-fpm.d/zz-docker.conf | grep 'memory_limit] = 256M'
    Expected Result: php-fpm -t exits 0 (config valid); all greps match
    Failure Indicators: Config test fails, grep returns empty
    Evidence: .sisyphus/evidence/task-3-fpm-config.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-3-fpm-config.txt` — full zz-docker.conf contents from container

  **Commit**: YES
  - Message: `fix(docker): tune PHP-FPM pool for media workload`
  - Files: `docker/php/zz-docker.conf`, `docker/php/Dockerfile`

- [x] 4. Entrypoint simplification (runtime-only concerns)

  **What to do**:
  - In `docker/php/entrypoint.sh`, remove the conditional `composer install`, `npm install`, and `npm run build` blocks (lines 4-20)
  - Keep: `storage:link` check and creation
  - Keep: `chown` and `chmod` permission fixes
  - Keep: `exec "$@"` at end
  - The resulting entrypoint should only handle runtime concerns: symlinks, permissions, then exec

  **Must NOT do**:
  - Do NOT remove `set -e` (must keep fail-fast)
  - Do NOT remove the `exec "$@"` final line
  - Do NOT add any new build-time steps (those belong in Dockerfile)
  - Do NOT remove the `storage:link` block

  **Recommended Agent Profile**:
  > Straightforward bash script simplification — remove code blocks, keep structure.
  - **Category**: `quick`
    - Reason: Deleting known lines from a 33-line script, no new logic
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2, 3)
  - **Blocks**: Task 5 (multi-stage build needs simplified entrypoint)
  - **Blocked By**: None

  **References** (CRITICAL):
  - `docker/php/entrypoint.sh:1-33` — Full current entrypoint to identify lines to remove
  - `docker/php/entrypoint.sh:4-8` — composer install block (REMOVE)
  - `docker/php/entrypoint.sh:10-14` — npm install block (REMOVE)
  - `docker/php/entrypoint.sh:16-20` — npm run build block (REMOVE)
  - `docker/php/entrypoint.sh:29-30` — chown/chmod blocks (KEEP)

  **Acceptance Criteria**:
  - [ ] Entrypoint contains no `composer install` command
  - [ ] Entrypoint contains no `npm install` command
  - [ ] Entrypoint contains no `npm run build` command
  - [ ] Entrypoint still contains `storage:link`
  - [ ] Entrypoint still contains `chown -R www-data`
  - [ ] Entrypoint still ends with `exec "$@"`

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Entrypoint only handles runtime concerns
    Tool: Bash (grep)
    Preconditions: Modified entrypoint.sh
    Steps:
      1. grep -c 'composer install' docker/php/entrypoint.sh — assert 0
      2. grep -c 'npm install' docker/php/entrypoint.sh — assert 0
      3. grep -c 'npm run build' docker/php/entrypoint.sh — assert 0
      4. grep -c 'storage:link' docker/php/entrypoint.sh — assert >= 1
      5. grep -c 'exec "\$@"' docker/php/entrypoint.sh — assert 1
    Expected Result: Build commands absent, runtime commands present, exec preserved
    Failure Indicators: Build commands found (not removed properly)
    Evidence: .sisyphus/evidence/task-4-entrypoint.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-4-entrypoint.txt` — grep output showing removed and preserved commands

  **Commit**: YES
  - Message: `refactor(docker): simplify entrypoint to runtime-only concerns`
  - Files: `docker/php/entrypoint.sh`

### Wave 2 — Security + Configuration (runs after Wave 1)

- [x] 5. Multi-stage Dockerfile refactor

  **What to do**:
  - Refactor `docker/php/Dockerfile` into two stages:
    - **Stage 1 (builder)**: Install Node + npm + Composer, copy source, run `composer install --no-dev --optimize-autoloader --classmap-authoritative`, run `npm ci && npm run build`
    - **Stage 2 (runtime)**: Base `php:8.4-fpm`, install only runtime dependencies (ffmpeg, yt-dlp), install PHP extensions (opcache, pdo_mysql, mbstring, exif, pcntl, bcmath, gd, zip, sockets, redis, imagick), COPY vendor and public/build from builder, COPY app source, COPY config and ini files, set up non-root user
  - COPY `docker/php/opcache.ini` and `docker/php/zz-docker.conf` into the runtime stage
  - Final image must NOT contain: node, npm, composer (build tools)
  - Runtime image should run as `www-data` user
  - Keep `ffmpeg` and `yt-dlp` in runtime (needed for media processing jobs)

  **Must NOT do**:
  - Do NOT remove ffmpeg or yt-dlp (needed by download/processing jobs)
  - Do NOT use Alpine base image (gd/imagick compatibility issues)
  - Do NOT change the working directory from `/var/www/html`
  - Do NOT remove any PHP extensions from the current list

  **Recommended Agent Profile**:
  > Complex Dockerfile restructuring with dependency analysis between stages.
  - **Category**: `deep`
    - Reason: Multi-stage refactor requires understanding of what each stage needs, build artifacts, and production runtime requirements
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2b (with Tasks 7, 8)
  - **Blocks**: Task 11 (final Docker verification)
  - **Blocked By**: Tasks 1, 3, 4 (needs Opcache, FPM config, simplified entrypoint)

  **References** (CRITICAL):
  - `docker/php/Dockerfile:1-32` — Current Dockerfile (source for full refactor)
  - `docker/php/opcache.ini` — Created in Task 1 (must be COPIED into runtime)
  - `docker/php/zz-docker.conf` — Created in Task 3 (must be COPIED into runtime)
  - `docker/php/entrypoint.sh` — Simplified in Task 4 (COPIED into runtime)
  - Laravel deployment docs: `https://laravel.com/docs/12.x/deployment` — Reference for optimize commands
  - Docker multi-stage build docs: `https://docs.docker.com/build/building/multi-stage/` — Reference for COPY --from syntax

  **Acceptance Criteria**:
  - [ ] `docker compose build --no-cache php` succeeds
  - [ ] `docker compose run --rm php which node` returns non-zero (node not installed)
  - [ ] `docker compose run --rm php which npm` returns non-zero (npm not installed)
  - [ ] `docker compose run --rm php which composer` returns non-zero (composer not in runtime)
  - [ ] `docker compose run --rm php which ffmpeg` returns `/usr/bin/ffmpeg` (still present)
  - [ ] `docker compose run --rm php which yt-dlp` returns `/usr/bin/yt-dlp` (still present)
  - [ ] `docker compose run --rm php whoami` returns `www-data` (non-root)
  - [ ] `docker compose run --rm php php -v` works (PHP functional)
  - [ ] `docker compose run --rm php test -d vendor` exits 0 (vendor present)
  - [ ] `docker compose run --rm php test -d public/build` exits 0 (built assets present)

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Build tools absent from runtime image
    Tool: Bash (docker compose)
    Preconditions: docker compose build --no-cache php
    Steps:
      1. docker compose run --rm php which node; test $? -ne 0
      2. docker compose run --rm php which npm; test $? -ne 0
      3. docker compose run --rm php which composer; test $? -ne 0
      4. docker compose run --rm php which ffmpeg
      5. docker compose run --rm php which yt-dlp
      6. docker compose run --rm php whoami
    Expected Result: node/npm/composer NOT found (exit non-zero), ffmpeg/yt-dlp found, user is www-data
    Failure Indicators: Build tools still present, runtime tools missing, running as root
    Evidence: .sisyphus/evidence/task-5-image-contents.txt

  Scenario: Laravel application boots in runtime image
    Tool: Bash (docker compose)
    Preconditions: Image built and running
    Steps:
      1. docker compose run --rm php php artisan --version
      2. docker compose run --rm php php artisan tinker --execute="echo 'boot-ok';"
    Expected Result: artisan --version returns Laravel version, tinker outputs boot-ok
    Failure Indicators: artisan commands fail (missing vendor, wrong paths, permission issues)
    Evidence: .sisyphus/evidence/task-5-boot.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-5-image-contents.txt` — which/node/npm/composer/ffmpeg/yt-dlp + whoami output
  - [ ] `task-5-boot.txt` — artisan version and tinker output

  **Commit**: YES
  - Message: `refactor(docker): multi-stage build separating build from runtime`
  - Files: `docker/php/Dockerfile`

- [x] 6. Docker Compose health checks, resource limits, and container security

  **What to do**:
  - Add health checks to `docker-compose.yml` for:
    - `php`: `test: ["CMD", "curl", "-fsS", "http://127.0.0.1/up"]` with `interval: 30s`, `timeout: 3s`, `retries: 3`
    - `horizon`: `test: ["CMD", "php", "artisan", "horizon:status"]` with `interval: 30s`, `timeout: 10s`, `retries: 3`
    - `reverb`: `test: ["CMD", "nc", "-z", "127.0.0.1", "8080"]` with `interval: 30s`, `timeout: 3s`, `retries: 3`
    - `nginx`: `test: ["CMD", "curl", "-fsS", "http://127.0.0.1/up"]` with `interval: 30s`, `timeout: 3s`, `retries: 3`
  - Add resource limits to all services:
    ```yaml
    php:
      deploy:
        resources:
          limits: { memory: 512M }
          reservations: { memory: 256M }
    horizon:
      deploy:
        resources:
          limits: { memory: 2G }
          reservations: { memory: 512M }
    typesense:
      deploy:
        resources:
          limits: { memory: 2G }
          reservations: { memory: 512M }
      command: >
        --data-dir=/data --api-key=${TYPESENSE_API_KEY}
        --memory-used-max-percentage=80
        --enable-cors
    ```
  - Add Docker security hardening to `php` service (conservative — only where safe):
    ```yaml
    php:
      read_only: false  # Must remain writable (storage, bootstrap/cache, temp)
      cap_drop: [ALL]
      security_opt: [no-new-privileges:true]
    ```
  - Add `tmpfs` to the `php` service for `/tmp` and `/var/run`

  **Must NOT do**:
  - Do NOT set `read_only: true` on php (needs writable storage/bootstrap/cache)
  - Do NOT add health checks that depend on DB/Redis/Typesense (liveness-only)
  - Do NOT remove existing `depends_on` conditions
  - Do NOT set resource limits on mariadb/redis (infrastructure services with existing configs)

  **Recommended Agent Profile**:
  > Multiple compose config additions with resource and security awareness.
  - **Category**: `unspecified-high`
    - Reason: Multiple service blocks to modify, needs to understand Docker resource limits and security options
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2b (with Tasks 5, 7, 8 — but Task 5 modifies same Dockerfile, could conflict)
  - **Actually**: Sequential after Task 5 (both modify Dockerfile), but parallel with Tasks 7, 8
  - **Blocks**: Task 11 (final verification)
  - **Blocked By**: Task 2 (.env for APP_ENV), Task 5 (Dockerfile base image)

  **References** (CRITICAL):
  - `docker-compose.yml:1-180` — Full compose file for service definitions
  - `docker-compose.yml:14-25` — php service block (add healthcheck + limits + security)
  - `docker-compose.yml:119-140` — horizon service block (add healthcheck + limits)
  - `docker-compose.yml:101-118` — reverb service block (add healthcheck)
  - `docker-compose.yml:27-32` — nginx service block (add healthcheck)
  - `docker-compose.yml:60-95` — typesense service block (add limits + memory flag)
  - Docker health check docs: `https://docs.docker.com/reference/dockerfile/#healthcheck`
  - Docker compose deploy docs: `https://docs.docker.com/reference/compose-file/services/#deploy`

  **Acceptance Criteria**:
  - [ ] `docker compose up -d` starts all services
  - [ ] After 60s, `docker compose ps | grep -c healthy` shows 8 (all services healthy)
  - [ ] `docker compose ps | grep -c unhealthy` shows 0
  - [ ] `docker inspect atlas-php --format '{{.HostConfig.Memory}}'` shows non-zero limit
  - [ ] `docker inspect atlas-php --format '{{.HostConfig.CapDrop}}'` includes `ALL`

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: All containers report healthy after startup
    Tool: Bash (docker compose)
    Preconditions: docker compose up -d, wait 60s
    Steps:
      1. docker compose ps --format json | python3 -c "import sys,json; [print(json.loads(l)['Health']) for l in sys.stdin]" | sort | uniq -c
    Expected Result: All 8 services show "healthy", zero show "unhealthy" or "starting"
    Failure Indicators: Any service unhealthy or stuck in "starting" after 60s
    Evidence: .sisyphus/evidence/task-6-health.txt

  Scenario: Resource limits applied
    Tool: Bash (docker inspect)
    Preconditions: docker compose up -d
    Steps:
      1. docker inspect atlas-php --format 'Memory limit: {{.HostConfig.Memory}}'
      2. docker inspect atlas-horizon --format 'Memory limit: {{.HostConfig.Memory}}'
      3. docker inspect atlas-typesense --format 'Memory limit: {{.HostConfig.Memory}}'
    Expected Result: All show Memory > 0 (limits applied)
    Failure Indicators: Memory = 0 (no limit set)
    Evidence: .sisyphus/evidence/task-6-limits.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-6-health.txt` — docker compose ps health status aggregation
  - [ ] `task-6-limits.txt` — docker inspect memory limits

  **Commit**: YES
  - Message: `feat(docker): health checks, resource limits, and container security`
  - Files: `docker-compose.yml`

- [x] 7. Nginx X-Accel media location and performance tuning

  **What to do**:
  - Add internal media location for X-Accel-Redirect in `docker/nginx/default.conf`:
    ```nginx
    location /_media/ {
        internal;
        alias /var/www/html/storage/app/atlas/.app/;
        add_header Accept-Ranges bytes always;
        sendfile on;
        tcp_nopush on;
        sendfile_max_chunk 1m;
    }
    ```
  - Add static asset caching for hashed build files:
    ```nginx
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, max-age=31536000, immutable" always;
    }
    ```
  - Add `http` block tuning (if not using separate nginx.conf that includes the server block):
    ```nginx
    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    open_file_cache max=10000 inactive=60s;
    open_file_cache_valid 120s;
    open_file_cache_min_uses 2;
    ```
  - Add gzip tuning:
    ```nginx
    gzip on;
    gzip_comp_level 4;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript image/svg+xml application/wasm;
    ```
    Note: explicitly exclude `image/png image/jpeg image/webp video/mp4 video/webm` from gzip_types (already not listed, but verify)

  **Must NOT do**:
  - Do NOT add caching headers to `/api/` location (private data)
  - Do NOT enable gzip on images or videos
  - Do NOT change the `/app` WebSocket proxy location
  - Do NOT remove `client_max_body_size 100M`
  - Do NOT add `immutable` cache headers to non-hashed assets

  **Recommended Agent Profile**:
  > Nginx configuration with multiple location blocks and performance directives — needs careful ordering.
  - **Category**: `unspecified-high`
    - Reason: Multiple nginx directives across location blocks, must not break existing routes
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2b (with Tasks 5, 6, 8)
  - **Blocks**: Task 9 (X-Accel in PHP code depends on /_media/ location), Task 11
  - **Blocked By**: None (can start immediately after Wave 1)

  **References** (CRITICAL):
  - `docker/nginx/default.conf:1-52` — Full current nginx config
  - `docker/nginx/default.conf:21-31` — Current PHP-FPM location block (keep intact)
  - `docker/nginx/default.conf:7-20` — Current /app WebSocket proxy (keep intact)
  - Nginx core module docs: `https://nginx.org/en/docs/http/ngx_http_core_module.html` — sendfile, tcp_nopush, open_file_cache
  - Nginx gzip module docs: `https://nginx.org/en/docs/http/ngx_http_gzip_module.html` — gzip_types

  **Acceptance Criteria**:
  - [ ] `docker compose restart nginx` succeeds
  - [ ] `curl -o /dev/null -w "%{http_code}" http://localhost:8080` returns 200 (app still works)
  - [ ] `curl -o /dev/null -w "%{http_code}" http://localhost:8080/_media/test` returns 404 (internal-only)
  - [ ] `curl -sI http://localhost:8080/build/assets/somefile.js | grep -i cache-control` shows `immutable` (or `max-age=31536000`)

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Internal media location blocked from direct access
    Tool: Bash (curl)
    Preconditions: nginx restarted with new config
    Steps:
      1. curl -o /dev/null -w "%{http_code}" http://localhost:8080/_media/test.jpg
    Expected Result: HTTP 404 (not 200 — internal; blocks direct access)
    Failure Indicators: Returns 200 (media publicly accessible — SERIOUS)
    Evidence: .sisyphus/evidence/task-7-internal-block.txt

  Scenario: Build assets have immutable caching
    Tool: Bash (curl)
    Preconditions: nginx restarted
    Steps:
      1. Find any file in public/build/: ls public/build/assets/*.js | head -1
      2. curl -sI http://localhost:8080/build/assets/{filename} | grep -i 'cache-control\|expires'
    Expected Result: Cache-Control header present with long max-age
    Failure Indicators: No cache headers (assets will be re-fetched every request)
    Evidence: .sisyphus/evidence/task-7-cache-headers.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-7-internal-block.txt` — curl status code from /_media/ direct access
  - [ ] `task-7-cache-headers.txt` — curl headers showing cache directives

  **Commit**: YES
  - Message: `feat(docker): X-Accel media serving and Nginx performance tuning`
  - Files: `docker/nginx/default.conf`

- [x] 8. Dedicated scheduler container

  **What to do**:
  - Add a new `scheduler` service to `docker-compose.yml`:
    ```yaml
    scheduler:
      build:
        context: .
        dockerfile: docker/php/Dockerfile
      image: atlas-php
      container_name: atlas-scheduler
      restart: unless-stopped
      command: php artisan schedule:work
      env_file:
        - .env.docker
      volumes:
        - ./:/var/www/html
      networks:
        - atlas-network
      depends_on:
        - php
        - redis
    ```
  - The scheduler uses the same multi-stage Docker image but runs `schedule:work` instead of `php-fpm`
  - It's a singleton — only one instance in the compose file
  - Verify no other service runs `schedule:run` or `schedule:work`

  **Must NOT do**:
  - Do NOT add scheduler command to php/horizon/reverb containers
  - Do NOT add multiple scheduler replicas
  - Do NOT use `schedule:run` (cron-based) — use `schedule:work` (long-running process)

  **Recommended Agent Profile**:
  > One new service block in docker-compose.yml — straightforward.
  - **Category**: `quick`
    - Reason: Single yml service addition following existing patterns
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2b (with Tasks 5, 6, 7)
  - **Blocks**: Task 11 (final verification)
  - **Blocked By**: Task 5 (Dockerfile must exist)

  **References** (CRITICAL):
  - `docker-compose.yml:119-140` — horizon service block (model for scheduler block)
  - `docker-compose.yml:1-15` — php service block (build context pattern)
  - Laravel scheduling docs: `https://laravel.com/docs/12.x/scheduling` — Reference for schedule:work

  **Acceptance Criteria**:
  - [ ] `docker compose up -d scheduler` starts the container
  - [ ] `docker compose ps scheduler` shows `Up` status
  - [ ] `docker compose exec scheduler pgrep -af "artisan schedule:work"` shows exactly one process

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Scheduler container runs as singleton
    Tool: Bash (docker compose)
    Preconditions: docker compose up -d scheduler
    Steps:
      1. docker compose ps scheduler --format json | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(d['State'])"
      2. docker compose exec scheduler pgrep -f "artisan schedule:work" | wc -l
    Expected Result: Container state is "running", exactly 1 process
    Failure Indicators: Container exited, 0 processes, or >1 process
    Evidence: .sisyphus/evidence/task-8-scheduler.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-8-scheduler.txt` — container state and process count

  **Commit**: YES
  - Message: `feat(docker): dedicated scheduler container`
  - Files: `docker-compose.yml`

---

### Wave 3 — Application Code Changes (runs after Wave 2)

- [x] 9. FileStorageResponseService X-Accel-Redirect support with non-Docker fallback

  **What to do**:
  - Modify `app/Services/FileStorageResponseService::serveDiskPath()` to detect whether the app is behind Nginx with X-Accel configured
  - Detection logic: check for the presence of `$_SERVER['HTTP_X_ACCEL_REDIRECT_CAPABLE']` OR check if `request()->server('SERVER_SOFTWARE')` contains 'nginx'
  - When behind nginx: return `X-Accel-Redirect` header with the mapped path instead of streaming/file response
  - The mapped path: `/_media/` + relative path from ATLAS_STORAGE/.app/ root
  - Example return:
    ```php
    return response('', 200, [
        'X-Accel-Redirect' => '/_media/' . $relativePath,
        'Content-Type' => $mimeType,
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
        'Accept-Ranges' => 'bytes',
    ]);
    ```
  - When NOT behind nginx (Herd/local dev): keep existing `response()->stream()` and `response()->file()` behavior unchanged
  - Must preserve: Range request handling, Content-Type, Content-Disposition, 206/416 status codes

  **Must NOT do**:
  - Do NOT remove the existing PHP streaming fallback
  - Do NOT change the authorization (auth middleware stays)
  - Do NOT change the storage path resolution (AtlasPathResolver stays)
  - Do NOT remove `Accept-Ranges: bytes` header
  - Do NOT hardcode paths — must use the resolved relative path
  - Do NOT serve files from public/storage (must use /_media/ internal only)

  **Recommended Agent Profile**:
  > Behavior-preserving refactor of media serving with architectural awareness.
  - **Category**: `deep`
    - Reason: Must understand current byte-range streaming, X-Accel pattern, and preserve both code paths; high-impact change
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Task 10)
  - **Blocks**: Task 11 (end-to-end media QA)
  - **Blocked By**: Task 7 (nginx /_media/ location must exist)

  **References** (CRITICAL):
  - `app/Services/FileStorageResponseService.php:133-227` — serveDiskPath() method (ENTIRE method — must preserve byte-range logic)
  - `app/Services/FileStorageResponseService.php:146-151` — Current base headers (Content-Type, Accept-Ranges)
  - `app/Services/FileStorageResponseService.php:153-220` — Byte-range request handling (must preserve for Herd fallback)
  - `app/Services/FileStorageResponseService.php:106-131` — serve(), serveDownloaded(), servePreview(), serveVideoPoster() (callers of serveDiskPath)
  - `docker/nginx/default.conf` — /_media/ location path (Task 7 output)

  **Acceptance Criteria**:
  - [ ] When behind nginx, media response includes `X-Accel-Redirect` header
  - [ ] When behind nginx, response body is empty (nginx serves the file)
  - [ ] When NOT behind nginx, existing stream/file behavior works unchanged
  - [ ] `Accept-Ranges: bytes` header present in both modes
  - [ ] `Content-Type` correctly set in both modes
  - [ ] Existing Pest tests for file serving still pass

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Media served via X-Accel when behind nginx
    Tool: Bash (curl)
    Preconditions: Docker running, authenticated (need cookie/token), a file exists with id=1
    Steps:
      1. Login and get session cookie: curl -c /tmp/cookies -X POST http://localhost:8080/login -d "email=demo@atlas.test&password=password" -H "Content-Type: application/x-www-form-urlencoded"
      2. Request media with auth: curl -b /tmp/cookies -sI http://localhost:8080/api/files/1/serve
      3. Check for X-Accel-Redirect header
      4. Check response body is empty
    Expected Result: 200 status, X-Accel-Redirect header present, Content-Type set, empty body
    Failure Indicators: Response contains file bytes (PHP streaming), no X-Accel header
    Evidence: .sisyphus/evidence/task-9-xaccel.txt

  Scenario: Byte-range request handled correctly via X-Accel
    Tool: Bash (curl)
    Preconditions: Authenticated, file exists
    Steps:
      1. curl -b /tmp/cookies -sI -H "Range: bytes=0-99" http://localhost:8080/api/files/1/serve
      2. Check status 206, Content-Range header present
    Expected Result: 206 Partial Content, Content-Range header with bytes 0-99/size
    Failure Indicators: 200 (full file returned — range not honored)
    Evidence: .sisyphus/evidence/task-9-range.txt

  Scenario: Direct /_media/ access returns 404
    Tool: Bash (curl)
    Preconditions: nginx running with internal; directive
    Steps:
      1. curl -o /dev/null -w "%{http_code}" http://localhost:8080/_media/some/path.jpg
    Expected Result: 404
    Failure Indicators: 200 (media exposed without auth)
    Evidence: .sisyphus/evidence/task-9-direct-block.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-9-xaccel.txt` — curl response headers showing X-Accel-Redirect
  - [ ] `task-9-range.txt` — curl response showing 206 + Content-Range
  - [ ] `task-9-direct-block.txt` — curl status code from direct /_media/ access

  **Commit**: YES
  - Message: `feat: X-Accel-Redirect media serving with non-Docker fallback`
  - Files: `app/Services/FileStorageResponseService.php`

- [x] 10. Verify /up health route in bootstrap/app.php

  **What to do**:
  - Check `bootstrap/app.php` for existing `->withRouting()` call
  - Verify the `health:` parameter is set to `'/up'` (Laravel 12 built-in health route)
  - If missing, add `health: '/up'` to the `->withRouting()` call
  - The `/up` route returns 200 if the app booted successfully, handles `DiagnosingHealth` event for deeper checks
  - This route is used by Docker health checks

  **Must NOT do**:
  - Do NOT create a custom health controller (use Laravel built-in)
  - Do NOT add DB/Redis/Typesense checks to /up (liveness-only)
  - Do NOT modify other routing configuration

  **Recommended Agent Profile**:
  > Single-line check/verify in bootstrap file.
  - **Category**: `quick`
    - Reason: Verify one line exists, add if missing — trivial change
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Task 9)
  - **Blocks**: Task 11 (health check verification)
  - **Blocked By**: None

  **References** (CRITICAL):
  - `bootstrap/app.php:1-25` — Full bootstrap file (verify ->withRouting health parameter)
  - Laravel health route docs: `https://laravel.com/docs/12.x/deployment` — Reference for /up route

  **Acceptance Criteria**:
  - [ ] `curl -fsS http://localhost:8080/up` returns HTTP 200
  - [ ] Response contains `{"status":"ok"}` or similar success indicator
  - [ ] `bootstrap/app.php` includes `health: '/up'` in `->withRouting()`

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Health endpoint returns 200
    Tool: Bash (curl)
    Preconditions: docker compose up -d, app booted
    Steps:
      1. curl -fsS -w "\n%{http_code}" http://localhost:8080/up
    Expected Result: HTTP 200 with success response body
    Failure Indicators: 404 (route not registered), 500 (app failed to boot)
    Evidence: .sisyphus/evidence/task-10-health.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-10-health.txt` — curl response from /up

  **Commit**: YES (groups with Task 10 only, or squash with Task 9 if trivial)
  - Message: `fix: verify health route in bootstrap/app.php`
  - Files: `bootstrap/app.php`

### Wave 4 — Integration Verification (runs after ALL implementation tasks)

- [ ] 11. Full Docker build and end-to-end verification

  **Status**: INCOMPLETE — `docker-setup.sh` reported success but the application server was not actually accessible.

  **Previous Evidence**: `docker-hardening/final-summary.txt` showed 14/14 PASS, but this was based on isolated container-health checks (e.g., `docker compose ps`, `docker exec … horizon:status`). It did NOT verify the app is reachable from the host at `http://localhost:8080`, that the Laravel bootstrap completes, or that any actual feature (login, browse, media) works.

  **Root Cause Gap**: The plan’s Task-11 QA scenarios only checked:
  - Container state (healthy)
  - Internal CLI commands (`php artisan tinker`, `php -m`)
  - `/up` endpoint (Laravel health)
  - `/api/browse` endpoint (Typesense search)
  
  None of them verified the full HTTP stack from the **host perspective**: Nginx → PHP-FPM → Laravel → database/Redis/Typesense. The script may have started containers, but the app may not have been browsable.

  **Additional Gaps to Address**:
  - No check that `http://localhost:8080` (root path) returns 200 with the SPA Vue app
  - No check that Laravel session / auth layer works (login page loads)
  - No check that the Vue SPA mounts (js/css served, not 404)
  - No check that actual user flows work: login → browse → react → download
  - No cross-service integration test (Nginx → PHP-FPM → MariaDB → Redis → Typesense)

  **Corrected Acceptance Criteria**:
  - [ ] `docker compose build --no-cache` exits 0
  - [ ] `docker compose up -d` starts all services
  - [ ] All 8 containers report `healthy` within 120s
  - [ ] `curl -fsS http://localhost:8080/up` returns 200
  - [ ] `curl -fsS http://localhost:8080/` returns 200 (SPA boot page)
  - [ ] `curl -fsS http://localhost:8080/login` returns 200 (auth layer reachable)
  - [ ] Opcache is enabled in container
  - [ ] APP_DEBUG is false at runtime
  - [ ] Build tools absent from runtime image
  - [ ] Media X-Accel serving works
  - [ ] Browse API returns 200
  - [ ] **Host-level E2E**: `docker-setup.sh` from a clean state results in a working app at `http://localhost:8080`

  **Corrected QA Scenarios**:

  ```
  Scenario: Complete Docker stack build and health verification
    Tool: Bash (docker compose)
    Preconditions: Clean state (docker compose down -v if needed)
    Steps:
      1. docker compose build --no-cache 2>&1 | tail -5
      2. docker compose up -d
      3. sleep 90
      4. docker compose ps --format json | python3 -c "import sys,json; statuses=[json.loads(l)['Health'] for l in sys.stdin]; print(f'healthy={statuses.count(\"healthy\")} unhealthy={statuses.count(\"unhealthy\")} starting={statuses.count(\"starting\")}')"
    Expected Result: All 8 services healthy, 0 unhealthy, 0 starting
    Failure Indicators: Any unhealthy or stuck services
    Evidence: .sisyphus/evidence/task-11-full-health.txt

  Scenario: Browse API functional (Typesense connected)
    Tool: Bash (curl)
    Preconditions: All services healthy
    Steps:
      1. curl -fsS -w "\n%{http_code}" "http://localhost:8080/api/browse?feed=local&page=1&limit=5"
    Expected Result: HTTP 200 with JSON response (even if empty results)
    Failure Indicators: 503 (LocalBrowseUnavailableException — Typesense not connected)
    Evidence: .sisyphus/evidence/task-11-browse.txt

  Scenario: SPA boot page is reachable from the host
    Tool: Bash (curl)
    Preconditions: All services healthy
    Steps:
      1. curl -fsS -w "\n%{http_code}" http://localhost:8080/
    Expected Result: HTTP 200, body contains `<div id="app">` or SPA mount indicator
    Failure Indicators: 502, 504, empty body (Nginx/PHP-FPM misconfiguration)
    Evidence: .sisyphus/evidence/task-11-spa-boot.txt

  Scenario: Login page is reachable
    Tool: Bash (curl)
    Preconditions: All services healthy
    Steps:
      1. curl -fsS -w "\n%{http_code}" http://localhost:8080/login
    Expected Result: HTTP 200, body contains login form elements
    Failure Indicators: 404, 500 (route or auth middleware issue)
    Evidence: .sisyphus/evidence/task-11-login.txt
  ```

  **What to do**:
  - Run `docker compose build --no-cache` to rebuild all images
  - Run `docker compose up -d` to start all services
  - Wait for all health checks to pass (max 120s)
  - Run all verification commands from Task 1-10 in sequence:
    - Opcache installed and configured
    - APP_DEBUG=false at runtime
    - PHP-FPM pool config applied
    - Build tools absent from runtime image
    - All 8 containers healthy
    - Nginx serving app and blocking /_media/
    - Health endpoint returns 200
    - Scheduler running as singleton
    - Media X-Accel serving with correct byte ranges
    - Browse API working (Typesense connected)
  - Record all results

  **Must NOT do**:
  - Do NOT skip any verification step
  - Do NOT assume previous tasks passed — verify each independently

  **Recommended Agent Profile**:
  > Comprehensive integration testing — sequential commands, careful verification.
  - **Category**: `unspecified-high`
    - Reason: Runs all verification steps, must interpret results and identify failures
  - **Skills**: `[]`

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (must run after all implementation tasks)
  - **Blocks**: Final Verification Wave (F1-F4)
  - **Blocked By**: Tasks 1-10 (all implementation)

  **References** (CRITICAL):
  - `.sisyphus/plans/docker-hardening.md` — This plan (all acceptance criteria and QA scenarios)
  - All modified files from Tasks 1-10

  **Acceptance Criteria**:
  - [ ] `docker compose build --no-cache` exits 0
  - [ ] `docker compose up -d` starts all services
  - [ ] All 8 containers report `healthy` within 120s
  - [ ] `/up` endpoint returns 200
  - [ ] Opcache is enabled in container
  - [ ] APP_DEBUG is false at runtime
  - [ ] Build tools absent from runtime image
  - [ ] Media X-Accel serving works
  - [ ] Browse API returns 200

  **QA Scenarios (MANDATORY)**:

  ```
  Scenario: Complete Docker stack build and health verification
    Tool: Bash (docker compose)
    Preconditions: Clean state (docker compose down -v if needed)
    Steps:
      1. docker compose build --no-cache 2>&1 | tail -5
      2. docker compose up -d
      3. sleep 90
      4. docker compose ps --format json | python3 -c "import sys,json; statuses=[json.loads(l)['Health'] for l in sys.stdin]; print(f'healthy={statuses.count(\"healthy\")} unhealthy={statuses.count(\"unhealthy\")} starting={statuses.count(\"starting\")}')"
    Expected Result: All 8 services healthy, 0 unhealthy, 0 starting
    Failure Indicators: Any unhealthy or stuck services
    Evidence: .sisyphus/evidence/task-11-full-health.txt

  Scenario: Browse API functional (Typesense connected)
    Tool: Bash (curl)
    Preconditions: All services healthy
    Steps:
      1. curl -fsS -w "\n%{http_code}" "http://localhost:8080/api/browse?feed=local&page=1&limit=5"
    Expected Result: HTTP 200 with JSON response (even if empty results)
    Failure Indicators: 503 (LocalBrowseUnavailableException — Typesense not connected)
    Evidence: .sisyphus/evidence/task-11-browse.txt
  ```

  **Evidence to Capture**:
  - [ ] `task-11-full-health.txt` — Full docker compose ps health status
  - [ ] `task-11-browse.txt` — Browse API response

**New Integration Test Suite**: `scripts/test-docker.sh`
A comprehensive bash integration test suite that verifies the full Docker stack from the host perspective. Replaces the ad-hoc verification commands with 12 organized test sections:

1. Container Health — all 8 containers running + no unhealthy
2. Infrastructure Connectivity — MariaDB, Redis, Typesense, Horizon, Scheduler, Reverb all reachable from PHP container
3. Web Server (Nginx → PHP-FPM) — full-stack HTTP, login page, Vite assets, APP_URL correctness
4. Security Hardening — APP_DEBUG off, .env/.git blocked, non-root, no Node.js, capabilities dropped, no-new-privileges, Opcache
5. Application Configuration — APP_ENV, APP_DEBUG, APP_URL port, session/queue drivers, ffmpeg/yt-dlp
6. Authentication Flow — unauthenticated redirect, CSRF token, login with demo creds, authenticated API, logout
7. API Endpoints — public APIs (csrf, extension/ping), protected APIs blocked when unauthenticated, protected APIs accessible when authenticated
8. SPA Routes — all Vue routes return app shell when authenticated
9. WebSocket (Reverb) — upgrade handshake
10. Background Services — Horizon dashboard, scheduler process, Horizon status
11. Media Serving — /_media/ returns 404, storage symlink exists
12. phpMyAdmin — accessible on port 8081

**Known Bug**: Nginx `default.conf` does not set `fastcgi_param HTTP_X_FORWARDED_PORT` — Laravel generates redirect URLs to `http://localhost/` (port 80) instead of `http://localhost:8080/`. The test suite detects this in section 6 (Authentication Flow → redirect URL port check).

**Commit**: YES
- Message: `test(docker): full end-to-end deployment verification`
- Files: `scripts/test-docker.sh`, evidence files

---

## Final Verification Wave (MANDATORY — after ALL implementation tasks)

> 4 review agents run in PARALLEL. ALL must APPROVE. Present consolidated results to user and get explicit "okay" before completing.
>
> **Do NOT auto-proceed after verification. Wait for user's explicit approval before marking work complete.**
> **Never mark F1-F4 as checked before getting user's okay.** Rejection or user feedback -> fix -> re-run -> present again -> wait for okay.

- [ ] F1. **Plan Compliance Audit** — `oracle`
  Read the plan end-to-end. For each "Must Have": verify implementation exists (read file, check docker compose config, run container inspection). For each "Must NOT Have": search codebase and container for forbidden patterns — reject with file:line if found. Check evidence files exist in `.sisyphus/evidence/`. Compare deliverables against plan.
  Output: `Must Have [N/N] | Must NOT Have [N/N] | Tasks [N/N] | VERDICT: APPROVE/REJECT`

- [ ] F2. **Code Quality Review** — `unspecified-high`
  Run `docker compose build --no-cache`. Review all changed files for: hardcoded secrets in commits, incorrect Nginx syntax, Dockerfile anti-patterns (missing cleanup, multi-stage mistakes), YAML indent errors. Check AI slop: excessive comments, over-engineering, unnecessary abstractions.
  Output: `Build [PASS/FAIL] | Nginx [VALID/INVALID] | Dockerfile [CLEAN/N issues] | VERDICT`

- [ ] F3. **Real Manual QA** — `unspecified-high`
  Start from clean state (`docker compose down -v`). Execute EVERY QA scenario from EVERY task — follow exact steps, capture evidence. Test cross-task integration: Opcache + FPM + X-Accel all working together under concurrent load. Test edge cases: zero-byte file via X-Accel, Range bytes=-500, HEAD request for media, unicode filenames. Save to `.sisyphus/evidence/final-qa/`.
  Output: `Scenarios [N/N pass] | Integration [N/N] | Edge Cases [N tested] | VERDICT`

- [ ] F4. **Scope Fidelity Check** — `deep`
  For each task: read "What to do", read actual diff (git log/diff). Verify 1:1 — everything in spec was built (no missing), nothing beyond spec was built (no creep). Check "Must NOT do" compliance. Detect cross-task contamination: Task N touching Task M's files. Flag unaccounted changes. Verify no S3/CDN/TLS/monitoring/transcoding code was added.
  Output: `Tasks [N/N compliant] | Contamination [CLEAN/N issues] | Unaccounted [CLEAN/N files] | VERDICT`

---

## Commit Strategy

- **1**: `fix(docker): add Opcache extension and tuned ini` — `docker/php/Dockerfile`, `docker/php/opcache.ini`
- **2**: `fix(docker): production-safe environment defaults` — `.env.docker`
- **3**: `fix(docker): tune PHP-FPM pool for media workload` — `docker/php/zz-docker.conf`, `docker/php/Dockerfile`
- **4**: `refactor(docker): simplify entrypoint to runtime-only concerns` — `docker/php/entrypoint.sh`
- **5**: `refactor(docker): multi-stage build separating build from runtime` — `docker/php/Dockerfile`
- **6**: `feat(docker): health checks, resource limits, and container security` — `docker-compose.yml`
- **7**: `feat(docker): X-Accel media serving and Nginx performance tuning` — `docker/nginx/default.conf`
- **8**: `feat(docker): dedicated scheduler container` — `docker-compose.yml`
- **9**: `feat: X-Accel-Redirect media serving with non-Docker fallback` — `app/Services/FileStorageResponseService.php`
- **10**: `fix: verify health route in bootstrap/app.php` — `bootstrap/app.php`
- **11**: `test(docker): full end-to-end deployment verification` — Evidence files only

> **Commits 3 and 5 both touch `docker/php/Dockerfile`** — Task 3 adds one COPY line, Task 5 does full refactor. These should be separate commits. If Task 5 is applied first, Task 3's COPY line is included in the refactored Dockerfile. If applied in order (3 then 5), Task 5 will conflict — the executor should handle the merge.

---

## Success Criteria

### Verification Commands
```bash
# Build and start entire stack
docker compose build --no-cache && docker compose up -d

# Wait for health checks (poll until all healthy, max 120s)
for i in $(seq 1 24); do
  unhealthy=$(docker compose ps | grep -c 'unhealthy\|starting' || true)
  [ "$unhealthy" -eq 0 ] && break
  sleep 5
done

# All containers healthy
docker compose ps | grep -c healthy  # Expected: 8

# Health endpoint
curl -fsS http://localhost:8080/up  # Expected: 200

# Opcache enabled
docker compose exec php php -m | grep -i opcache  # Expected: Zend OPcache

# Debug off at runtime
docker compose exec php php artisan tinker --execute="echo config('app.debug')?'debug':'no-debug';"
# Expected: no-debug

# Environment is production
docker compose exec php php artisan tinker --execute="echo app()->environment();"
# Expected: production

# Horizon running
docker compose exec horizon php artisan horizon:status  # Expected: running

# Build tools absent from runtime
docker compose exec php which node  # Expected: exit 1
docker compose exec php which npm   # Expected: exit 1

# Runtime tools present
docker compose exec php which ffmpeg  # Expected: /usr/bin/ffmpeg
docker compose exec php which yt-dlp  # Expected: /usr/bin/yt-dlp

# Running as non-root
docker compose exec php whoami  # Expected: www-data

# FPM pool config
docker compose exec php cat /usr/local/etc/php-fpm.d/zz-docker.conf | grep max_children
# Expected: pm.max_children = 16

# Browse API working (Typesense connected)
curl -fsS http://localhost:8080/api/browse?feed=local  # Expected: 200

# Media X-Accel serving
curl -sI -H "Range: bytes=0-99" http://localhost:8080/api/files/1/serve
# Expected: 206 Partial Content, Content-Range: bytes 0-99/...

# Internal _media_ blocked
curl -o /dev/null -w "%{http_code}" http://localhost:8080/_media/test.jpg
# Expected: 404

# Scheduler running as singleton
docker compose exec scheduler pgrep -f "artisan schedule:work" | wc -l
# Expected: 1
```

### Final Checklist
- [ ] All "Must Have" present (Opcache, health checks, APP_DEBUG=false, X-Accel, resource limits, FPM tuning, multi-stage, runtime-only entrypoint)
- [ ] All "Must NOT Have" absent (no public media exposure, no build tools in runtime, no S3/CDN/TLS/monitoring, no secrets in env files, no multi-scheduler)
- [ ] `docker compose build --no-cache` succeeds
- [ ] All 8 containers report healthy
- [ ] Media serving works via X-Accel with correct byte ranges
- [ ] Direct /_media/ access returns 404
- [ ] Non-Docker PHP streaming fallback preserved
- [ ] Browse API functional
- [ ] All evidence files captured in `.sisyphus/evidence/`

---

## Commit Strategy

- **1**: `fix(docker): add Opcache extension and tuned ini` — docker/php/Dockerfile, docker/php/opcache.ini
- **2**: `fix(docker): production-safe environment defaults` — .env.docker
- **3**: `fix(docker): tune PHP-FPM pool for media workload` — docker/php/zz-docker.conf
- **4**: `refactor(docker): simplify entrypoint to runtime-only concerns` — docker/php/entrypoint.sh
- **5**: `refactor(docker): multi-stage build separating build from runtime` — docker/php/Dockerfile
- **6**: `feat(docker): health checks, resource limits, and container security` — docker-compose.yml
- **7**: `feat(docker): X-Accel media serving and Nginx performance tuning` — docker/nginx/default.conf
- **8**: `feat(docker): dedicated scheduler container` — docker-compose.yml
- **9**: `feat: X-Accel-Redirect media serving with non-Docker fallback` — app/Services/FileStorageResponseService.php
- **10**: `fix: verify health route in bootstrap/app.php` — bootstrap/app.php

---

## Success Criteria

### Verification Commands
```bash
# Build and start
docker compose build --no-cache && docker compose up -d

# All containers healthy (wait 30s)
docker compose ps | grep -E 'unhealthy|starting'

# Health endpoint
curl -fsS http://localhost:8080/up

# Opcache enabled
docker compose exec php php -m | grep -i opcache

# Debug off
docker compose exec php php artisan tinker --execute="echo config('app.debug')?'debug':'no-debug';"

# Horizon running
docker compose exec horizon php artisan horizon:status

# Browse working (Typesense available)
curl -fsS http://localhost:8080/api/browse?feed=local

# Media X-Accel serving
curl -fsS -H "Range: bytes=0-99" http://localhost:8080/api/files/1/serve

# Internal media blocked
curl -fsS -o /dev/null -w "%{http_code}" http://localhost:8080/_media/
```

### Final Checklist
- [ ] All "Must Have" present
- [ ] All "Must NOT Have" absent
- [ ] `docker compose build --no-cache` succeeds
- [ ] All 8 containers report healthy
- [ ] Media serving works via X-Accel with correct byte ranges
- [ ] Direct /_media/ access blocked
- [ ] Non-Docker PHP streaming fallback preserved
