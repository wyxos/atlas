# Docker Setup for Atlas

## TL;DR

> **Quick Summary**: Set up Atlas as a standalone Docker-deployed application with all required services (MariaDB, Redis, Typesense, Reverb, Horizon, Nginx, phpMyAdmin) using Laravel Sail for local development.
> 
> **Deliverables**:
> - `docker-compose.yml` with all services
> - `docker/` directory with configuration files
> - `.env.docker` configuration file
> - `docker-setup.sh` initialization script
> - Updated documentation
> 
> **Estimated Effort**: Medium
> **Parallel Execution**: YES - 3 waves
> **Critical Path**: Task 1 → Task 2 → Task 3 → Task 4 → Task 5 → Task 6

---

## Context

### Original Request
Set up Atlas as a standalone Docker-deployed server & web client app for local development. The app will eventually be deployed on cloudhome server, but for now we need a Docker image standalone build running on the local machine.

### Interview Summary
**Key Discussions**:
- **Database**: MariaDB (not SQLite) for better concurrency and production parity
- **Search**: Typesense container included for full search functionality
- **WebSockets**: Laravel Reverb container for real-time updates
- **Queue Monitoring**: Horizon container for queue dashboard
- **FFmpeg**: Install in PHP container for media processing
- **Frontend**: Nginx serving built Vue assets (production-like)
- **Compose Structure**: Single docker-compose.yml for simplicity
- **Storage**: Named Docker volumes for data persistence
- **DB Admin**: phpMyAdmin for database management
- **App URL**: localhost:8080 (no custom domain setup)
- **Docker Approach**: Laravel Sail (official tooling)

**Research Findings**:
- Project uses Laravel 12, PHP 8.4, Vue 3, Vite, Tailwind CSS 4
- Current .env.example shows SQLite default, but supports MySQL/Postgres
- Uses Horizon for queue management, Reverb for WebSockets
- Requires FFmpeg for video previews/thumbnails
- Uses Typesense for search (Scout driver)
- Has Sentry integration for error tracking
- Laravel Sail already in dev dependencies

### Self-Review (Metis unavailable)
**Identified Gaps** (addressed):
- **PHP Extensions**: Need to ensure all required PHP extensions are installed (pdo_mysql, redis, etc.)
- **Node.js Version**: Need to specify Node.js version for frontend build
- **Health Checks**: Need health checks for service dependencies
- **Volume Permissions**: Need to handle file permissions for storage volumes
- **Environment Separation**: Need clear separation between local dev and Docker configs

---

## Work Objectives

### Core Objective
Create a complete Docker development environment for Atlas that includes all required services and can be started with a single command.

### Concrete Deliverables
- `docker-compose.yml` with all services configured
- `docker/php/Dockerfile` for PHP with FFmpeg and required extensions
- `docker/nginx/default.conf` for Nginx configuration
- `.env.docker` with Docker-specific environment variables
- `docker-setup.sh` script for initial setup
- Updated `README.md` with Docker instructions

### Definition of Done
- [ ] `docker-compose up -d` starts all services
- [ ] Application accessible at http://localhost:8080
- [ ] All services healthy (MariaDB, Redis, Typesense, Reverb, Horizon)
- [ ] Frontend assets served correctly via Nginx
- [ ] phpMyAdmin accessible at http://localhost:8081
- [ ] Queue processing working via Horizon
- [ ] Search functionality working via Typesense
- [ ] WebSocket connections working via Reverb

### Must Have
- All services in single docker-compose.yml
- PHP container with FFmpeg and all required extensions
- Nginx serving built Vue assets
- Named volumes for data persistence
- Health checks for service dependencies
- Clear setup instructions

### Must NOT Have (Guardrails)
- No SSL/TLS configuration (local dev only)
- No custom domain setup (use localhost:8080)
- No production deployment configuration
- No CI/CD pipeline integration
- No browser extension Docker setup
- No Sentry configuration (keep existing .env setup)

---

## Verification Strategy (MANDATORY)

