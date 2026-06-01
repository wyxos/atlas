<?php

namespace App\Services;

use App\Models\User;
use App\Services\DeviantArt\DeviantArtOAuthConfig;
use App\Services\DeviantArt\DeviantArtOAuthService;
use App\Support\DeviantArtApiClient;
use App\Support\DeviantArtImagesFilterSchema;
use App\Support\DeviantArtMediaResolver;
use App\Support\DeviantArtPageUrl;
use App\Support\FileMimeType;
use App\Support\FileTypeDetector;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeviantArtImages extends BaseService
{
    public const string KEY = 'deviantart-images';

    public const string SOURCE = 'deviantart.com';

    public const string LABEL = 'DeviantArt Images';

    /**
     * Fetch deviations from the official DeviantArt API.
     *
     * @throws ConnectionException
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;
        $client = $this->client();
        $token = $this->accessToken();
        [$url, $query] = $this->requestTarget($client);

        $json = $client->requestJson($url, $query, $token);

        return $json;
    }

    public function containers(array $listingMetadata = [], array $detailMetadata = []): array
    {
        $containers = [];
        $username = DeviantArtMediaResolver::artistUsername($listingMetadata);

        if ($username !== null && $username !== '') {
            $containers[] = [
                'type' => 'User',
                'source_id' => $username,
                'referrer' => DeviantArtMediaResolver::artistGalleryUrl($username),
            ];
        }

        return $containers;
    }

    public function formatParams(): array
    {
        $endpoint = $this->endpointType();
        $query = [
            'limit' => $this->resolveLimit($endpoint),
        ];

        $page = $this->params['page'] ?? null;
        if ($endpoint === 'tag' && is_string($page) && $page !== '' && $page !== '1' && ! ctype_digit($page)) {
            $query['cursor'] = $page;
        } else {
            $query['offset'] = $this->resolveOffset($page);
        }

        $nsfw = $this->resolveBoolean($this->params['nsfw'] ?? null);
        if ($nsfw !== null) {
            $query['mature_content'] = $nsfw;
        }

        $username = $this->stringParam('username');
        if (in_array($endpoint, ['gallery-all', 'gallery-folder'], true) && $username !== null) {
            $query['username'] = $username;
        }

        if ($endpoint === 'home' && ($q = $this->homeSearchQuery()) !== null) {
            $query['q'] = $q;
            $this->params['q'] = $q;
        }

        if ($endpoint === 'tag' && ($tag = $this->stringParam('tag')) !== null) {
            $query['tag'] = $tag;
        }

        return $query;
    }

    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'q' => '',
            'tag' => '',
            'username' => '',
            'folderId' => '',
            'nsfw' => false,
        ];
    }

    public function browseStatusForUser(?Authenticatable $user): ?array
    {
        $config = app(DeviantArtOAuthConfig::class);

        if (! $config->isConfigured()) {
            return [
                'state' => 'error',
                'label' => 'Error',
                'message' => 'DeviantArt OAuth is not configured.',
            ];
        }

        if (! $user instanceof User) {
            return [
                'state' => 'disconnected',
                'label' => 'Disconnected',
                'message' => 'Sign in to connect DeviantArt.',
            ];
        }

        $token = $user->deviantArtToken()->latest('id')->first();

        if (! $token) {
            return [
                'state' => 'disconnected',
                'label' => 'Disconnected',
                'message' => 'Connect DeviantArt in Settings.',
            ];
        }

        try {
            if ($token->isExpired() && trim((string) $token->refresh_token) === '') {
                return [
                    'state' => 'disconnected',
                    'label' => 'Reconnect',
                    'message' => 'Reconnect DeviantArt in Settings.',
                ];
            }
        } catch (Throwable) {
            return [
                'state' => 'error',
                'label' => 'Error',
                'message' => 'Stored DeviantArt credentials are invalid. Reconnect in Settings.',
            ];
        }

        return [
            'state' => 'ready',
            'label' => null,
            'message' => null,
        ];
    }

    public function filterSchema(): array
    {
        return DeviantArtImagesFilterSchema::make();
    }

    public function shouldPersistResult(array $item): bool
    {
        if ($this->hasLockedTierAccess($item)) {
            return false;
        }

        return ! $this->hasLockedPremiumFolderAccess($item);
    }

    public function transform(array $response, array $params = []): array
    {
        [$rows, $next, $total] = $this->transformResponse($response);
        $mapped = [];

        foreach ($this->persistableResults($rows) as $row) {
            $transformed = $this->transformRow($row);
            if ($transformed !== null) {
                $mapped[] = $transformed;
            }
        }

        return [
            'files' => $mapped,
            'filter' => [
                ...$this->params,
                'next' => $next,
            ],
            'meta' => [
                'total' => $total,
            ],
        ];
    }

    public function getBlacklistableContainerTypes(): array
    {
        return [
            'User',
        ];
    }

    protected function transformResponse(array $response): array
    {
        $rows = $response['results'] ?? [];
        $hasMore = (bool) ($response['has_more'] ?? false);
        $next = null;

        if ($hasMore && isset($response['next_cursor']) && is_string($response['next_cursor']) && $response['next_cursor'] !== '') {
            $next = $response['next_cursor'];
        } elseif ($hasMore && isset($response['next_offset']) && is_numeric($response['next_offset'])) {
            $next = (string) $response['next_offset'];
        }

        $total = isset($response['estimated_total']) && is_numeric($response['estimated_total'])
            ? (int) $response['estimated_total']
            : null;

        return [$rows, $next, $total];
    }

    protected function transformRow(array $row): ?array
    {
        $now = Carbon::now();
        $media = DeviantArtMediaResolver::resolve($row);
        if ($media['url'] === '') {
            return null;
        }

        $rawReferrer = isset($row['url']) && is_string($row['url']) ? $row['url'] : null;
        $referrer = DeviantArtPageUrl::normalize($rawReferrer) ?? $rawReferrer;
        if ($referrer !== null) {
            $row['url'] = $referrer;
        }

        $id = isset($row['deviationid']) ? (string) $row['deviationid'] : sha1((string) ($referrer ?? $media['url']));
        $typeProbe = $media['filename'] !== null && $media['filename'] !== '' ? $media['filename'] : $media['url'];

        $file = [
            'source' => self::SOURCE,
            'source_id' => $id,
            'url' => $media['url'],
            'referrer_url' => $referrer,
            'filename' => Str::random(40),
            'ext' => FileTypeDetector::extensionFromUrl($typeProbe),
            'mime_type' => FileMimeType::canonicalize(FileTypeDetector::mimeFromUrl($typeProbe)),
            'hash' => null,
            'size' => $media['filesize'],
            'title' => isset($row['title']) && is_string($row['title']) ? $row['title'] : null,
            'description' => isset($row['excerpt']) && is_string($row['excerpt']) ? $row['excerpt'] : null,
            'preview_url' => $media['preview_url'],
            'listing_metadata' => json_encode(DeviantArtMediaResolver::listingMetadata($row, $media)),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $metadata = [
            'file_referrer_url' => $referrer,
            'payload' => json_encode(DeviantArtMediaResolver::metadataPayload($row, $media)),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            'file' => $file,
            'metadata' => $metadata,
        ];
    }

    private function requestTarget(DeviantArtApiClient $client): array
    {
        $endpoint = $this->endpointType();
        $path = match ($endpoint) {
            'gallery-folder' => '/gallery/'.$this->encodedFolderId(),
            'gallery-all' => '/gallery/all',
            'tag' => '/browse/tags',
            default => '/browse/home',
        };

        return [$client->apiBaseUrl().$path, $this->formatParams()];
    }

    private function endpointType(): string
    {
        if ($this->stringParam('folderId') !== null) {
            return 'gallery-folder';
        }

        if ($this->stringParam('username') !== null) {
            return 'gallery-all';
        }

        if ($this->stringParam('tag') !== null) {
            return 'tag';
        }

        return 'home';
    }

    private function encodedFolderId(): string
    {
        return rawurlencode((string) $this->stringParam('folderId'));
    }

    private function resolveLimit(string $endpoint): int
    {
        $limit = isset($this->params['limit']) ? (int) $this->params['limit'] : 20;
        $max = in_array($endpoint, ['gallery-all', 'gallery-folder'], true) ? 24 : 50;

        return max(1, min($max, $limit));
    }

    private function resolveOffset(mixed $page): int
    {
        if (is_int($page) || is_float($page) || (is_string($page) && ctype_digit($page))) {
            $offset = (int) $page;

            return $offset <= 1 ? 0 : $offset;
        }

        return 0;
    }

    private function homeSearchQuery(): ?string
    {
        return $this->stringParam('q') ?? $this->defaultQuery();
    }

    private function defaultQuery(): ?string
    {
        $query = app()->bound('config')
            ? trim((string) config('services.deviantart.default_query', ''))
            : '';

        return $query === '' ? null : $query;
    }

    private function stringParam(string $key): ?string
    {
        $value = $this->params[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resolveBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function hasLockedTierAccess(array $item): bool
    {
        return strtolower((string) $this->nullableString(data_get($item, 'tier_access'))) === 'locked';
    }

    private function hasLockedPremiumFolderAccess(array $item): bool
    {
        $premiumFolderData = data_get($item, 'premium_folder_data');
        if (! is_array($premiumFolderData)) {
            return false;
        }

        $hasAccess = $this->nullableBoolean($premiumFolderData['has_access'] ?? null);
        if ($hasAccess !== false) {
            return false;
        }

        $type = strtolower((string) $this->nullableString($premiumFolderData['type'] ?? null));
        if ($type === '') {
            return false;
        }

        return $type !== 'watchers';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return null;
    }

    private function client(): DeviantArtApiClient
    {
        return app(DeviantArtApiClient::class);
    }

    private function accessToken(): string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RuntimeException('DeviantArt requires a connected Atlas user.');
        }

        $token = app(DeviantArtOAuthService::class)->getValidAccessToken($user);
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('DeviantArt is not connected. Connect DeviantArt in Settings.');
        }

        return $token;
    }
}
