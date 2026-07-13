<?php

namespace App\Services\SourceMedia;

use App\Enums\SourceMediaUrlPolicy;
use App\Enums\SourceMediaVariant;
use App\Models\File;
use App\Models\User;
use App\Services\DeviantArt\DeviantArtOAuthService;
use App\Services\DeviantArtImages;
use App\Support\DeviantArtApiClient;
use App\Support\DeviantArtMediaResolver;
use App\Support\DeviantArtPageUrl;
use App\Support\FileMimeType;
use App\Support\FileTypeDetector;
use Carbon\CarbonImmutable;

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

    public function mediaUrlPolicy(File $file): SourceMediaUrlPolicy
    {
        return SourceMediaUrlPolicy::Expiring;
    }

    public function mediaUrlExpiresAt(File $file, SourceMediaVariant $variant): ?CarbonImmutable
    {
        return self::expiresAt($variant->currentUrl($file));
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
            urlExpiresAt: self::expiresAt($media['url']),
            previewUrlExpiresAt: self::expiresAt($media['preview_url']),
            size: $media['filesize'],
            ext: FileTypeDetector::extensionFromUrl($typeProbe),
            mimeType: FileMimeType::canonicalize(FileTypeDetector::mimeFromUrl($typeProbe)),
            listingMetadata: $listingMetadata,
            metadataPayload: DeviantArtMediaResolver::metadataPayload($payload, $media),
        );
    }

    private static function expiresAt(?string $url): ?CarbonImmutable
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $queryString = parse_url($url, PHP_URL_QUERY);
        if (! is_string($queryString) || $queryString === '') {
            return null;
        }

        parse_str($queryString, $query);
        $token = $query['token'] ?? null;
        if (! is_string($token)) {
            return null;
        }

        $segments = explode('.', $token);
        if (count($segments) < 2) {
            return null;
        }

        $payload = strtr($segments[1], '-_', '+/');
        $remainder = strlen($payload) % 4;
        if ($remainder !== 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($payload, true);
        if (! is_string($decoded)) {
            return null;
        }

        $claims = json_decode($decoded, true);
        $expiresAt = is_array($claims) ? ($claims['exp'] ?? null) : null;
        if (! is_numeric($expiresAt) || (int) $expiresAt <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $expiresAt);
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