> **ZERO HUMAN INTERVENTION** - ALL verification is agent-executed. No exceptions.

### Test Decision
- **Infrastructure exists**: YES (Pest for backend, Vitest for frontend)
- **Automated tests**: Tests-after (Docker setup doesn't require TDD)
- **Framework**: Pest (backend), Vitest (frontend)
- **Verification**: Manual testing of Docker services

### QA Policy
Every task MUST include agent-executed QA scenarios.
Evidence saved to `.sisyphus/evidence/task-{N}-{scenario-slug}.{ext}`.

- **Docker Services**: Use Bash (docker commands) - Start services, check health, verify connectivity
- **Web Application**: Use Playwright - Navigate, interact, verify functionality
- **API Endpoints**: Use Bash (curl) - Send requests, verify responses

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Start Immediately - foundation):
├── Task 1: Create PHP Dockerfile [quick]
├── Task 2: Create Nginx configuration [quick]
└── Task 3: Create .env.docker file [quick]

Wave 2 (After Wave 1 - core services):
├── Task 4: Create docker-compose.yml (depends: 1, 2, 3) [unspecified-high]
└── Task 5: Create docker-setup.sh script (depends: 4) [quick]

Wave 3 (After Wave 2 - verification & documentation):
├── Task 6: Verify Docker setup works (depends: 4, 5) [unspecified-high]
└── Task 7: Update documentation (depends: 6) [quick]

Wave FINAL (After ALL tasks — parallel reviews):
├── Task F1: Plan compliance audit (oracle)
├── Task F2: Code quality review (unspecified-high)
├── Task F3: Real manual QA (unspecified-high)
└── Task F4: Scope fidelity check (deep)
-> Present results -> Get explicit user okay

