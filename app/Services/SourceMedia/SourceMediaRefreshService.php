<?php

namespace App\Services\SourceMedia;

use App\Enums\SourceMediaUrlPolicy;
use App\Enums\SourceMediaVariant;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SourceMediaRefreshService
{
    private const int EXPIRY_REFRESH_BUFFER_SECONDS = 30;

    private const int DEFAULT_RESOLUTION_CACHE_SECONDS = 300;

    private const int MAX_RESOLUTION_CACHE_SECONDS = 3600;

    private const int FORCED_REFRESH_DEDUPLICATION_SECONDS = 10;

    public function __construct(
        private readonly DeviantArtSourceMediaRefreshResolver $deviantArtResolver,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ) {}

    public function supports(File $file): bool
    {
        return $this->resolverFor($file) !== null;
    }

    public function usesDynamicMediaUrls(File $file): bool
    {
        if ($this->isStoredFile($file)) {
            return false;
        }

        $resolver = $this->resolverFor($file);

        return $resolver !== null && $resolver->mediaUrlPolicy($file)->usesAtlasResolver();
    }

    public function resolveMediaUrl(
        File $file,
        User $user,
        SourceMediaVariant $variant,
        bool $forceRefresh = false,
    ): ?string {
        if ($this->isStoredFile($file)) {
            return null;
        }

        $resolver = $this->resolverFor($file);
        if (! $resolver) {
            return null;
        }

        if ($resolver->mediaUrlPolicy($file) === SourceMediaUrlPolicy::Stable) {
            return $this->validMediaUrl($variant->currentUrl($file));
        }

        $cacheKey = $this->resolutionCacheKey($file, $user);
        $cached = $this->cachedResolution($cacheKey);
        if ($cached !== null && (! $forceRefresh || $this->wasRecentlyResolved($cached))) {
            return $this->urlFromCachedResolution($cached, $variant);
        }

        if (! $forceRefresh && $this->canUseCurrentUrl($resolver, $file, $variant)) {
            return $this->validMediaUrl($variant->currentUrl($file));
        }

        try {
            return Cache::lock("{$cacheKey}:lock", 30)->block(15, function () use (
                $cacheKey,
                $file,
                $forceRefresh,
                $resolver,
                $user,
                $variant,
            ): ?string {
                $cached = $this->cachedResolution($cacheKey);
                if ($cached !== null && (! $forceRefresh || $this->wasRecentlyResolved($cached))) {
                    return $this->urlFromCachedResolution($cached, $variant);
                }

                if (! $forceRefresh && $this->canUseCurrentUrl($resolver, $file, $variant)) {
                    return $this->validMediaUrl($variant->currentUrl($file));
                }

                $media = $resolver->resolve($file, $user);
                if (! $media) {
                    return null;
                }

                $resolvedUrl = $this->urlFromResolvedMedia($media, $variant);
                if ($resolvedUrl === null) {
                    return null;
                }

                $this->cacheResolution($cacheKey, $media);

                return $resolvedUrl;
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function refresh(File $file, User $user): SourceMediaRefreshResult
    {
        $resolver = $this->resolverFor($file);
        if (! $resolver) {
            return new SourceMediaRefreshResult(
                supported: false,
                changed: false,
                message: 'This file source does not support media refresh.',
                file: $file,
            );
        }

        try {
            $media = $resolver->resolve($file, $user);
        } catch (Throwable $exception) {
            report($exception);

            return new SourceMediaRefreshResult(
                supported: true,
                changed: false,
                message: 'Unable to refresh source media from the provider.',
                file: $file->fresh() ?? $file,
            );
        }

        if (! $media) {
            return new SourceMediaRefreshResult(
                supported: true,
                changed: false,
                message: 'No refreshed source media was available.',
                file: $file->fresh() ?? $file,
            );
        }

        $changed = $this->applyMedia($file, $media);
        Cache::forget($this->resolutionCacheKey($file, $user));
        $refreshedFile = $file->fresh() ?? $file;

        return new SourceMediaRefreshResult(
            supported: true,
            changed: $changed,
            message: $changed ? 'Source media refreshed.' : 'Source media is already current.',
            file: $refreshedFile,
        );
    }

    private function resolverFor(File $file): ?SourceMediaRefreshResolver
    {
        foreach ($this->resolvers() as $resolver) {
            if ($resolver->supports($file)) {
                return $resolver;
            }
        }

        return null;
    }

    private function canUseCurrentUrl(
        SourceMediaRefreshResolver $resolver,
        File $file,
        SourceMediaVariant $variant,
    ): bool {
        if ($this->validMediaUrl($variant->currentUrl($file)) === null) {
            return false;
        }

        $expiresAt = $resolver->mediaUrlExpiresAt($file, $variant);

        return $expiresAt === null
            || $expiresAt->isAfter(now()->addSeconds(self::EXPIRY_REFRESH_BUFFER_SECONDS));
    }

    private function isStoredFile(File $file): bool
    {
        return is_string($file->path)
            && trim($file->path) !== ''
            && ((bool) $file->downloaded || $file->imported_at !== null);
    }

    private function resolutionCacheKey(File $file, User $user): string
    {
        return "source-media-resolution:{$user->id}:{$file->id}";
    }

    /** @return array{url:string, preview_url:string|null, resolved_at:int}|null */
    private function cachedResolution(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);
        if (! is_array($cached) || ! isset($cached['url']) || ! is_string($cached['url'])) {
            return null;
        }

        return [
            'url' => $cached['url'],
            'preview_url' => isset($cached['preview_url']) && is_string($cached['preview_url'])
                ? $cached['preview_url']
                : null,
            'resolved_at' => isset($cached['resolved_at']) && is_numeric($cached['resolved_at'])
                ? (int) $cached['resolved_at']
                : 0,
        ];
    }

    /** @param array{url:string, preview_url:string|null, resolved_at:int} $cached */
    private function wasRecentlyResolved(array $cached): bool
    {
        return $cached['resolved_at'] >= now()->subSeconds(self::FORCED_REFRESH_DEDUPLICATION_SECONDS)->timestamp;
    }

    /** @param array{url:string, preview_url:string|null, resolved_at:int} $cached */
    private function urlFromCachedResolution(array $cached, SourceMediaVariant $variant): ?string
    {
        return $this->validMediaUrl(match ($variant) {
            SourceMediaVariant::Original => $cached['url'],
            SourceMediaVariant::Preview => $cached['preview_url'] ?? $cached['url'],
        });
    }

    private function urlFromResolvedMedia(ResolvedSourceMedia $media, SourceMediaVariant $variant): ?string
    {
        return $this->validMediaUrl(match ($variant) {
            SourceMediaVariant::Original => $media->url,
            SourceMediaVariant::Preview => $media->previewUrl ?? $media->url,
        });
    }

    private function validMediaUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function cacheResolution(string $cacheKey, ResolvedSourceMedia $media): void
    {
        Cache::put($cacheKey, [
            'url' => $media->url,
            'preview_url' => $media->previewUrl,
            'resolved_at' => now()->timestamp,
        ], $this->resolutionCacheSeconds($media));
    }

    private function resolutionCacheSeconds(ResolvedSourceMedia $media): int
    {
        $expirationTimestamps = array_values(array_filter([
            $media->urlExpiresAt?->timestamp,
            $media->previewUrlExpiresAt?->timestamp,
        ], static fn (?int $timestamp): bool => $timestamp !== null));

        if ($expirationTimestamps === []) {
            return self::DEFAULT_RESOLUTION_CACHE_SECONDS;
        }

        $secondsUntilRefresh = min($expirationTimestamps)
            - now()->timestamp
            - self::EXPIRY_REFRESH_BUFFER_SECONDS;

        return max(1, min(self::MAX_RESOLUTION_CACHE_SECONDS, $secondsUntilRefresh));
    }

    /**
     * @return list<SourceMediaRefreshResolver>
     */
    private function resolvers(): array
    {
        return [
            $this->deviantArtResolver,
        ];
    }

    private function applyMedia(File $file, ResolvedSourceMedia $media): bool
    {
        $updates = [
            'url' => $media->url,
        ];

        if ($media->previewUrl !== null) {
            $updates['preview_url'] = $media->previewUrl;
        }

        if ($media->size !== null) {
            $updates['size'] = $media->size;
        }

        if ($media->ext !== null) {
            $updates['ext'] = $media->ext;
        }

        if ($media->mimeType !== null) {
            $updates['mime_type'] = $media->mimeType;
        }

        if ($media->listingMetadata !== []) {
            $updates['listing_metadata'] = $media->listingMetadata;
        }

        $changed = false;
        foreach ($updates as $key => $value) {
            if ($file->{$key} !== $value) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $file->forceFill($updates)->save();
        }

        $metadataChanged = $this->applyMetadata($file, $media);

        if ($changed || $metadataChanged) {
            $this->libraryIndexSyncDispatcher->files([(int) $file->id]);
        }

        return $changed || $metadataChanged;
    }

    private function applyMetadata(File $file, ResolvedSourceMedia $media): bool
    {
        if ($media->metadataPayload === []) {
            return false;
        }

        $metadata = FileMetadata::query()->firstOrNew([
            'file_id' => $file->id,
        ]);

        if ($metadata->exists && $metadata->payload === $media->metadataPayload) {
            return false;
        }

        $metadata->payload = $media->metadataPayload;
        $metadata->save();

        return true;
    }
}
