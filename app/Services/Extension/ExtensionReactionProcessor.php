<?php

namespace App\Services\Extension;

use App\Models\File;
use App\Models\User;
use App\Services\DeviantArtImages;
use App\Services\FileBlacklistService;
use App\Services\FilePreviewService;
use App\Services\FileReactionService;
use App\Support\DeviantArtPageUrl;
use App\Support\FileTypeDetector;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExtensionReactionProcessor
{
    public function __construct(
        private readonly ExtensionActiveTransferLookup $activeTransferLookup,
        private readonly ExtensionAssetMatchIdentityService $assetMatchIdentities,
        private readonly ExtensionFileIdentityResolver $identityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $runtimeContext
     * @param  array<string, mixed>  $listingMetadataOverrides
     * @param  array{loadActiveTransfer?: bool, queueLibrarySync?: bool}  $options
     * @return array<string, mixed>
     */
    public function process(
        array $item,
        string $reactionType,
        string $downloadBehavior,
        FileReactionService $fileReactionService,
        FileBlacklistService $fileBlacklistService,
        ExtensionContainerMetadataService $containerMetadataService,
        User $user,
        string $extensionChannel,
        array $runtimeContext,
        array $listingMetadataOverrides = [],
        array $options = [],
    ): array {
        $file = $this->fileForExtensionItem(
            $item,
            $containerMetadataService,
            $user,
            $extensionChannel,
            $listingMetadataOverrides,
        );
        $loadActiveTransfer = $options['loadActiveTransfer'] ?? true;
        if ($reactionType === 'blacklist') {
            return $this->blacklistFile($file, $fileBlacklistService, $user, $loadActiveTransfer);
        }

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
                'queueLibrarySync' => $options['queueLibrarySync'] ?? true,
            ]
        );
        $activeTransfer = $loadActiveTransfer ? $this->activeTransferLookup->forFileId((int) $file->id) : null;

        return [
            'file' => [
                'atlas_url' => url("/browse/file/{$file->id}"),
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
                'downloaded_at' => $activeTransfer ? null : $file->downloaded_at?->toIso8601String(),
            ],
            'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $listingMetadataOverrides
     */
    public function fileForExtensionItem(
        array $item,
        ExtensionContainerMetadataService $containerMetadataService,
        User $user,
        string $extensionChannel,
        array $listingMetadataOverrides = [],
    ): File {
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
        if ($source === DeviantArtImages::SOURCE) {
            $referrerUrl = $this->normalizeDeviantArtReferrerUrl($referrerUrl);
            $pageUrl = $this->normalizeDeviantArtReferrerUrl($pageUrl);
        }

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
        $this->assetMatchIdentities->upsertForFile($file, $item['match_identity'] ?? null);

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function blacklistFile(
        File $file,
        FileBlacklistService $fileBlacklistService,
        User $user,
        bool $loadActiveTransfer = true,
    ): array {
        $file->loadMissing('reactions');
        $fileBlacklistService->apply(
            [$file],
            (int) $user->id,
            minimumPreviewedCount: FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
            autoBlacklisted: false,
            queueContainerAutoBlacklistEvaluation: true,
        );
        $file->refresh();
        $activeTransfer = $loadActiveTransfer ? $this->activeTransferLookup->forFileId((int) $file->id) : null;

        return [
            'file' => [
                'atlas_url' => url("/browse/file/{$file->id}"),
                'id' => $file->id,
                'url' => $file->url,
                'referrer_url' => $file->referrer_url,
                'preview_url' => $file->preview_url,
            ],
            'reaction' => null,
            'reacted_at' => null,
            'download' => [
                'requested' => false,
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $activeTransfer ? null : $file->downloaded_at?->toIso8601String(),
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

    private function normalizeDeviantArtReferrerUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return DeviantArtPageUrl::normalize($url) ?? $url;
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
        $identity = $this->identityResolver->resolve(
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

        $file = $this->findExistingFileForExtensionIdentity($urlHash, $source, $sourceId, $referrerUrl);

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
        if ($referrerUrl !== null
            && $file->referrer_url !== $referrerUrl
            && ! $this->identityResolver->referrerUrlsAreEquivalent($source, $file->referrer_url, $referrerUrl)) {
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

    private function findExistingFileForExtensionIdentity(
        string $urlHash,
        string $source,
        ?string $sourceId,
        ?string $referrerUrl,
    ): ?File {
        return $this->identityResolver->findExisting($urlHash, $source, $sourceId, $referrerUrl);
    }

    /**
     * @param  array<int, mixed>  $fileIds
     * @return array<int, \App\Models\DownloadTransfer>
     */
    public function activeTransfersByFileId(array $fileIds): array
    {
        return $this->activeTransferLookup->byFileId($fileIds);
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
        return in_array($tagName, ['video', 'iframe'], true);
    }
}
