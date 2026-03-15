<?php

namespace App\Services\Extension;

use App\Services\CivitAiImages;

class ExtensionContainerMetadataService
{
    public function sourceFromCandidateUrls(array $candidateUrls): ?string
    {
        foreach ($candidateUrls as $candidateUrl) {
            $source = $this->sourceFromUrl(is_string($candidateUrl) ? $candidateUrl : null);
            if ($source !== null) {
                return $source;
            }
        }

        return null;
    }

    public function sourceFromUrl(?string $url): ?string
    {
        $normalizedUrl = $this->normalizeUrl($url);
        if ($normalizedUrl === null) {
            return null;
        }

        return $this->containerSourceFromUrl($normalizedUrl);
    }

    public function metadataOverridesFromCandidateUrls(array $candidateUrls, bool $includePostContainer): array
    {
        $containerUrl = $this->firstSupportedContainerUrl($candidateUrls);
        if ($containerUrl === null) {
            return [];
        }

        $source = $this->containerSourceFromUrl($containerUrl);
        if ($source !== 'deviantart.com') {
            return [];
        }

        $postContainerOverrides = $includePostContainer
            ? [
                'post_container_referrer_url' => $containerUrl,
                'post_container_source' => $source,
            ]
            : [];

        return array_filter(
            [
                ...$postContainerOverrides,
                ...$this->userContainerOverridesFromUrl($containerUrl, $source),
            ],
            static fn (?string $value): bool => $value !== null && $value !== ''
        );
    }

    public function mergeListingMetadataOverrides(array $urlDerivedOverrides, array $submittedOverrides): array
    {
        if ($submittedOverrides === []) {
            return $urlDerivedOverrides;
        }

        $merged = array_replace($urlDerivedOverrides, $submittedOverrides);

        if (isset($submittedOverrides['resource_containers']) && is_array($submittedOverrides['resource_containers'])) {
            $merged['resource_containers'] = array_values($submittedOverrides['resource_containers']);
        }

        return $merged;
    }

    public function firstSupportedContainerUrl(array $candidateUrls): ?string
    {
        foreach ($candidateUrls as $candidateUrl) {
            $normalizedUrl = $this->normalizeUrl(is_string($candidateUrl) ? $candidateUrl : null);
            if ($normalizedUrl !== null && $this->sourceFromUrl($normalizedUrl) === 'deviantart.com') {
                return $normalizedUrl;
            }
        }

        return null;
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        $withoutFragment = preg_replace('/#.*$/', '', $trimmed);
        $candidate = is_string($withoutFragment) ? trim($withoutFragment) : $trimmed;
        if ($candidate === '') {
            return null;
        }

        $scheme = parse_url($candidate, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return $candidate;
    }

    private function userContainerOverridesFromUrl(string $url, ?string $source): array
    {
        if ($source !== 'deviantart.com') {
            return [];
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return [];
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $username = $segments[0] ?? null;
        if (! is_string($username) || ! $this->isValidDeviantArtUsernameSegment($username)) {
            return [];
        }

        return [
            'user_container_source' => $source,
            'user_container_source_id' => $username,
            'user_container_referrer_url' => "https://www.deviantart.com/{$username}/gallery",
        ];
    }

    private function isValidDeviantArtUsernameSegment(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, [
            'about',
            'art',
            'browse',
            'daily-deviations',
            'gallery',
            'morelikethis',
            'notifications',
            'prints',
            'search',
            'settings',
            'shop',
            'watch',
        ], true)) {
            return false;
        }

        return preg_match('/^[a-z0-9_-]+$/i', $value) === 1;
    }

    private function containerSourceFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost === '') {
            return null;
        }

        if ($normalizedHost === 'deviantart.com' || str_ends_with($normalizedHost, '.deviantart.com')) {
            return 'deviantart.com';
        }

        if ($normalizedHost === 'civitai.com' || str_ends_with($normalizedHost, '.civitai.com')) {
            return CivitAiImages::source();
        }

        return preg_replace('/^www\./', '', $normalizedHost) ?: null;
    }
}
