<?php

namespace App\Services;

use App\Models\Container;
use App\Models\File;
use App\Services\SourceMedia\SourceMediaRefreshService;
use App\Services\SourceMedia\SourceWatchRefreshService;
use App\Support\ContainerBrowseTabPayload;
use App\Support\FileApiPath;
use App\Support\FileMimeType;
use App\Support\SourceAccessState;
use Illuminate\Container\Container as IoCContainer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class FileItemFormatter
{
    private static function toRelativeInternalApiUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '' || ! str_starts_with($path, '/')) {
            return $url;
        }

        $container = IoCContainer::getInstance();
        $requestHost = $container?->bound('request') === true
            ? $container->make('request')->getHost()
            : null;
        if (! is_string($requestHost) || strtolower($requestHost) !== $host) {
            return $url;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    private static function hasLoadedAlbumCoverRelation(File $file): bool
    {
        if (! $file->relationLoaded('albums')) {
            return false;
        }

        return $file->albums->every(
            static fn ($album): bool => $album->relationLoaded('defaultCover')
        );
    }

    private static function audioCoverUrl(File $file): ?string
    {
        if ($file->relationLoaded('albums')) {
            $albumCover = $file->albums
                ->map(static fn ($album) => $album->defaultCover)
                ->filter()
                ->first();

            if ($albumCover) {
                return FileApiPath::albumCover((int) $albumCover->id);
            }
        }

        if (is_string($file->poster_path) && trim($file->poster_path) !== '') {
            return FileApiPath::poster((int) $file->id);
        }

        if (is_string($file->preview_path) && trim($file->preview_path) !== '') {
            return FileApiPath::preview((int) $file->id);
        }

        $previewUrl = trim((string) ($file->preview_url ?? ''));

        return $previewUrl !== '' ? $previewUrl : null;
    }

    /**
     * Format files into items structure for frontend.
     */
    public static function format($files, int|string $page = 1, array $browseContext = []): array
    {
        // Eager load containers relationship to avoid N+1 queries
        if ($files instanceof Collection) {
            $persistedFiles = $files->filter(
                fn (File $file): bool => $file->exists && ! $file->relationLoaded('containers')
            );

            if ($persistedFiles->isNotEmpty()) {
                $persistedFiles->load('containers');
            }

            $audioFilesMissingCovers = $files->filter(
                fn (File $file): bool => $file->exists
                    && FileMimeType::isAudio($file->mime_type)
                    && ! self::hasLoadedAlbumCoverRelation($file)
            );

            if ($audioFilesMissingCovers->isNotEmpty()) {
                $audioFilesMissingCovers->load('albums.defaultCover');
            }
        } elseif ($files instanceof SupportCollection || (is_array($files) && ! empty($files))) {
            $fileList = $files instanceof SupportCollection ? $files->all() : $files;

            $filesNeedingContainers = array_values(array_filter(
                $fileList,
                fn (File $file): bool => $file->exists && ! $file->relationLoaded('containers')
            ));

            if ($filesNeedingContainers !== []) {
                $fileIds = array_map(fn (File $file) => $file->id, $filesNeedingContainers);
                $filesWithContainers = File::query()
                    ->whereIn('id', $fileIds)
                    ->with('containers')
                    ->get()
                    ->keyBy('id');

                // Update files in array with loaded containers
                foreach ($filesNeedingContainers as $file) {
                    if (isset($filesWithContainers[$file->id])) {
                        $file->setRelation('containers', $filesWithContainers[$file->id]->containers);
                    }
                }
            }

            $audioFilesMissingCovers = array_values(array_filter(
                $fileList,
                fn (File $file): bool => $file->exists
                    && FileMimeType::isAudio($file->mime_type)
                    && ! self::hasLoadedAlbumCoverRelation($file)
            ));

            if ($audioFilesMissingCovers !== []) {
                $fileIds = array_map(fn (File $file) => $file->id, $audioFilesMissingCovers);
                $filesWithAlbums = File::query()
                    ->whereIn('id', $fileIds)
                    ->with('albums.defaultCover')
                    ->get()
                    ->keyBy('id');

                foreach ($audioFilesMissingCovers as $file) {
                    if (isset($filesWithAlbums[$file->id])) {
                        $file->setRelation('albums', $filesWithAlbums[$file->id]->albums);
                    }
                }
            }
        }

        $items = [];
        $index = 0;
        $sourceMediaRefreshes = app(SourceMediaRefreshService::class);
        $sourceWatchRefreshes = app(SourceWatchRefreshService::class);

        foreach ($files as $file) {
            // Only extract essential metadata properties needed for masonry display
            // Full metadata will be loaded on-demand when needed (e.g., in FileViewer)
            $metadata = is_array($file->metadata?->payload) ? $file->metadata->payload : (is_string($file->metadata?->payload) ? json_decode($file->metadata->payload, true) : []);

            // Extract only width/height for masonry layout.
            // Prefer FileMetadata payload, but fall back to listing_metadata (extension ingest uses this).
            $listing = is_array($file->listing_metadata) ? $file->listing_metadata : [];
            $width = (int) ($metadata['width'] ?? ($listing['width'] ?? 500));
            $height = (int) ($metadata['height'] ?? ($listing['height'] ?? 500));

            // Support both the normalized payload and older nested CivitAI payloads.
            $prompt = data_get($metadata, 'prompt')
                ?? data_get($metadata, 'meta.prompt')
                ?? data_get($listing, 'meta.prompt')
                ?? data_get($listing, 'meta.meta.prompt');
            if (! is_string($prompt) || trim($prompt) === '') {
                $prompt = null;
            }

            // Ensure containers relation is loaded (even if empty)
            if (! $file->relationLoaded('containers')) {
                $file->load('containers');
            }

            $containers = $file->containers->map(function (Container $container) use ($browseContext) {
                return [
                    'id' => $container->id,
                    'type' => $container->type,
                    'source' => $container->source,
                    'source_id' => $container->source_id,
                    'referrer' => $container->referrer,
                    'browse_tab' => ContainerBrowseTabPayload::build([
                        'id' => $container->id,
                        'type' => $container->type,
                        'source' => $container->source,
                        'source_id' => $container->source_id,
                    ], $browseContext),
                    'action_type' => $container->action_type,
                    'blacklist_previewed_count_mode' => $container->blacklist_previewed_count_mode ?? 'preserve',
                    'blacklisted_at' => $container->blacklisted_at?->toIso8601String(),
                ];
            })->values()->all();

            // Get reaction if loaded
            $reaction = null;
            if ($file->relationLoaded('reaction')) {
                $reactionModel = $file->getRelation('reaction');
                if ($reactionModel) {
                    $reaction = [
                        'type' => $reactionModel->type,
                    ];
                }
            }

            $originalUrl = $file->url;
            $thumbnailUrl = $file->preview_url;

            $isStored = $file->path && ($file->downloaded || $file->imported_at !== null);
            if ($isStored) {
                $originalUrl = $file->downloaded ? FileApiPath::downloaded($file->id) : FileApiPath::serve($file->id);
                if ($file->preview_path) {
                    $thumbnailUrl = FileApiPath::preview($file->id);
                }
            } else {
                if (! $originalUrl && $file->path) {
                    $originalUrl = FileApiPath::serve($file->id);
                }
            }

            $isVideo = FileMimeType::isVideo($file->mime_type);
            $isImage = FileMimeType::isImage($file->mime_type);
            $isAudio = FileMimeType::isAudio($file->mime_type);

            // Vibe currently only knows how to load "image" and "video" items.
            // For audio/other files, keep the real file URL in `original` for the viewer/actions.
            $mediaKind = $isVideo ? 'video' : ($isImage ? 'image' : ($isAudio ? 'audio' : 'file'));
            $vibeType = $isVideo ? 'video' : 'image';

            if ($mediaKind === 'audio') {
                $thumbnailUrl = self::audioCoverUrl($file) ?? FileApiPath::icon($file->id);
            } elseif ($mediaKind !== 'image' && $mediaKind !== 'video') {
                $thumbnailUrl = FileApiPath::icon($file->id);
            }

            $originalUrl = self::toRelativeInternalApiUrl($originalUrl);
            $thumbnailUrl = self::toRelativeInternalApiUrl($thumbnailUrl);

            $item = [
                'id' => $file->id,
                'width' => $width,
                'height' => $height,
                'src' => $thumbnailUrl,
                // Vibe (new) expects preview/original for the internal MasonryLoader.
                // Keep existing src/originalUrl for backward compatibility in the Atlas UI.
                'preview' => $thumbnailUrl,
                'original' => $originalUrl,
                'timeoutSeconds' => 30,
                'originalUrl' => $originalUrl, // Needed for FileViewer to show original images
                'thumbnail' => $thumbnailUrl,
                'url' => $file->url,
                'source' => $file->source,
                'source_id' => $file->source_id,
                'referrer_url' => $file->referrer_url,
                'type' => $vibeType,
                'media_kind' => $mediaKind,
                'mime_type' => $file->mime_type,
                'ext' => $file->ext,
                'filename' => $file->filename,
                'page' => $page,
                'key' => "{$page}-{$file->id}",
                'index' => $index,
                'notFound' => (bool) $file->not_found,
                'previewed_count' => $file->previewed_count ?? 0,
                'seen_count' => $file->seen_count ?? 0,
                'downloaded' => (bool) $file->downloaded,
                'imported_at' => $file->imported_at?->toIso8601String(),
                'auto_blacklisted' => $file->auto_blacklisted ?? false,
                'reaction' => $reaction, // Current user's reaction for this file
                // Include metadata with prompt if available - full metadata loaded on-demand
                'metadata' => $prompt ? ['prompt' => $prompt] : null,
                // listing_metadata removed - loaded on-demand in FileDetailsCard when needed
                'containers' => $containers, // Needed for container badges and reactions
                'source_access' => SourceAccessState::forFile($file),
                'capabilities' => [
                    'refresh_source_media' => $sourceMediaRefreshes->supports($file),
                    'watch_source_and_refresh' => $sourceWatchRefreshes->supports($file),
                    'unwatch_source_account' => $sourceWatchRefreshes->supportsUnwatch($file),
                ],
            ];

            $items[] = $item;
            $index++;
        }

        return $items;
    }
}
