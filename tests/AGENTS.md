# Testing - Agent Guidelines

## Package Identity

**Purpose**: Test suite for Atlas v2 application  
**Backend Testing**: Pest (PHP) - Feature, Browser, and Unit tests  
**Frontend Testing**: Vitest (TypeScript) - Component and composable tests  
**Browser Testing**: Playwright via Pest Browser plugin

---

## Setup & Run

### Backend Tests (Pest)
```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/Browse/BrowseTest.php

# Run tests matching filter
php artisan test --filter=Browse

# Run with coverage
php artisan test --coverage

# Run browser tests
php artisan test tests/Browser/
```

### Frontend Tests (Vitest)
```bash
# Run all tests
npm run test

# Watch mode
npm run test:watch

# UI mode
npm run test:ui

# Coverage
npm run test:coverage

# Run specific test file
npm run test resources/js/components/AppHeader.test.ts
```

---

## Patterns & Conventions

### File Organization

**Feature Tests** (`tests/Feature/`)
- ✅ DO: Test HTTP endpoints and full request/response cycle
- ✅ DO: Use factories for test data
- ✅ DO: Group by feature area (e.g., `Browse/`, `Files/`, `Users/`)
- ✅ Example: `tests/Feature/Browse/BrowseIndexTest.php` - browse endpoint tests
- ✅ Example: `tests/Feature/Files/FilesIndexTest.php` - file listing tests

**Browser Tests** (`tests/Browser/`)
- ✅ DO: Use Pest Browser plugin for E2E testing
- ✅ DO: Test user interactions and page behavior
- ✅ Example: `tests/Browser/OfflineTabRestorationTest.php` - offline behavior

**Unit Tests** (`tests/Unit/`)
- ✅ DO: Test isolated classes and methods
- ✅ DO: Mock dependencies
- ✅ Example: `tests/Unit/ExampleTest.php`

**Frontend Tests** (`resources/js/**/*.test.ts`)
- ✅ DO: Colocate tests with source files
- ✅ DO: Test components with `@vue/test-utils`
- ✅ DO: Test composables in isolation
- ✅ Example: `resources/js/components/AppHeader.test.ts`
- ✅ Example: `resources/js/composables/useBrowseService.test.ts`

### Naming Conventions

- **Feature Tests**: `{Feature}Test.php` (e.g., `BrowseTest.php`)
- **Browser Tests**: `{Feature}Test.php` in `tests/Browser/`
- **Unit Tests**: `{Class}Test.php` in `tests/Unit/`
- **Frontend Tests**: `{Component}.test.ts` or `{Composable}.test.ts`

### Pest Test Structure

**✅ DO: Use Pest syntax**
```php
it('can browse files', function () {
    $user = User::factory()->create();
    
    $response = $this->actingAs($user)
        ->getJson('/api/browse');
    
    $response->assertSuccessful();
});
```

**✅ DO: Use datasets for multiple test cases**
```php
it('validates email format', function (string $email) {
    // test logic
})->with([
    'valid' => 'user@example.com',
    'invalid' => 'not-an-email',
]);
```

**✅ DO: Use factories for test data**
```php
$file = File::factory()->create();
$user = User::factory()->create();
```

**✅ DO: Use assertions specific to response type**
```php
$response->assertSuccessful(); // ✅
// Not: $response->assertStatus(200); // ❌
```

### Frontend Test Structure

**✅ DO: Test component behavior**
```typescript
import { mount } from '@vue/test-utils'
import { createRouter } from 'vue-router'

it('renders correctly', () => {
  const router = createRouter({ history: createMemoryHistory() })
  const wrapper = mount(Component, { 
    global: { plugins: [router] } 
  })
  
  expect(wrapper.text()).toContain('Expected Text')
})
```

**✅ DO: Test composables**
```typescript
import { useFeature } from '@/composables/useFeature'

it('should return reactive state', () => {
  const { state, method } = useFeature()
  
  method()
  expect(state.value).toBe(expected)
})
```

### Database in Tests

**✅ DO: Use `RefreshDatabase` trait**
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a file', function () {
    $file = File::factory()->create();
    expect($file)->toBeInstanceOf(File::class);
});
```

**✅ DO: Use factories, not manual model creation**
```php
// ✅ DO
$file = File::factory()->create();

