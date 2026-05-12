# Adding a New Image/Video Source to Atlas

Atlas uses a pluggable service architecture for browse sources. Each source (CivitAI, Wallhaven, etc.) is a class that extends `BaseService` and implements a `fetch()` → `transform()` pipeline. Adding a new source requires **only backend changes** — the frontend discovers services dynamically.

## Architecture Overview

```
Frontend (Vue)                    Backend (Laravel)
─────────────                     ─────────────────
BrowsePage.vue
  → GET /api/browse?service=xxx   → BrowseController::index()
                                    → Browser::handle()
                                      → resolves service by key
                                      → service.fetch($params)     ← call external API
                                      → service.transform($resp)   ← normalize to standard format
                                      → BrowsePersister::persist()  ← upsert Files + Metadata + Containers
                                      → BrowseModerationService    ← filter blacklisted/reacted
                                      → FileItemFormatter::format() ← shape for frontend
                                    ← JSON response with items, services, moderation
```

**Key components:**

| Component | File | Role |
|-----------|------|------|
| `BaseService` | `app/Services/BaseService.php` | Abstract base class all services extend |
| `Browser` | `app/Browser.php` | Orchestrator — routes requests, handles errors, tab persistence |
| `BrowsePersister` | `app/Services/BrowsePersister.php` | Upserts Files, FileMetadata, Containers; syncs to Typesense |
| `BrowseController` | `app/Http/Controllers/BrowseController.php` | API endpoints: `index()`, `services()`, `sources()` |
| `FileItemFormatter` | `app/Services/FileItemFormatter.php` | Shapes File models into the JSON items the frontend consumes |
| `ServiceFilterSchema` | `app/Support/ServiceFilterSchema.php` | Declarative builder for filter UI schemas |
| `HttpRateLimiter` | `app/Support/HttpRateLimiter.php` | Rate limiting + retry logic for HTTP requests |
| `FileTypeDetector` | `app/Support/FileTypeDetector.php` | Derive extension/MIME from URLs |

## Step-by-Step Implementation

### Step 1: Create the Service Class

**File:** `app/Services/{Name}.php`

Extend `BaseService` and define the required constants and methods:

