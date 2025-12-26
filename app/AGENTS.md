# Laravel Backend - Agent Guidelines

## Package Identity

**Purpose**: Laravel 12 backend API and application logic  
**Framework**: Laravel 12 (PHP 8.4.15)  
**Architecture**: MVC with Service layer, Form Requests for validation, Policies for authorization

---

## Setup & Run

### Commands
```bash
# Run migrations
php artisan migrate

# Run seeders
php artisan db:seed

# Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Format code
vendor/bin/pint --dirty

# Run tests
php artisan test
php artisan test --filter=FeatureName
```

---

## Patterns & Conventions

### File Organization

**Controllers** (`app/Http/Controllers/`)
- ✅ DO: Keep controllers thin, delegate to Services
- ✅ DO: Use Form Requests for validation (see `app/Http/Requests/`)
- ✅ DO: Return API Resources for JSON responses (see `app/Http/Resources/`)
- ✅ Example: `app/Http/Controllers/BrowseController.php` - delegates to `BrowseModerationService`

**Models** (`app/Models/`)
- ✅ DO: Use Eloquent relationships with return type hints
- ✅ DO: Use `casts()` method for type casting (Laravel 12 pattern)
- ✅ DO: Use factories for test data (see `database/factories/`)
- ✅ Example: `app/Models/File.php` - relationships, casts, factory

**Services** (`app/Services/`)
- ✅ DO: Business logic goes in Services, not Controllers
- ✅ DO: Extend `BaseService` when appropriate (see `app/Services/BaseService.php`)
- ✅ DO: Use dependency injection in constructors
- ✅ Example: `app/Services/BrowseModerationService.php` - handles browse logic
- ✅ Example: `app/Services/FileModerationService.php` - file moderation rules

**Form Requests** (`app/Http/Requests/`)
- ✅ DO: Use Form Requests for validation (not inline in controllers)
- ✅ DO: Include both validation rules and custom error messages
- ✅ Example: `app/Http/Requests/StoreTabRequest.php`

**Policies** (`app/Policies/`)
- ✅ DO: Use Policies for authorization (not inline checks)
- ✅ Example: `app/Policies/FilePolicy.php`

**Jobs** (`app/Jobs/`)
- ✅ DO: Implement `ShouldQueue` for time-consuming operations
- ✅ Example: `app/Jobs/DownloadFile.php`

**Listings** (`app/Listings/`)
- ✅ DO: Use Listing classes for complex query building (from `wyxos/harmonie` package)
- ✅ Example: `app/Listings/FileListing.php`

### Naming Conventions

- **Controllers**: `{Resource}Controller.php` (e.g., `FilesController.php`)
- **Models**: Singular, PascalCase (e.g., `File.php`, `User.php`)
- **Services**: `{Purpose}Service.php` (e.g., `BrowseModerationService.php`)
- **Form Requests**: `{Action}{Resource}Request.php` (e.g., `StoreTabRequest.php`)
- **Policies**: `{Model}Policy.php` (e.g., `FilePolicy.php`)
- **Jobs**: `{Action}{Resource}Job.php` (e.g., `DeleteAutoDislikedFileJob.php`)

### Laravel 12 Specifics

- **No middleware files** in `app/Http/Middleware/` - register in `bootstrap/app.php`
- **No `app/Console/Kernel.php`** - use `bootstrap/app.php` or `routes/console.php`
- **Commands auto-register** - files in `app/Console/Commands/` are automatically available
- **Casts in method**: Use `casts()` method instead of `$casts` property when appropriate

### Code Examples

**Controller Pattern**:
```php
// ✅ DO: Thin controller, delegate to service
public function index(BrowseRequest $request): JsonResponse
{
    $items = $this->browseService->getItems($request->validated());
    return response()->json($items);
}
```

**Service Pattern**:
```php
// ✅ DO: Business logic in services
public function __construct(
    public BrowsePersister $persister,
    public BaseModerationService $moderation
) {}
```

**Form Request Pattern**:
```php
// ✅ DO: Validation in Form Request
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
    ];
}
```

---

## Touch Points / Key Files

### Core Application
- **Bootstrap**: `bootstrap/app.php` - Application configuration, middleware, exceptions
- **Routes**: `routes/web.php` - All application routes
- **Service Provider**: `app/Providers/AppServiceProvider.php` - Application service provider

