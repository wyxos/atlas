<?php

namespace App\Services\Extension;

use App\Models\File;
use App\Services\CivitAiImages;
use App\Services\DeviantArtImages;
use App\Support\CivitAiMediaUrl;
use App\Support\DeviantArtPageUrl;
use App\Support\StableFileIdentity;

class ExtensionFileIdentityResolver
{
    /**
     * @return array{url: string, source_id: string|null}
     */
    public function resolve(
        string $url,
        string $source,
        ?string $referrerUrl,
        ?string $pageUrl,
        ?string $tagName,
    ): array {
        if ($source !== CivitAiImages::SOURCE) {
            return [
                'source_id' => null,
                'url' => $url,
            ];
        }

        $sourceId = $this->extractCivitAiImageIdFromCandidateUrls([$referrerUrl, $pageUrl]);
        if ($sourceId === null) {
            return [
                'source_id' => null,
                'url' => $url,
            ];
        }

        return [
            'source_id' => $sourceId,
            'url' => $this->canonicalizeCivitAiMediaUrl($url, $sourceId, $tagName) ?? $url,
        ];
    }

    public function findExisting(
        string $urlHash,
        string $source,
        ?string $sourceId,
        ?string $referrerUrl,
    ): ?File {
        $file = File::query()
            ->where('url_hash', $urlHash)
            ->first();
        if ($file) {
            return $file;
        }

        return StableFileIdentity::findExistingFile(
            $source,
            $sourceId,
            $this->identityReferrerUrl($source, $referrerUrl),
        );
    }

    public function referrerUrlsAreEquivalent(string $source, ?string $currentReferrerUrl, string $nextReferrerUrl): bool
    {
        if ($source !== DeviantArtImages::SOURCE) {
            return false;
        }

        $current = DeviantArtPageUrl::normalize($currentReferrerUrl);
        $next = DeviantArtPageUrl::normalize($nextReferrerUrl);

        return $current !== null && $current === $next;
    }

    private function identityReferrerUrl(string $source, ?string $referrerUrl): ?string
    {
        if ($source === DeviantArtImages::SOURCE) {
            return DeviantArtPageUrl::normalize($referrerUrl) ?? $referrerUrl;
        }

        return $referrerUrl;
    }

    /**
     * @param  array<int, mixed>  $candidateUrls
     */
    private function extractCivitAiImageIdFromCandidateUrls(array $candidateUrls): ?string
    {
        foreach ($candidateUrls as $candidateUrl) {
            $imageId = $this->extractCivitAiImageIdFromUrl(is_string($candidateUrl) ? $candidateUrl : null);
            if ($imageId !== null) {
                return $imageId;
            }
        }

        return null;
    }

    private function extractCivitAiImageIdFromUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($host) || ! is_string($path)) {
            return null;
        }

        $normalizedHost = strtolower(trim($host));
        if (! CivitAiMediaUrl::isPageHost($normalizedHost)) {
            return null;
        }

        if (preg_match('#^/images/(\d+)(?:/|$)#i', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function canonicalizeCivitAiMediaUrl(string $url, string $imageId, ?string $tagName): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;
        $path = isset($parts['path']) && is_string($parts['path']) ? trim($parts['path'], '/') : null;
        if ($scheme === null || ! in_array($scheme, ['http', 'https'], true) || ! CivitAiMediaUrl::isMediaHost($host) || $path === null || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) < 4) {
            return null;
        }

        $token = $segments[0] ?? '';
        $guid = $segments[1] ?? '';
        $filename = end($segments);
        if (! is_string($filename) || $token === '' || $guid === '') {
            return null;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $isVideo = in_array($tagName, ['video', 'iframe'], true)
            || in_array($extension, ['mp4', 'm4v', 'mov', 'webm'], true);
        if (! $isVideo) {
            return CivitAiMediaUrl::normalizeImageUrl($url);
        }

        return "{$scheme}://".CivitAiMediaUrl::PRIMARY_MEDIA_HOST."/{$token}/{$guid}/transcode=true,original=true,quality=90/{$imageId}.{$extension}";
    }
}