```php
<?php

namespace App\Services;

use App\Support\FileTypeDetector;
use App\Support\HttpRateLimiter;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Unsplash extends BaseService
{
    public const string KEY = 'unsplash';        // kebab-case identifier
    public const string SOURCE = 'Unsplash';     // source label for DB/storage
    public const string LABEL = 'Unsplash';      // display label in UI

    // Set to true if the source blocks direct image hotlinking
    public const bool HOTLINK_PROTECTED = false;

    /**
     * Call the external API and return the raw response array.
     *
     * @throws ConnectionException
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;

        $base = 'https://api.unsplash.com/photos';

        HttpRateLimiter::throttleDomain('api.unsplash.com', 10, 60);

        $response = HttpRateLimiter::requestWithRetry(
            fn () => Http::withHeaders([
                'Authorization' => 'Client-ID ' . config('services.unsplash.key'),
            ])->acceptJson(),
            $base,
            $this->formatParams(),
            maxRetries: 3,
            baseDelaySeconds: 2
        );

        if ($response->failed()) {
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Map UI params to the upstream API's query format.
     */
    public function formatParams(): array
    {
        $page = isset($this->params['page']) ? (int) $this->params['page'] : 1;
        $limit = isset($this->params['limit']) ? (int) $this->params['limit'] : 20;

        $query = [
            'page' => max(1, $page),
            'per_page' => max(1, min(30, $limit)),
        ];

        $orderBy = $this->params['sort'] ?? 'latest';
        $query['order_by'] = $orderBy;

        return $query;
    }

    /**
     * Default filter values for this service.
     */
    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'sort' => 'latest',
        ];
    }

    /**
     * Normalize raw API response into the standard Atlas format.
     */
    public function transform(array $response, array $params = []): array
    {
        $rows = is_array($response) ? $response : [];
        $mapped = array_map(fn ($row) => $this->transformRow((array) $row), $rows);

        // Derive next page cursor from response headers or params
        $currentPage = (int) ($this->params['page'] ?? 1);
        $next = count($rows) > 0 ? (string) ($currentPage + 1) : null;

        return [
            'files' => $mapped,
            'filter' => [
                ...$this->params,
                'next' => $next,
            ],
            'meta' => [
                'total' => null,
            ],
        ];
    }

    /**
     * Transform a single API item into the {file, metadata} structure.
     */
    protected function transformRow(array $row): array
    {
        $now = Carbon::now();
        $id = $row['id'] ?? '';
        $urls = $row['urls'] ?? [];
        $url = $urls['raw'] ?? $urls['full'] ?? '';
        $thumbnail = $urls['small'] ?? $urls['thumb'] ?? $url;
        $referrer = $row['links']?.['html'] ?? "https://unsplash.com/photos/{$id}";

        $file = [
            'source' => 'Unsplash',
            'source_id' => (string) $id,
            'url' => $url,
            'referrer_url' => $referrer,
            'filename' => Str::random(40),
            'ext' => FileTypeDetector::extensionFromUrl($url),
            'mime_type' => FileTypeDetector::mimeFromUrl($url),
            'hash' => null,
            'size' => null,
            'title' => null,
            'description' => $row['description'] ?? $row['alt_description'] ?? null,
            'preview_url' => $thumbnail,
            'listing_metadata' => json_encode($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $metadata = [
            'file_referrer_url' => $referrer,
            'payload' => json_encode([
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            'file' => $file,
            'metadata' => $metadata,
        ];
    }

    /**
     * Define containers (grouping contexts) extracted from listing_metadata.
     */
    public function containers(array $listingMetadata = [], array $detailMetadata = []): array
    {
        $containers = [];

        $username = $listingMetadata['user']?.['username'] ?? null;
        if (is_string($username) && $username !== '') {
            $containers[] = [
                'type' => 'User',
                'source_id' => $username,
                'referrer' => "https://unsplash.com/@{$username}",
            ];
        }

        $collectionId = $listingMetadata['collection_id'] ?? null;
        if ($collectionId) {
            $containers[] = [
                'type' => 'Collection',
                'source_id' => (string) $collectionId,
                'referrer' => null,
            ];
        }

        return $containers;
    }

    /**
     * Which container types can be blacklisted by users.
     */
    public function getBlacklistableContainerTypes(): array
    {
        return ['User'];
    }
}
```

**Required `file` array keys** (in `transformRow`):

| Key | Type | Purpose |
|-----|------|---------|
| `source` | string | Must match the `SOURCE` constant |
| `source_id` | string | Unique ID from the upstream service |
| `url` | string | Original media URL |
| `referrer_url` | string \| null | Page URL where the item appears |
| `filename` | string | Random storage filename (`Str::random(40)`) |
| `ext` | string \| null | File extension (use `FileTypeDetector::extensionFromUrl`) |
| `mime_type` | string \| null | MIME type (use `FileTypeDetector::mimeFromUrl`) |
| `hash` | string \| null | Content hash if available |
| `size` | int \| null | File size in bytes |
| `title` | string \| null | Item title |
| `description` | string \| null | Item description |
| `preview_url` | string | Thumbnail/preview image URL |
| `listing_metadata` | string | JSON-encoded raw API item data |
| `created_at` | Carbon | Timestamp |
| `updated_at` | Carbon | Timestamp |

**Required `metadata` array keys:**

| Key | Type | Purpose |
|-----|------|---------|
| `file_referrer_url` | string \| null | Referrer URL for metadata |
| `payload` | string | JSON-encoded service-specific metadata (width, height, etc.) |
| `created_at` | Carbon | Timestamp |
| `updated_at` | Carbon | Timestamp |