Critical Path: Task 1 → Task 4 → Task 5 → Task 6 → F1-F4 → user okay
Parallel Speedup: ~50% faster than sequential
Max Concurrent: 3 (Wave 1)
```

### Dependency Matrix

- **1**: - - 4
- **2**: - - 4
- **3**: - - 4
- **4**: 1, 2, 3 - 5, 6
- **5**: 4 - 6
- **6**: 4, 5 - 7
- **7**: 6 - F1-F4

### Agent Dispatch Summary

- **Wave 1**: **3** - T1 → `quick`, T2 → `quick`, T3 → `quick`
- **Wave 2**: **2** - T4 → `unspecified-high`, T5 → `quick`
- **Wave 3**: **2** - T6 → `unspecified-high`, T7 → `quick`
- **FINAL**: **4** - F1 → `oracle`, F2 → `unspecified-high`, F3 → `unspecified-high`, F4 → `deep`

---

## TODOs

- [x] 1. Create PHP Dockerfile

  **What to do**:
  - Create `docker/php/Dockerfile` based on `php:8.4-fpm`
  - Install required PHP extensions: `pdo_mysql`, `redis`, `bcmath`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `imagick`
  - Install FFmpeg and yt-dlp
  - Install Node.js 20 and npm for frontend build
  - Configure PHP settings for Laravel
  - Set up proper file permissions

  **Must NOT do**:
  - No Xdebug or development tools (keep image lean)
  - No production optimizations (OPcache, etc.)

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `quick`
    - Reason: Single file creation with clear requirements
  - **Skills**: []
    - No specialized skills needed for Dockerfile creation
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 2, 3)
  - **Blocks**: Task 4 (docker-compose.yml)
  - **Blocked By**: None (can start immediately)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `composer.json` - PHP version requirement (^8.4)
  - `.env.example` - Required services and configuration

  **API/Type References** (contracts to implement against):
  - None - Dockerfile creation doesn't require API references

  **Test References** (testing patterns to follow):
  - None - Dockerfile testing is manual

  **External References** (libraries and frameworks):
  - Official PHP Docker image: `https://hub.docker.com/_/php`
  - Laravel Sail Dockerfile: `https://github.com/laravel/sail/tree/master/runtimes`

  **WHY Each Reference Matters** (explain the relevance):
  - `composer.json`: Ensures PHP version matches project requirements
  - `.env.example`: Shows all required services and configuration variables
  - Laravel Sail Dockerfile: Reference for PHP extensions and configuration

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: PHP container builds successfully
    Tool: Bash
    Preconditions: Docker installed and running
    Steps:
      1. Run: docker build -t atlas-php docker/php/
      2. Check exit code is 0
      3. Run: docker run --rm atlas-php php -v
      4. Verify PHP version is 8.4.x
    Expected Result: Container builds and runs with correct PHP version
    Failure Indicators: Build errors, wrong PHP version
    Evidence: .sisyphus/evidence/task-1-php-build.txt

  Scenario: FFmpeg is available in container
    Tool: Bash
    Preconditions: PHP container built
    Steps:
      1. Run: docker run --rm atlas-php ffmpeg -version
      2. Check exit code is 0
      3. Verify FFmpeg version information is displayed
    Expected Result: FFmpeg is installed and accessible
    Failure Indicators: FFmpeg not found, version mismatch
    Evidence: .sisyphus/evidence/task-1-ffmpeg-version.txt

  Scenario: Required PHP extensions are installed
    Tool: Bash
    Preconditions: PHP container built
    Steps:
      1. Run: docker run --rm atlas-php php -m
      2. Check output contains: pdo_mysql, redis, bcmath, mbstring, xml, curl, zip, gd, imagick
    Expected Result: All required extensions are listed
    Failure Indicators: Missing extensions
    Evidence: .sisyphus/evidence/task-1-php-extensions.txt
  ```

  **Commit**: YES
  - Message: `feat(docker): add PHP Dockerfile with FFmpeg and required extensions`
  - Files: `docker/php/Dockerfile`
  - Pre-commit: `docker build -t atlas-php docker/php/`

---

- [x] 2. Create Nginx configuration

  **What to do**:
  - Create `docker/nginx/default.conf` for serving Laravel and Vue assets
  - Configure proper location blocks for API, static assets, and SPA routing
  - Set up PHP-FPM upstream configuration
  - Configure proper headers for CORS and security
  - Set up logging configuration

  **Must NOT do**:
  - No SSL configuration (local dev only)
  - No custom domain configuration (use localhost:8080)

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `quick`
    - Reason: Single configuration file with clear requirements
  - **Skills**: []
    - No specialized skills needed for Nginx configuration
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 3)
  - **Blocks**: Task 4 (docker-compose.yml)
  - **Blocked By**: None (can start immediately)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `vite.config.js` - Vite configuration for asset paths
  - `resources/js/app.ts` - Vue app entry point

  **API/Type References** (contracts to implement against):
  - None - Nginx configuration doesn't require API references

  **Test References** (testing patterns to follow):
  - None - Nginx configuration testing is manual

  **External References** (libraries and frameworks):
  - Laravel Nginx configuration: `https://laravel.com/docs/12.x/deployment#nginx`
  - Nginx documentation: `https://nginx.org/en/docs/`

  **WHY Each Reference Matters** (explain the relevance):
  - `vite.config.js`: Shows asset paths and build configuration
  - Laravel Nginx docs: Official recommended configuration

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: Nginx configuration is valid
    Tool: Bash
    Preconditions: Nginx container built
    Steps:
      1. Run: docker run --rm -v $(pwd)/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf nginx:alpine nginx -t
      2. Check exit code is 0
    Expected Result: Configuration syntax is valid
    Failure Indicators: Configuration errors
    Evidence: .sisyphus/evidence/task-2-nginx-valid.txt

  Scenario: Nginx serves static files
    Tool: Bash
    Preconditions: Nginx container running
    Steps:
      1. Create test file: echo "test" > public/test.txt
      2. Run: curl http://localhost:8080/test.txt
      3. Verify response contains "test"
      4. Clean up: rm public/test.txt
    Expected Result: Static files are served correctly
    Failure Indicators: 404 errors, wrong content
    Evidence: .sisyphus/evidence/task-2-nginx-static.txt
  ```

  **Commit**: YES
  - Message: `feat(docker): add Nginx configuration for serving Laravel and Vue assets`
  - Files: `docker/nginx/default.conf`
  - Pre-commit: `docker run --rm -v $(pwd)/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf nginx:alpine nginx -t`

---

- [x] 3. Create .env.docker file

  **What to do**:
  - Create `.env.docker` with Docker-specific environment variables
  - Configure MariaDB connection settings
  - Configure Redis connection settings
  - Configure Typesense connection settings
  - Configure Reverb connection settings
  - Set proper APP_URL for Docker environment
  - Configure queue and cache settings

  **Must NOT do**:
  - No production secrets or API keys
  - No Sentry configuration (keep existing .env setup)

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `quick`
    - Reason: Single configuration file with clear requirements
  - **Skills**: []
    - No specialized skills needed for environment configuration
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2)
  - **Blocks**: Task 4 (docker-compose.yml)
  - **Blocked By**: None (can start immediately)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `.env.example` - Current environment configuration
  - `config/database.php` - Database configuration
  - `config/redis.php` - Redis configuration

  **API/Type References** (contracts to implement against):
  - None - Environment configuration doesn't require API references

  **Test References** (testing patterns to follow):
  - None - Environment configuration testing is manual

  **External References** (libraries and frameworks):
  - Laravel configuration: `https://laravel.com/docs/12.x/configuration`

  **WHY Each Reference Matters** (explain the relevance):
  - `.env.example`: Shows all required configuration variables
  - `config/database.php`: Shows database configuration structure
  - `config/redis.php`: Shows Redis configuration structure

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: .env.docker file exists and is valid
    Tool: Bash
    Preconditions: None
    Steps:
      1. Run: test -f .env.docker && echo "File exists"
      2. Run: grep -q "DB_CONNECTION=mariadb" .env.docker && echo "MariaDB configured"
      3. Run: grep -q "REDIS_HOST=redis" .env.docker && echo "Redis configured"
      4. Run: grep -q "TYPESENSE_HOST=typesense" .env.docker && echo "Typesense configured"
    Expected Result: All required services are configured
    Failure Indicators: Missing configuration, wrong hostnames
    Evidence: .sisyphus/evidence/task-3-env-docker.txt

  Scenario: Environment variables are properly formatted
    Tool: Bash
    Preconditions: .env.docker file exists
    Steps:
      1. Run: grep -E "^[A-Z_]+=" .env.docker | wc -l
      2. Verify count is greater than 20 (minimum required variables)
      3. Run: grep -E "^[A-Z_]+=" .env.docker | grep -E "=.*" | wc -l
      4. Verify all variables have values
    Expected Result: All variables are properly formatted with values
    Failure Indicators: Empty variables, malformed lines
    Evidence: .sisyphus/evidence/task-3-env-format.txt
  ```

  **Commit**: YES
  - Message: `feat(docker): add Docker environment configuration`
  - Files: `.env.docker`
  - Pre-commit: `test -f .env.docker`

---

- [x] 4. Create docker-compose.yml

  **What to do**:
  - Create `docker-compose.yml` with all required services
  - Configure PHP service using custom Dockerfile
  - Configure Nginx service with proper volume mounts
  - Configure MariaDB service with health check
  - Configure Redis service with health check
  - Configure Typesense service with health check
  - Configure Reverb service
  - Configure Horizon service
  - Configure phpMyAdmin service
  - Set up named volumes for data persistence
  - Configure proper networking between services

  **Must NOT do**:
  - No production configuration
  - No custom domain setup

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `unspecified-high`
    - Reason: Complex configuration with multiple services and dependencies
  - **Skills**: []
    - No specialized skills needed for Docker Compose configuration
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (after Wave 1)
  - **Blocks**: Task 5 (docker-setup.sh), Task 6 (verification)
  - **Blocked By**: Tasks 1, 2, 3 (Dockerfile, Nginx config, .env.docker)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `docker/php/Dockerfile` - PHP container configuration
  - `docker/nginx/default.conf` - Nginx configuration
  - `.env.docker` - Environment variables

  **API/Type References** (contracts to implement against):
  - None - Docker Compose configuration doesn't require API references

  **Test References** (testing patterns to follow):
  - None - Docker Compose configuration testing is manual

  **External References** (libraries and frameworks):
  - Docker Compose documentation: `https://docs.docker.com/compose/`
  - Laravel Sail: `https://github.com/laravel/sail`

  **WHY Each Reference Matters** (explain the relevance):
  - `docker/php/Dockerfile`: Defines PHP service image
  - `docker/nginx/default.conf`: Defines Nginx service configuration
  - `.env.docker`: Defines environment variables for services

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: Docker Compose file is valid
    Tool: Bash
    Preconditions: Docker Compose installed
    Steps:
      1. Run: docker-compose config
      2. Check exit code is 0
      3. Verify output contains all services: php, nginx, mariadb, redis, typesense, reverb, horizon, phpmyadmin
    Expected Result: Configuration is valid and contains all services
    Failure Indicators: Configuration errors, missing services
    Evidence: .sisyphus/evidence/task-4-compose-valid.txt

  Scenario: All services can be started
    Tool: Bash
    Preconditions: Docker Compose file valid
    Steps:
      1. Run: docker-compose up -d
      2. Wait 30 seconds for services to start
      3. Run: docker-compose ps
      4. Verify all services are running (status: Up)
    Expected Result: All services start successfully
    Failure Indicators: Services failing to start, dependency issues
    Evidence: .sisyphus/evidence/task-4-compose-start.txt

  Scenario: Services have health checks
    Tool: Bash
    Preconditions: Services running
    Steps:
      1. Run: docker-compose ps
      2. Verify MariaDB, Redis, and Typesense have health status
      3. Run: docker inspect --format='{{.State.Health.Status}}' atlas-mariadb
      4. Verify health status is "healthy"
    Expected Result: Services have proper health checks
    Failure Indicators: Missing health checks, unhealthy status
    Evidence: .sisyphus/evidence/task-4-compose-health.txt
  ```

  **Commit**: YES
  - Message: `feat(docker): add Docker Compose configuration with all services`
  - Files: `docker-compose.yml`
  - Pre-commit: `docker-compose config`

---

- [x] 5. Create docker-setup.sh script

  **What to do**:
  - Create `docker-setup.sh` script for initial setup
  - Copy .env.docker to .env if not exists
  - Generate application key
  - Run database migrations
  - Create admin user
  - Build frontend assets
  - Start all services
  - Display access information

  **Must NOT do**:
  - No production setup
  - No SSL configuration

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `quick`
    - Reason: Single script with clear requirements
  - **Skills**: []
    - No specialized skills needed for shell script creation
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (after Task 4)
  - **Blocks**: Task 6 (verification)
  - **Blocked By**: Task 4 (docker-compose.yml)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `composer.json` - Setup script in composer scripts
  - `docs/SETUP.md` - Manual setup instructions

  **API/Type References** (contracts to implement against):
  - None - Shell script doesn't require API references

  **Test References** (testing patterns to follow):
  - None - Shell script testing is manual

  **External References** (libraries and frameworks):
  - Laravel Artisan commands: `https://laravel.com/docs/12.x/artisan`

  **WHY Each Reference Matters** (explain the relevance):
  - `composer.json`: Shows existing setup script pattern
  - `docs/SETUP.md`: Shows manual setup steps to automate

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: Setup script is executable
    Tool: Bash
    Preconditions: docker-setup.sh exists
    Steps:
      1. Run: test -x docker-setup.sh && echo "Script is executable"
      2. Run: head -1 docker-setup.sh | grep -q "#!/bin/bash" && echo "Has shebang"
    Expected Result: Script is executable and has proper shebang
    Failure Indicators: Not executable, missing shebang
    Evidence: .sisyphus/evidence/task-5-script-executable.txt

  Scenario: Setup script runs successfully
    Tool: Bash
    Preconditions: Docker services running
    Steps:
      1. Run: ./docker-setup.sh
      2. Wait for script to complete
      3. Check exit code is 0
      4. Verify .env file exists
      5. Verify application key is set
    Expected Result: Setup completes successfully
    Failure Indicators: Script errors, missing files
    Evidence: .sisyphus/evidence/task-5-script-run.txt

  Scenario: Setup script is idempotent
    Tool: Bash
    Preconditions: Setup already run once
    Steps:
      1. Run: ./docker-setup.sh
      2. Wait for script to complete
      3. Check exit code is 0
      4. Verify no errors about existing files
    Expected Result: Script can be run multiple times safely
    Failure Indicators: Errors about existing files, duplicate data
    Evidence: .sisyphus/evidence/task-5-script-idempotent.txt
  ```

  **Commit**: YES
  - Message: `feat(docker): add Docker setup script for initial configuration`
  - Files: `docker-setup.sh`
  - Pre-commit: `test -x docker-setup.sh`

---

- [x] 6. Verify Docker setup works

  **What to do**:
  - Run complete Docker setup from scratch
  - Verify all services start and are healthy
  - Verify application is accessible at http://localhost:8080
  - Verify phpMyAdmin is accessible at http://localhost:8081
  - Verify frontend assets are served correctly
  - Verify API endpoints respond correctly
  - Verify queue processing works
  - Verify search functionality works
  - Verify WebSocket connections work

  **Must NOT do**:
  - No production testing
  - No performance testing

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `unspecified-high`
    - Reason: Complex verification with multiple services and integrations
  - **Skills**: []
    - No specialized skills needed for Docker verification
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (after Task 5)
  - **Blocks**: Task 7 (documentation), F1-F4 (final verification)
  - **Blocked By**: Tasks 4, 5 (docker-compose.yml, setup script)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `docker-compose.yml` - Service configuration
  - `docker-setup.sh` - Setup script

  **API/Type References** (contracts to implement against):
  - `routes/web.php` - Web routes
  - `routes/api.php` - API routes (if exists)

  **Test References** (testing patterns to follow):
  - `tests/Feature/` - Feature tests for API endpoints

  **External References** (libraries and frameworks):
  - Docker documentation: `https://docs.docker.com/`
  - Laravel documentation: `https://laravel.com/docs/12.x`

  **WHY Each Reference Matters** (explain the relevance):
  - `docker-compose.yml`: Defines expected service configuration
  - `routes/web.php`: Defines expected web routes
  - `tests/Feature/`: Shows expected API behavior

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: All services are running
    Tool: Bash
    Preconditions: Docker setup completed
    Steps:
      1. Run: docker-compose ps
      2. Verify all services show "Up" status
      3. Verify MariaDB health is "healthy"
      4. Verify Redis health is "healthy"
      5. Verify Typesense health is "healthy"
    Expected Result: All services are running and healthy
    Failure Indicators: Services down, unhealthy status
    Evidence: .sisyphus/evidence/task-6-services-running.txt

  Scenario: Application is accessible
    Tool: Bash
    Preconditions: Services running
    Steps:
      1. Run: curl -s -o /dev/null -w "%{http_code}" http://localhost:8080
      2. Verify response code is 200 or 302 (redirect to login)
      3. Run: curl -s http://localhost:8080 | grep -q "Atlas" && echo "App title found"
    Expected Result: Application responds and contains Atlas branding
    Failure Indicators: Connection refused, wrong content
    Evidence: .sisyphus/evidence/task-6-app-accessible.txt

  Scenario: phpMyAdmin is accessible
    Tool: Bash
    Preconditions: Services running
    Steps:
      1. Run: curl -s -o /dev/null -w "%{http_code}" http://localhost:8081
      2. Verify response code is 200
      3. Run: curl -s http://localhost:8081 | grep -q "phpMyAdmin" && echo "phpMyAdmin found"
    Expected Result: phpMyAdmin responds and is accessible
    Failure Indicators: Connection refused, wrong content
    Evidence: .sisyphus/evidence/task-6-phpmyadmin-accessible.txt

  Scenario: API endpoints respond correctly
    Tool: Bash
    Preconditions: Application accessible
    Steps:
      1. Run: curl -s http://localhost:8080/api/health
      2. Verify response contains "status" or similar health indicator
      3. Run: curl -s http://localhost:8080/login
      4. Verify response contains login form
    Expected Result: API and web routes respond correctly
    Failure Indicators: 404 errors, wrong responses
    Evidence: .sisyphus/evidence/task-6-api-responds.txt

  Scenario: Frontend assets are served
    Tool: Bash
    Preconditions: Application accessible
    Steps:
      1. Run: curl -s http://localhost:8080 | grep -q "app.ts" && echo "Vite assets found"
      2. Run: curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/build/assets/app.js
      3. Verify response code is 200
    Expected Result: Frontend assets are built and served
    Failure Indicators: Missing assets, 404 errors
    Evidence: .sisyphus/evidence/task-6-frontend-assets.txt
  ```

  **Commit**: NO (verification only)

---

- [x] 7. Update documentation

  **What to do**:
  - Update `README.md` with Docker setup instructions
  - Add Docker section to `docs/SETUP.md`
  - Document all services and their ports
  - Document volume mounts and data persistence
  - Document troubleshooting common issues
  - Document how to access logs and debug services

  **Must NOT do**:
  - No production deployment documentation
  - No SSL configuration documentation

  **Recommended Agent Profile**:
  > Select category + skills based on task domain. Justify each choice.
  - **Category**: `quick`
    - Reason: Documentation updates with clear requirements
  - **Skills**: []
    - No specialized skills needed for documentation
  - **Skills Evaluated but Omitted**:
    - None evaluated

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (after Task 6)
  - **Blocks**: F1-F4 (final verification)
  - **Blocked By**: Task 6 (verification)

  **References** (CRITICAL - Be Exhaustive):

  **Pattern References** (existing code to follow):
  - `README.md` - Current README structure
  - `docs/SETUP.md` - Current setup documentation

  **API/Type References** (contracts to implement against):
  - None - Documentation doesn't require API references

  **Test References** (testing patterns to follow):
  - None - Documentation testing is manual

  **External References** (libraries and frameworks):
  - Markdown documentation: `https://www.markdownguide.org/`

  **WHY Each Reference Matters** (explain the relevance):
  - `README.md`: Shows current documentation structure
  - `docs/SETUP.md`: Shows current setup documentation format

  **Acceptance Criteria**:

  **QA Scenarios (MANDATORY):**

  ```
  Scenario: README contains Docker section
    Tool: Bash
    Preconditions: Documentation updated
    Steps:
      1. Run: grep -q "Docker" README.md && echo "Docker section found"
      2. Run: grep -q "docker-compose" README.md && echo "Docker commands found"
      3. Run: grep -q "localhost:8080" README.md && echo "Access URL found"
    Expected Result: README contains Docker setup instructions
    Failure Indicators: Missing Docker section, wrong URLs
    Evidence: .sisyphus/evidence/task-7-readme-docker.txt

  Scenario: SETUP.md contains Docker instructions
    Tool: Bash
    Preconditions: Documentation updated
    Steps:
      1. Run: grep -q "Docker" docs/SETUP.md && echo "Docker section found"
      2. Run: grep -q "docker-compose" docs/SETUP.md && echo "Docker commands found"
      3. Run: grep -q "docker-setup.sh" docs/SETUP.md && echo "Setup script found"
    Expected Result: SETUP.md contains Docker setup instructions
    Failure Indicators: Missing Docker section, wrong commands
    Evidence: .sisyphus/evidence/task-7-setup-docker.txt

  Scenario: Documentation is comprehensive
    Tool: Bash
    Preconditions: Documentation updated
    Steps:
      1. Run: grep -c "##" README.md
      2. Verify count is greater than 5 (multiple sections)
      3. Run: grep -c "##" docs/SETUP.md
      4. Verify count is greater than 3 (multiple sections)
    Expected Result: Documentation has multiple sections
    Failure Indicators: Too few sections, incomplete documentation
    Evidence: .sisyphus/evidence/task-7-doc-sections.txt
  ```

  **Commit**: YES
  - Message: `docs: add Docker setup instructions to README and SETUP.md`
  - Files: `README.md`, `docs/SETUP.md`
  - Pre-commit: `grep -q "Docker" README.md`

