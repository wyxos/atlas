<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\FilePreviewUrl;
use App\Support\ListingOptions;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Laravel\Scout\Builder as ScoutBuilder;

class ReelsDislikedController extends Controller
{
    use InteractsWithListings;

    public function index(string $category)
    {
        $payload = $this->getData($category);

        return Inertia::render('reels/Index', $payload);
    }

    public function data(string $category): JsonResponse
    {
        return response()->json($this->getData($category));
    }

    /**
     * IMPORTANT: This feed MUST use Typesense via Laravel Scout.
     * - Do NOT replace with DB/Eloquent queries.
     * - Sorting relies on blacklisted_at being present in the index as a numeric timestamp.
     */
    protected function getData(string $category): array
    {
        $options = $this->resolveListingOptions([
            'allowed_sorts' => ['newest', 'random'],
            'default_sort' => 'newest',
        ]);

        $userId = $this->currentUserId();

        $query = $this->buildScoutQuery($category, $options);

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);
        $models = $this->loadFilesByIds($ids);
        $reactions = $this->reactionsForUser($ids, $userId);

        $files = collect($ids)->map(function (int $id) use ($models, $reactions, $options) {
            $file = $models->get($id);
            if (! $file) {
                return null;
            }

            $remoteThumbnail = $file->thumbnail_url;
            $mime = (string) ($file->mime_type ?? '');
            $hasPath = (bool) $file->path;
            $original = $hasPath ? route('files.view', ['file' => $id]) : $file->url;
            $localPreview = FilePreviewUrl::for($file);
            $thumbnail = $localPreview ?? $remoteThumbnail;
            $type = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'other'));

            $payload = (array) optional($file->metadata)->payload;
            $width = (int) ($payload['width'] ?? 0);
            $height = (int) ($payload['height'] ?? 0);
            if ($width <= 0 && $height > 0) {
                $width = $height;
            }
            if ($height <= 0 && $width > 0) {
                $height = $width;
            }
            if ($width <= 0) {
                $width = 512;
            }
            if ($height <= 0) {
                $height = 512;
            }

            $listingMetadata = $file->listing_metadata;
            if (! is_array($listingMetadata)) {
                $listingMetadata = is_string($listingMetadata) ? json_decode($listingMetadata, true) ?: [] : [];
            }

            // Calculate absolute disk path
            $absolutePath = null;
            if ($hasPath && $file->path) {
                $path = (string) $file->path;
                foreach (['atlas_app', 'atlas'] as $diskName) {
                    $disk = Storage::disk($diskName);
                    if ($disk->exists($path)) {
                        try {
                            $absolutePath = $disk->path($path);
                            break;
                        } catch (\Throwable $e) {
                            // Continue to next disk
                        }
                    }
                }
            }

            $reaction = $reactions[$id] ?? null;

            return [
                'id' => $id,
                'preview' => $thumbnail,
                'original' => $original,
                'true_original_url' => $file->url ?: null,
                'true_thumbnail_url' => $remoteThumbnail ?: ($localPreview ?? null),
                'referrer_url' => $file->referrer_url ?: null,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => $options->page,
                'has_path' => $hasPath,
                'downloaded' => (bool) $file->downloaded,
                'previewed_count' => (int) ($file->previewed_count ?? 0),
                'seen_count' => (int) ($file->seen_count ?? 0),
                'not_found' => (bool) $file->not_found,
                'containers' => PhotoContainers::forFile($file),
                'listing_metadata' => $listingMetadata,
                'absolute_path' => $absolutePath,
                'metadata' => [
                    'prompt' => data_get(optional($file->metadata)->payload ?? [], 'prompt'),
                    'moderation' => data_get(optional($file->metadata)->payload ?? [], 'moderation'),
                ],
                'loved' => $reaction === 'love',
                'liked' => $reaction === 'like',
                'disliked' => $reaction === 'dislike',
                'funny' => $reaction === 'funny',
            ];
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter($options, $paginator),
        ];
    }

    /**
     * Build the Scout/Typesense query for disliked reels with all constraints.
     */
    protected function buildScoutQuery(string $category, ListingOptions $options): ScoutBuilder
    {
        $reasons = $this->mapReasons($category);

        $userId = (string) ($this->currentUserId() ?? '');
        $mimeType = $this->requestedMimeType();
        $fileId = $this->requestedFileId();
        $sourceId = $this->requestedSourceId();

        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('blacklisted', true)
            ->where('not_found', false);

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        if ($fileId) {
            $query->where('id', (string) $fileId);
        }

        if ($sourceId) {
            $query->where('source_id', (string) $sourceId);
        }

        if ($category === 'auto') {
            $query->whereIn('previewed_count', [0]);
        } else {
            $query->whereIn('previewed_count', [0, 1, 2, 3, 4, 5]);
        }

        if ($reasons !== null) {
            $query->whereIn('blacklist_reason', $reasons);
        }

        if ($category === 'not-disliked' && $userId !== '') {
            $query->whereNotIn('dislike_user_ids', [(string) $userId]);
        }

        $this->applySorting($query, $options, 'blacklisted_at');

        return $query;
    }

    /**
     * Map UI category to blacklist reasons.
     */
    protected function mapReasons(string $category): ?array
    {
        return match ($category) {
            'all' => null, // no reason filter
            'manual' => ['disliked', 'container:batch'],
            'ignored' => ['auto:previewed_threshold'],
            'auto' => ['auto:meta_content', 'moderation:rule'],
            'not-disliked' => null, // handled via dislike_user_ids exclusion
            default => null,
        };
    }
}
