<?php

namespace App\Services\Extension;

use App\Services\CivitAiImages;
use App\Support\CivitAiMediaUrl;

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

        if ($normalizedHost === CivitAiMediaUrl::PRIMARY_PAGE_HOST
            || str_ends_with($normalizedHost, '.'.CivitAiMediaUrl::PRIMARY_PAGE_HOST)
            || $normalizedHost === CivitAiMediaUrl::NSFW_PAGE_HOST
            || str_ends_with($normalizedHost, '.'.CivitAiMediaUrl::NSFW_PAGE_HOST)) {
            return CivitAiImages::source();
        }

        return preg_replace('/^www\./', '', $normalizedHost) ?: null;
    }
}