// ❌ DON'T
$file = new File();
$file->save();
```

### Authentication in Tests

**✅ DO: Use `actingAs()` for authenticated requests**
```php
$user = User::factory()->create();

$response = $this->actingAs($user)
    ->getJson('/api/files');
```

### API Testing

**✅ DO: Use `getJson()`, `postJson()`, etc. for API tests**
```php
$response = $this->postJson('/api/files/reactions/batch/store', [
    'reactions' => [...]
]);

$response->assertSuccessful();
$response->assertJson(['success' => true]);
```

---

## Touch Points / Key Files

### Test Configuration
- **Pest Config**: `tests/Pest.php` - Pest configuration
- **TestCase**: `tests/TestCase.php` - Base test case class
- **Vitest Config**: `vitest.config.js` - Vitest configuration

### Test Utilities
- **Factories**: `database/factories/` - Model factories for test data
- **Fixtures**: `tests/fixtures/` - Test fixture files (images, audio, video)
- **Test Utils**: `resources/js/test/` - Frontend test utilities

### Example Tests

**Feature Tests**:
- `tests/Feature/Browse/BrowseIndexTest.php` - Browse endpoint tests
- `tests/Feature/Files/FilesIndexTest.php` - File listing
- `tests/Feature/FileReactionStoreTest.php` - Reaction creation

**Browser Tests**:
- `tests/Browser/OfflineTabRestorationTest.php` - Offline behavior

**Frontend Tests**:
- `resources/js/components/AppHeader.test.ts` - Component test
- `resources/js/composables/useBrowseService.test.ts` - Composable test
- `resources/js/pages/BrowseCore.test.ts` - Page integration test

---

## JIT Index Hints

### Search Commands
```bash
# Find a test file
find tests -name "*Test.php" | rg "FeatureName"
find resources/js -name "*.test.ts"

# Find tests for a specific feature
rg -n "it\(" tests/Feature/Browse/

# Find factory definitions
find database/factories -name "*Factory.php"

# Find test fixtures
find tests/fixtures -type f
```

### Test Discovery
```bash
# List all feature tests
find tests/Feature -name "*Test.php"

# List all browser tests
find tests/Browser -name "*Test.php"

# List all frontend tests
find resources/js -name "*.test.ts"
```

---

## Common Gotchas

1. **Database**: Always use `RefreshDatabase` trait to ensure clean state
2. **Factories**: Check for custom states before manually setting up models
   ```php
   // ✅ DO: Use factory states if available
   File::factory()->withMetadata()->create();
   ```

3. **Authentication**: Use `actingAs()` for authenticated requests, not manual session setup

4. **API Responses**: Use `assertSuccessful()`, `assertNotFound()`, etc. instead of `assertStatus()`

5. **Frontend Router**: Mock Vue Router in component tests
   ```typescript
   const router = createRouter({ history: createMemoryHistory() })
   ```

6. **Async Operations**: Use `waitFor()` or `nextTick()` for async component updates

7. **Mocks**: Use `Pest\Laravel\mock()` for mocking in Pest tests
   ```php
   use function Pest\Laravel\mock;
   
   mock(Service::class)->shouldReceive('method')->andReturn($value);
   ```

8. **Browser Tests**: Use Pest Browser plugin syntax, not raw Playwright

---

## Pre-PR Checks

```bash
# Backend
php artisan test

# Frontend
npm run test

# Both
php artisan test && npm run test
```

---

## Test Coverage Goals

- **Feature Tests**: Cover all HTTP endpoints and business logic
- **Unit Tests**: Cover isolated service methods and utilities
- **Browser Tests**: Cover critical user flows
- **Frontend Tests**: Cover component behavior and composable logic

Aim for >80% coverage on critical paths.

---

## Browser Testing (Pest Browser)

### Setup
- Uses Pest Browser plugin (`pestphp/pest-plugin-browser`)
- Configured in `tests/Pest.php`

### Usage
```php
it('can browse files in browser', function () {
    $this->actingAs(User::factory()->create());
    
    $page = visit('/browse');
    
    $page->assertSee('Browse')
        ->assertNoJavascriptErrors()
        ->click('Next Page')
        ->assertSee('Page 2');
});
```

### Best Practices
- Use `Event::fake()` for testing events
- Use `RefreshDatabase` for clean state
- Test user interactions (click, type, scroll)
- Assert no JavaScript errors: `assertNoJavascriptErrors()`



