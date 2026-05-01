<?php

namespace App\Services\Extension;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use App\Services\CivitAiImages;
use App\Services\FileReactionService;
use App\Support\CivitAiMediaUrl;
use App\Support\FileTypeDetector;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExtensionReactionProcessor
{
    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $runtimeContext
     * @param  array<string, mixed>  $listingMetadataOverrides
     * @return array<string, mixed>
     */
    public function process(
        array $item,
        string $reactionType,
        string $downloadBehavior,
        FileReactionService $fileReactionService,
        ExtensionContainerMetadataService $containerMetadataService,
        User $user,
        string $extensionChannel,
        array $runtimeContext,
        array $listingMetadataOverrides = [],
    ): array {
        $url = $this->normalizeUrl($item['url'] ?? null);
        if ($url === null) {
            throw ValidationException::withMessages([
                'url' => 'A valid media URL is required.',
            ]);
        }

        $referrerUrl = $this->normalizeOptionalUrl($item['referrer_url_hash_aware'] ?? null)
            ?? $this->normalizeOptionalUrl($item['referrer_url'] ?? null);
        $previewUrl = $url;
        $pageUrl = $this->normalizeOptionalUrl($item['page_url'] ?? null);
        $tagName = isset($item['tag_name']) && is_string($item['tag_name']) ? $item['tag_name'] : null;
        $source = $containerMetadataService->sourceFromCandidateUrls([$referrerUrl, $pageUrl, $url]) ?? 'extension';

        $file = $this->findOrCreateFile(
            $url,
            $source,
            $referrerUrl,
            $previewUrl,
            $extensionChannel,
            (int) $user->id,
            $pageUrl,
            $tagName,
            $listingMetadataOverrides
        );
        $queueDownload = $this->shouldQueueExtensionDownload($reactionType, $downloadBehavior);
        $forceDownload = $this->shouldForceExtensionDownload($downloadBehavior);
        $result = $fileReactionService->set(
            $file,
            $user,
            $reactionType,
            [
                'queueDownload' => $queueDownload,
                'forceDownload' => $forceDownload,
                'downloadRuntimeContext' => $runtimeContext,
            ]
        );
        $activeTransfer = $this->findActiveTransfer($file->id);

        return [
            'file' => [
                'id' => $file->id,
                'url' => $file->url,
                'referrer_url' => $file->referrer_url,
                'preview_url' => $file->preview_url,
            ],
            'reaction' => $result['reaction'],
            'reacted_at' => $result['reacted_at'] ?? null,
            'download' => [
                'requested' => $queueDownload,
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
        ];
    }

    private function normalizeUrl(mixed $url): ?string
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

    private function normalizeOptionalUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function findOrCreateFile(
        string $url,
        string $source,
        ?string $referrerUrl,
        ?string $previewUrl,
        string $extensionChannel,
        int $extensionUserId,
        ?string $pageUrl,
        ?string $tagName,
        array $listingMetadataOverrides = [],
    ): File {
        $downloadVia = $this->shouldUseYtDlp($url, $pageUrl, $tagName) ? 'yt-dlp' : null;
        $rawCanonicalUrl = $downloadVia === 'yt-dlp' && $pageUrl !== null ? $pageUrl : $url;
        $identity = $this->resolveExtensionFileIdentity(
            $rawCanonicalUrl,
            $source,
            $referrerUrl,
            $pageUrl,
            $tagName
        );
        $canonicalUrl = $identity['url'];
        $sourceId = $identity['source_id'];
        $urlHash = hash('sha256', $canonicalUrl);
        $listingMetadata = array_filter([
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUserId,
            'page_url' => $pageUrl,
            'tag_name' => $tagName,
            'download_via' => $downloadVia,
            ...$listingMetadataOverrides,
        ], static fn ($value) => match (true) {
            $value === null => false,
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            default => true,
        });

        $file = $this->findExistingFileForExtensionIdentity($urlHash, $canonicalUrl, $source, $sourceId, $referrerUrl);

        if (! $file) {
            return File::query()->create([
                'source' => $source,
                'source_id' => $sourceId,
                'url' => $canonicalUrl,
                'referrer_url' => $referrerUrl,
                'preview_url' => $previewUrl,
                'listing_metadata' => $listingMetadata,
                'filename' => Str::random(40),
                'ext' => FileTypeDetector::extensionFromUrl($canonicalUrl),
            ]);
        }

        $updates = [];
        $currentSource = strtolower(trim((string) $file->source));
        if (($currentSource === '' || $currentSource === 'extension') && $file->source !== $source) {
            $updates['source'] = $source;
        }
        if ($sourceId !== null && trim((string) ($file->source_id ?? '')) === '') {
            $updates['source_id'] = $sourceId;
        }
        $duplicateCanonicalUrlExists = $file->url !== $canonicalUrl
            && File::query()
                ->where('id', '!=', $file->id)
                ->where('url_hash', $urlHash)
                ->exists();

        if ($file->url !== $canonicalUrl && ! $duplicateCanonicalUrlExists) {
            $updates['url'] = $canonicalUrl;
        }
        if ($referrerUrl !== null && $file->referrer_url !== $referrerUrl) {
            $updates['referrer_url'] = $referrerUrl;
        }
        if ($file->preview_url === null && $previewUrl !== null) {
            $updates['preview_url'] = $previewUrl;
        }
        $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $listingChanged = false;
        foreach ($listingMetadataOverrides + [
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUserId,
            'page_url' => $pageUrl,
            'tag_name' => $tagName,
            'download_via' => $downloadVia,
        ] as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                continue;
            }

            if (($listingMetadata[$key] ?? null) === $value) {
                continue;
            }

            $listingMetadata[$key] = $value;
            $listingChanged = true;
        }
        if ($listingChanged) {
            $updates['listing_metadata'] = $listingMetadata;
        }

        if ($updates !== []) {
            $file->update($updates);
        }

        return $file;
    }

    /**
     * @return array{url: string, source_id: string|null}
     */
    private function resolveExtensionFileIdentity(
        string $url,
        string $source,
        ?string $referrerUrl,
        ?string $pageUrl,
        ?string $tagName,
    ): array {
        if ($source !== CivitAiImages::SOURCE) {
            return [
                'url' => $url,
                'source_id' => null,
            ];
        }

        $sourceId = $this->extractCivitAiImageIdFromCandidateUrls([$referrerUrl, $pageUrl]);
        if ($sourceId === null) {
            return [
                'url' => $url,
                'source_id' => null,
            ];
        }

        return [
            'url' => $this->canonicalizeCivitAiMediaUrl($url, $sourceId, $tagName) ?? $url,
            'source_id' => $sourceId,
        ];
    }

    private function findExistingFileForExtensionIdentity(
        string $urlHash,
        string $canonicalUrl,
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

        if ($source !== CivitAiImages::SOURCE) {
            return null;
        }

        if ($referrerUrl !== null) {
            $file = File::query()
                ->where('source', CivitAiImages::SOURCE)
                ->where('referrer_url_hash', hash('sha256', $referrerUrl))
                ->orderByDesc('downloaded')
                ->latest('updated_at')
                ->first();
            if ($file) {
                return $file;
            }
        }

        if ($sourceId === null) {
            return null;
        }

        return File::query()
            ->where('source', CivitAiImages::SOURCE)
            ->where('source_id', $sourceId)
            ->orderByDesc('downloaded')
            ->latest('updated_at')
            ->first();
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

    private function findActiveTransfer(int $fileId): ?DownloadTransfer
    {
        return DownloadTransfer::query()
            ->select(['id', 'status', 'last_broadcast_percent'])
            ->where('file_id', $fileId)
            ->whereIn('status', [
                DownloadTransferStatus::PENDING,
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
                DownloadTransferStatus::DOWNLOADING,
                DownloadTransferStatus::ASSEMBLING,
                DownloadTransferStatus::PREVIEWING,
                DownloadTransferStatus::PAUSED,
            ])
            ->latest('id')
            ->first();
    }

    private function shouldQueueExtensionDownload(string $reactionType, string $downloadBehavior): bool
    {
        return $downloadBehavior !== 'skip';
    }

    private function shouldForceExtensionDownload(string $downloadBehavior): bool
    {
        return $downloadBehavior === 'force';
    }

    private function shouldUseYtDlp(string $url, ?string $pageUrl, ?string $tagName): bool
    {
        if ($tagName !== 'video' && $tagName !== 'iframe') {
            return false;
        }

        $videoPlatformHosts = [
            'x.com',
            'twitter.com',
            'facebook.com',
            'fb.watch',
            'youtube.com',
            'youtu.be',
            'instagram.com',
            'tiktok.com',
            'vimeo.com',
        ];

        $hosts = array_values(array_filter([
            parse_url($url, PHP_URL_HOST),
            parse_url((string) $pageUrl, PHP_URL_HOST),
        ], static fn ($host) => is_string($host) && $host !== ''));

        foreach ($hosts as $host) {
            $normalizedHost = strtolower($host);
            foreach ($videoPlatformHosts as $platformHost) {
                if ($normalizedHost === $platformHost || str_ends_with($normalizedHost, '.'.$platformHost)) {
                    return true;
                }
            }
        }

        return false;
    }
}
