<?php

namespace App\Services\SourceMedia;

use App\Models\File;
use App\Models\User;
use App\Services\DeviantArt\DeviantArtOAuthService;
use App\Services\DeviantArtImages;
use App\Support\DeviantArtApiClient;
use App\Support\DeviantArtMediaResolver;
use App\Support\DeviantArtPageUrl;
use App\Support\FileMimeType;
use App\Support\FileTypeDetector;

final class DeviantArtSourceMediaRefreshResolver implements SourceMediaRefreshResolver
{
    public function __construct(
        private readonly DeviantArtOAuthService $oauth,
        private readonly DeviantArtApiClient $client,
        private readonly DeviantArtImages $deviantArtImages,
    ) {}

    public function supports(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === DeviantArtImages::SOURCE
            && $this->deviationId($file) !== null;
    }

    public function resolve(File $file, User $user): ?ResolvedSourceMedia
    {
        $deviationId = $this->deviationId($file);
        if ($deviationId === null) {
            return null;
        }

        $token = $this->oauth->getValidAccessToken($user);
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $payload = $this->client->deviationPayload($deviationId, $token);
        $media = DeviantArtMediaResolver::resolve($payload);
        if ($media['url'] === '') {
            return null;
        }

        $rawReferrer = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : null;
        $referrer = DeviantArtPageUrl::normalize($rawReferrer) ?? $rawReferrer;
        if ($referrer !== null) {
            $payload['url'] = $referrer;
        }

        $typeProbe = isset($media['filename']) && is_string($media['filename']) && $media['filename'] !== ''
            ? $media['filename']
            : $media['url'];

        $listingMetadata = [
            ...DeviantArtMediaResolver::listingMetadata($payload, $media),
            ...$this->deviantArtImages->containerMetadataFromApiRow($payload, [$rawReferrer, $referrer]),
        ];

        return new ResolvedSourceMedia(
            url: $media['url'],
            previewUrl: $media['preview_url'],
            size: $media['filesize'],
            ext: FileTypeDetector::extensionFromUrl($typeProbe),
            mimeType: FileMimeType::canonicalize(FileTypeDetector::mimeFromUrl($typeProbe)),
            listingMetadata: $listingMetadata,
            metadataPayload: DeviantArtMediaResolver::metadataPayload($payload, $media),
        );
    }

    private function deviationId(File $file): ?string
    {
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];

        foreach ([$listingMetadata['deviationid'] ?? null, $file->source_id] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
