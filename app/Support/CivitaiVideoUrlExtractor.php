<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Facades\Http;

class CivitaiVideoUrlExtractor
{
    public function extractFromFileId(int $fileId): ?string
    {
        $file = File::find($fileId);
        if (! $file) {
            return null;
        }

        $referrerUrl = (string) ($file->referrer_url ?? '');
        if ($referrerUrl === '') {
            return null;
        }

        return $this->extractFromReferrerUrl($referrerUrl);
    }

    public function extractFromReferrerUrl(string $referrerUrl): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($referrerUrl);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            $referrerBase = $this->getBaseUrl($referrerUrl);

            // Look for <source> tags with mp4 files
            // Pattern: <source src="..." ...> or <source ... src="...">
            if (preg_match_all('/<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $html, $matches)) {
                $foundUrls = [];
                foreach ($matches[1] as $url) {
                    // Resolve relative URLs
                    $absoluteUrl = $this->resolveUrl($url, $referrerBase);
                    $foundUrls[] = $absoluteUrl;

                    // Prefer URLs with transcode parameters (higher quality)
                    if (str_contains($absoluteUrl, 'transcode=true')) {
                        return $absoluteUrl;
                    }
                }

                // Fall back to first mp4 URL found
                return $foundUrls[0] ?? null;
            }

            // Also check for video tags with source children
            if (preg_match_all('/<video[^>]*>(.*?)<\/video>/is', $html, $videoMatches)) {
                $foundUrls = [];
                foreach ($videoMatches[1] as $videoContent) {
                    if (preg_match('/<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $videoContent, $srcMatches)) {
                        $absoluteUrl = $this->resolveUrl($srcMatches[1], $referrerBase);
                        $foundUrls[] = $absoluteUrl;

                        if (str_contains($absoluteUrl, 'transcode=true')) {
                            return $absoluteUrl;
                        }
                    }
                }

                // Fall back to first found URL
                if (! empty($foundUrls)) {
                    return $foundUrls[0];
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (! $parsed) {
            return $url;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return $scheme.'://'.$host;
    }

    protected function resolveUrl(string $url, string $baseUrl): string
    {
        // If already absolute, return as-is
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Resolve relative URL
        $parsedBase = parse_url($baseUrl);
        if (! $parsedBase) {
            return $url;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        // If URL starts with /, it's absolute path
        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        // Relative path - need to resolve against base path
        $basePath = $parsedBase['path'] ?? '/';
        $baseDir = dirname($basePath);
        if ($baseDir === '.') {
            $baseDir = '/';
        }

        return $scheme.'://'.$host.rtrim($baseDir, '/').'/'.ltrim($url, '/');
    }
}