**Gotchas:**
- `source_id` must be a **string** — cast with `(string)` even if the upstream ID is numeric
- `listing_metadata` must be a **JSON string**, not an array — use `json_encode($row)`
- `url` must not be empty — `BrowsePersister` filters out items without a URL
- Use `HttpRateLimiter::throttleDomain()` before every HTTP request to avoid hammering the API
- Use `HttpRateLimiter::requestWithRetry()` with retry logic for 429/connection errors

### Step 2: Create the Filter Schema (Recommended)

**File:** `app/Support/{Name}FilterSchema.php`

This defines the UI filter controls for the service. The frontend renders them automatically from the schema.

```php
<?php

namespace App\Support;

class UnsplashFilterSchema
{
    public static function make(): array
    {
        $schema = ServiceFilterSchema::make()
            ->keys([
                // Map UI key → upstream API key (only when they differ)
                'sort' => 'order_by',
            ])
            ->types([
                'page' => 'hidden',
                'limit' => 'number',
                'sort' => 'select',
            ])
            ->labels([
                'sort' => 'Order By',
            ]);

        return $schema->fields([
            ...$schema->paginationFields(),

            $schema->field('sort', [
                'description' => 'Sort order for results.',
                'options' => [
                    ['label' => 'Latest', 'value' => 'latest'],
                    ['label' => 'Oldest', 'value' => 'oldest'],
                    ['label' => 'Popular', 'value' => 'popular'],
                ],
                'default' => 'latest',
            ]),
        ]);
    }
}
```

Then wire it up in the service:

```php
public function filterSchema(): array
{
    return UnsplashFilterSchema::make();
}
```

**Available field types:** `select`, `radio`, `checkbox`, `checkbox-group`, `boolean`, `text`, `number`, `hidden`

**Field definition keys:** `uiKey`, `serviceKey`, `type`, `label`, `description`, `required`, `options` (array of `{label, value}`), `default`, `min`, `max`, `step`, `placeholder`

### Step 3: Register the Service in Browser

**File:** `app/Browser.php`

Add one line to `getAvailableServices()`:

```php
protected function getAvailableServices(): array
{
    return [
        CivitAiImages::key() => CivitAiImages::class,
        Wallhaven::key() => Wallhaven::class,
        LocalService::key() => LocalService::class,
        Unsplash::key() => Unsplash::class,  // <-- add this
    ];
}
```

That's it. No other registration is needed — `Browser`, `BrowseController`, and the frontend all discover services from this map.

### Step 4: Add API Key Configuration

**File:** `config/services.php`

```php
'unsplash' => [
    'key' => env('UNSPLASH_API'),
],
```

Reference in the service:

```php
config('services.unsplash.key')
```

Add to `.env`:

```
UNSPLASH_API=your_access_key_here
```

### Step 5: Write Tests

**File:** `tests/Unit/Services/{Name}Test.php`

```php
<?php

use App\Services\Unsplash;

it('returns correct key', function () {
    expect(Unsplash::key())->toBe('unsplash');
});

it('returns default params', function () {
    $service = new Unsplash;
    $defaults = $service->defaultParams();

    expect($defaults)->toHaveKey('limit')
        ->and($defaults['limit'])->toBe(20)
        ->and($defaults)->toHaveKey('sort')
        ->and($defaults['sort'])->toBe('latest');
});

it('transforms raw response into standard format', function () {
    $service = new Unsplash;
    $service->setParams(['page' => 1, 'limit' => 20]);

    $response = [
        [
            'id' => 'abc123',
            'urls' => ['raw' => 'https://images.unsplash.com/photo-abc', 'small' => 'https://images.unsplash.com/photo-abc?w=400'],
            'width' => 1920,
            'height' => 1080,
        ],
    ];

    $result = $service->transform($response);

    expect($result)->toHaveKey('files')
        ->and($result['files'])->toHaveCount(1)
        ->and($result['files'][0])->toHaveKey('file')
        ->and($result['files'][0])->toHaveKey('metadata')
        ->and($result['files'][0]['file']['source'])->toBe('Unsplash')
        ->and($result['files'][0]['file']['source_id'])->toBe('abc123')
        ->and($result['filter'])->toHaveKey('next');
});

it('formats params for upstream API', function () {
    $service = new Unsplash;
    $service->setParams(['page' => 2, 'limit' => 10, 'sort' => 'popular']);

    $params = $service->formatParams();

    expect($params)->toHaveKey('page')
        ->and($params['page'])->toBe(2)
        ->and($params)->toHaveKey('per_page')
        ->and($params['per_page'])->toBe(10)
        ->and($params)->toHaveKey('order_by')
        ->and($params['order_by'])->toBe('popular');
});
```