### Important Models
- `app/Models/File.php` - Main file model with relationships
- `app/Models/User.php` - User model
- `app/Models/Tab.php` - Browse tab model
- `app/Models/Reaction.php` - File reaction model

### Key Services
- `app/Services/BrowseModerationService.php` - Browse filtering logic
- `app/Services/FileModerationService.php` - File moderation rules
- `app/Services/BrowsePersister.php` - Browse state persistence
- `app/Services/LocalService.php` - Local file service
- `app/Services/Wallhaven.php` - Wallhaven API integration
- `app/Services/CivitAiImages.php` - CivitAI API integration

### Controllers
- `app/Http/Controllers/BrowseController.php` - Browse API endpoints
- `app/Http/Controllers/FilesController.php` - File CRUD operations
- `app/Http/Controllers/TabController.php` - Tab management
- `app/Http/Controllers/FileReactionController.php` - File reactions

### Base Classes
- `app/Model.php` - Base model class
- `app/Services/BaseService.php` - Base service class
- `app/Services/BaseModerationService.php` - Base moderation service
- `app/Http/Controllers/Controller.php` - Base controller

---

## JIT Index Hints

### Search Commands
```bash
# Find a controller method
rg -n "public function.*" app/Http/Controllers

# Find a service method
rg -n "public function.*" app/Services

# Find a model relationship
rg -n "public function.*\(\):" app/Models

# Find a policy method
rg -n "public function.*" app/Policies

# Find a form request
find app/Http/Requests -name "*Request.php"

# Find API routes
rg -n "Route::" routes/web.php
```

### Database
```bash
# List migrations
ls -la database/migrations/

# Run specific migration
php artisan migrate --path=database/migrations/YYYY_MM_DD_create_table.php

# Rollback last migration
php artisan migrate:rollback
```

---

## Common Gotchas

1. **Environment Variables**: Always use `config()` helper, never `env()` outside config files
2. **Database Migrations**: When modifying a column, include ALL previous attributes or they'll be dropped
3. **Eager Loading**: Use `with()` to prevent N+1 queries: `File::with('reactions')->get()`
4. **Form Requests**: Must be type-hinted in controller method parameters to trigger validation
5. **Policies**: Register in `app/Providers/AppServiceProvider.php` or use auto-discovery
6. **Queue Jobs**: Must implement `ShouldQueue` interface and have proper exception handling

---

## Pre-PR Checks

```bash
vendor/bin/pint --dirty && php artisan test
```

---

## Database Patterns

### Migrations
- Location: `database/migrations/`
- Naming: `YYYY_MM_DD_HHMMSS_description.php`
- **Critical**: When modifying columns, include all previous attributes

### Factories
- Location: `database/factories/`
- Use for test data: `File::factory()->create()`
- Example: `database/factories/FileFactory.php`

### Seeders
- Location: `database/seeders/`
- Run with: `php artisan db:seed`

---

## API Patterns

### Routes
- REST routes defined in `routes/web.php`
- API routes use `/api/` prefix
- All API routes require `auth` middleware (except login)

### Validation
- Use Form Request classes (see `app/Http/Requests/`)
- Validation rules and messages in same file

### Responses & Backend Alignment
- **✅ DO: Return data in the exact format the frontend needs**
- Use API Resources for structured responses (see `app/Http/Resources/`)
- If frontend needs specific field names or structure, update the Resource, don't ask frontend to transform
- Example: `app/Http/Resources/FileResource.php` - returns data structure frontend uses directly
- **Principle**: Backend should align to frontend needs, not vice versa

**✅ DO: Return frontend-ready data**
```php
// In FileResource.php
public function toArray($request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'path' => $this->path,
        // Return exactly what frontend needs
    ];
}
```

**❌ DON'T: Return raw model and expect frontend to transform**
```php
// ❌ DON'T: Return inconsistent or incomplete data
return $file->toArray(); // May include fields frontend doesn't need
```

### Error Handling
- Laravel handles exceptions via `bootstrap/app.php` exception handler
- API errors return JSON with appropriate status codes

### Example Endpoint Pattern
See `app/Http/Controllers/BrowseController.php`:
- Form Request for validation
- Service for business logic
- JSON response with proper status codes

