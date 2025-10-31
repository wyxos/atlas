<?php

namespace App\Services;

use App\Models\File;
use Atlas\Plugin\Contracts\BrowseService;

abstract class BaseService implements BrowseService
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

    /**
     * Persist transformed rows using the shared BrowsePersister helper.
     *
     * @return array<int, File>
     */
    public function persists(array $transformedItems): array
    {
        return app(BrowsePersister::class)->persist($transformedItems);
    }
}