---

## Final Verification Wave (MANDATORY — after ALL implementation tasks)

> 4 review agents run in PARALLEL. ALL must APPROVE. Present consolidated results to user and get explicit "okay" before completing.

- [x] F1. **Plan Compliance Audit** — `oracle`
  Read the plan end-to-end. For each "Must Have": verify implementation exists (read file, curl endpoint, run command). For each "Must NOT Have": search codebase for forbidden patterns — reject with file:line if found. Check evidence files exist in .sisyphus/evidence/. Compare deliverables against plan.
  Output: `Must Have [N/N] | Must NOT Have [N/N] | Tasks [N/N] | VERDICT: APPROVE/REJECT`

- [x] F2. **Code Quality Review** — `unspecified-high`
  Run `docker-compose config` to verify configuration. Review all changed files for: syntax errors, proper formatting, consistent style. Check for security issues: exposed ports, hardcoded credentials, missing health checks.
  Output: `Config [PASS/FAIL] | Files [N clean/N issues] | VERDICT`

- [x] F3. **Real Manual QA** — `unspecified-high`
  Start from clean state. Execute EVERY QA scenario from EVERY task — follow exact steps, capture evidence. Test cross-task integration (services working together, not isolation). Test edge cases: service failures, network issues, volume permissions. Save to `.sisyphus/evidence/final-qa/`.
  Output: `Scenarios [N/N pass] | Integration [N/N] | Edge Cases [N tested] | VERDICT`

