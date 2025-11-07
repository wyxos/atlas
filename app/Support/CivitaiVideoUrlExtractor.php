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

            if ($response->status() === 404) {
                // Return special marker to indicate 404
                return '404_NOT_FOUND';
            }

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            if (empty($html)) {
                return null;
            }

            $referrerBase = $this->getBaseUrl($referrerUrl);
            $foundUrls = [];

            // Try to extract video URL from __NEXT_DATA__ JSON (for React/Next.js apps)
            if (preg_match('/<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $nextDataMatches)) {
                $jsonData = json_decode($nextDataMatches[1], true);
                if ($jsonData && isset($jsonData['props']['pageProps']['trpcState']['json']['queries'])) {
                    foreach ($jsonData['props']['pageProps']['trpcState']['json']['queries'] as $query) {
                        if (isset($query['state']['data']['url']) && isset($query['state']['data']['type']) && $query['state']['data']['type'] === 'video') {
                            $videoUuid = $query['state']['data']['url'];
                            $videoName = $query['state']['data']['name'] ?? 'video.mp4';

                            // Construct possible video URLs based on CivitAI's URL pattern
                            // Pattern: https://image.civitai.com/{hash}/{uuid}/transcode=true,original=true,quality=90/{filename}
                            $transcodedUrl = "https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$videoUuid}/transcode=true,original=true,quality=90/{$videoName}";
                            $originalUrl = "https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$videoUuid}/original=true/{$videoName}";

                            $foundUrls[] = $transcodedUrl;
                            $foundUrls[] = $originalUrl;
                        }
                    }
                }
            }

            // Try multiple patterns to find <source> tags with mp4 files
            // Pattern 1: <source src="..." ...> - src attribute with whitespace before it
            // Handles both self-closing and non-self-closing tags
            if (preg_match_all('/<source[^>]*\s+src\s*=\s*["\']([^"\']*\.mp4[^"\']*)["\'][^>]*>/i', $html, $matches)) {
                foreach ($matches[1] as $url) {
                    if (empty($url)) {
                        continue;
                    }
                    $absoluteUrl = $this->resolveUrl($url, $referrerBase);
                    if (! in_array($absoluteUrl, $foundUrls, true)) {
                        $foundUrls[] = $absoluteUrl;
                    }
                }
            }

            // Pattern 2: <source src="..." ...> - src attribute immediately after tag name
            if (preg_match_all('/<source\s+src\s*=\s*["\']([^"\']*\.mp4[^"\']*)["\'][^>]*>/i', $html, $matches)) {
                foreach ($matches[1] as $url) {
                    if (empty($url)) {
                        continue;
                    }
                    $absoluteUrl = $this->resolveUrl($url, $referrerBase);
                    if (! in_array($absoluteUrl, $foundUrls, true)) {
                        $foundUrls[] = $absoluteUrl;
                    }
                }
            }

            // Pattern 3: Check inside <video> tags specifically (more specific context)
            if (preg_match_all('/<video[^>]*>(.*?)<\/video>/is', $html, $videoMatches)) {
                foreach ($videoMatches[1] as $videoContent) {
                    if (preg_match_all('/<source[^>]*\s+src\s*=\s*["\']([^"\']*\.mp4[^"\']*)["\'][^>]*>/i', $videoContent, $srcMatches)) {
                        foreach ($srcMatches[1] as $url) {
                            if (empty($url)) {
                                continue;
                            }
                            $absoluteUrl = $this->resolveUrl($url, $referrerBase);
                            if (! in_array($absoluteUrl, $foundUrls, true)) {
                                $foundUrls[] = $absoluteUrl;
                            }
                        }
                    }
                }
            }

            // Pattern 4: Try to find any URL containing .mp4 in the HTML (fallback for unusual structures)
            // This is a last resort and less specific
            // BUT: Exclude URLs with only "original=true" as these are often posters, not actual videos
            if (empty($foundUrls) && preg_match_all('/https?:\/\/[^"\'\s<>]+\.mp4[^"\'\s<>]*/i', $html, $matches)) {
                foreach ($matches[0] as $url) {
                    if (str_contains($url, 'image.civitai.com') && ! in_array($url, $foundUrls, true)) {
                        // Skip URLs that only have "original=true" without "transcode" - these are likely posters
                        if (str_contains($url, 'original=true') && ! str_contains($url, 'transcode')) {
                            continue;
                        }
                        $foundUrls[] = $url;
                    }
                }
            }

            if (empty($foundUrls)) {
                return null;
            }

            // Prefer URLs with transcode parameters (higher quality, actual video)
            foreach ($foundUrls as $url) {
                if (str_contains($url, 'transcode=true')) {
                    return $url;
                }
            }

            // Avoid URLs with only "original=true" (these are often posters)
            // Only use them if no transcoded URLs are available
            foreach ($foundUrls as $url) {
                if (! str_contains($url, 'original=true') || str_contains($url, 'transcode')) {
                    return $url;
                }
            }

            // Last resort: fall back to first mp4 URL found (even if it might be a poster)
            return $foundUrls[0];
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
