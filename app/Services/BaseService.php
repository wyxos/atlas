<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class BaseService
{
    public const HOTLINK_PROTECTED = false;

    protected array $params = [];

    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public static function key(): string
    {
        return static::KEY;
    }

    public static function label(): string
    {
        return static::LABEL ?? ucfirst(str_replace('-', ' ', static::KEY));
    }

    public static function source(): string
    {
        return static::SOURCE ?? static::label();
    }

    public static function hotlinkProtected(): bool
    {
        return (bool) (static::HOTLINK_PROTECTED ?? false);
    }

    public function setParams(array $params): static
    {
        $this->params = $params;

        return $this;
    }

    public function containers(array $listingMetadata = [], array $detailMetadata = []): array
    {
        return [];
    }

    public function decorateOriginalUrl(File $file, string $originalUrl, ?Authenticatable $viewer = null): string
    {
        return $originalUrl;
    }

    /**
     * Persist transformed rows using the shared BrowsePersister helper.
     */
    public function persists(array $transformedItems): array
    {
        return app(BrowsePersister::class)->persist($transformedItems);
    }

    /**
     * Validate download consistency after a file has been downloaded.
     * Services can override this to perform source-specific validation.
     */
    public function validateDownload(File $file): bool
    {
        return true;
    }

    /**
     * Get the container types that are eligible for blacklisting.
     * Services should override this to define which container types can be blacklisted.
     * For example, users and models can be blacklisted, but posts cannot.
     */
    public function getBlacklistableContainerTypes(): array
    {
        return [];
    }

    /**
     * Describe the service's available UI filters.
     *
     * The frontend uses canonical UI keys (e.g. 'page', 'limit').
     * Each field may specify a different 'serviceKey' used by the upstream API
     * (e.g. UI 'page' -> service 'cursor', UI 'limit' -> service 'size').
     */
    public function filterSchema(): array
    {
        return [
            'fields' => [],
        ];
    }
}