- [x] F4. **Scope Fidelity Check** — `deep`
  For each task: read "What to do", read actual diff (git log/diff). Verify 1:1 — everything in spec was built (no missing), nothing beyond spec was built (no creep). Check "Must NOT do" compliance. Detect cross-task contamination: Task N touching Task M's files. Flag unaccounted changes.
  Output: `Tasks [N/N compliant] | Contamination [CLEAN/N issues] | Unaccounted [CLEAN/N files] | VERDICT`

---

## Commit Strategy

- **Task 1**: `feat(docker): add PHP Dockerfile with FFmpeg and required extensions` - `docker/php/Dockerfile`
- **Task 2**: `feat(docker): add Nginx configuration for serving Laravel and Vue assets` - `docker/nginx/default.conf`
- **Task 3**: `feat(docker): add Docker environment configuration` - `.env.docker`
- **Task 4**: `feat(docker): add Docker Compose configuration with all services` - `docker-compose.yml`
- **Task 5**: `feat(docker): add Docker setup script for initial configuration` - `docker-setup.sh`
- **Task 7**: `docs: add Docker setup instructions to README and SETUP.md` - `README.md`, `docs/SETUP.md`

---

## Success Criteria

### Verification Commands
```bash
docker-compose config  # Expected: Valid configuration with all services
docker-compose up -d   # Expected: All services start successfully
docker-compose ps      # Expected: All services show "Up" status
curl http://localhost:8080  # Expected: Application accessible
curl http://localhost:8081  # Expected: phpMyAdmin accessible
```

### Final Checklist
- [ ] All "Must Have" present
- [ ] All "Must NOT Have" absent
- [ ] All services running and healthy
- [ ] Application accessible at http://localhost:8080
- [ ] phpMyAdmin accessible at http://localhost:8081
- [ ] Documentation updated
- [ ] All QA scenarios pass
