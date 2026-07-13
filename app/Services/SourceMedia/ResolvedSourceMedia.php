<?php

namespace App\Services\SourceMedia;

use Carbon\CarbonImmutable;

final class ResolvedSourceMedia
{
    /**
     * @param  array<string, mixed>  $listingMetadata
     * @param  array<string, mixed>  $metadataPayload
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $previewUrl = null,
        public readonly ?CarbonImmutable $urlExpiresAt = null,
        public readonly ?CarbonImmutable $previewUrlExpiresAt = null,
        public readonly ?int $size = null,
        public readonly ?string $ext = null,
        public readonly ?string $mimeType = null,
        public readonly array $listingMetadata = [],
        public readonly array $metadataPayload = [],
    ) {}
}