### Step 6: Verify End-to-End

```bash
# Run tests
php artisan test --compact

# Start the app
composer run dev
```

1. Open the browse page
2. The new service appears in the services dropdown automatically
3. Select it and verify items load
4. Test pagination, filters, reactions, and downloads

## Frontend Integration: Zero Changes Required

The frontend discovers services dynamically via `GET /api/browse/services`. The response includes each service's `key`, `label`, `defaults`, and `schema`. The `browseCatalog.ts` module fetches this and the `BrowseController::services()` method auto-enumerates all registered services from `Browser::getAvailableServices()`.

No Vue components, routes, or TypeScript types need modification when adding a new source.

## Containers and Blacklisting

Containers group files by their source context (a post, a user, a collection). They enable blacklisting — blocking all current and future files from a specific container.

**How containers work:**

1. The service's `containers()` method extracts container data from each item's `listing_metadata`
2. `BrowsePersister::createContainersForFiles()` upserts containers and attaches them to files
3. Each container has: `type` (e.g., `Post`, `User`, `Collection`), `source_id`, `referrer` (optional URL), `source` (optional, defaults to file source)
4. `getBlacklistableContainerTypes()` defines which types users can blacklist
5. Blacklisting a container auto-blacklists all its files and any future files added to it

**CivitAI example:** Extracts `Post`, `User`, `Checkpoint`, and `LoRA` containers. Only `User` is blacklisted.

## Hotlink Protection

Some sources block direct image access from external referrers. To handle this:

1. Set `HOTLINK_PROTECTED = true` on the service class
2. Override `decorateOriginalUrl()` if URL rewriting is needed:

```php
public const bool HOTLINK_PROTECTED = true;

public function decorateOriginalUrl(File $file, string $originalUrl, ?Authenticatable $viewer = null): string
{
    // Return a proxy URL or modified URL that bypasses hotlink protection
    return $originalUrl;
}
```

Wallhaven is the reference — it requires `Referer: https://wallhaven.cc/` headers on requests.

## Support Classes Quick Reference

### HttpRateLimiter (`app/Support/HttpRateLimiter.php`)

```php
// Throttle: max N requests per domain per time window
HttpRateLimiter::throttleDomain('api.example.com', 10, 60);

// Retry: auto-retries on 429 and connection errors with exponential backoff
$response = HttpRateLimiter::requestWithRetry(
    fn () => Http::acceptJson(),  // client factory
    $url,                          // request URL
    $query,                        // query params
    maxRetries: 3,                 // max retry attempts
    baseDelaySeconds: 2            // base delay for backoff
);
```

### FileTypeDetector (`app/Support/FileTypeDetector.php`)

```php
$ext = FileTypeDetector::extensionFromUrl($url);     // e.g., 'jpg', 'mp4', null
$mime = FileTypeDetector::mimeFromUrl($url);          // e.g., 'image/jpeg', 'video/mp4', null
```

### ServiceFilterSchema (`app/Support/ServiceFilterSchema.php`)

```php
$schema = ServiceFilterSchema::make()
    ->keys(['sort' => 'order_by'])         // UI key → API key mapping
    ->types(['sort' => 'select'])           // UI key → field type
    ->labels(['sort' => 'Order By']);       // UI key → display label

$fields = $schema->fields([
    ...$schema->paginationFields(),         // page + limit fields
    $schema->field('sort', [                // custom field
        'description' => 'Sort order.',
        'options' => [
            ['label' => 'Newest', 'value' => 'newest'],
        ],
        'default' => 'newest',
    ]),
]);
```
