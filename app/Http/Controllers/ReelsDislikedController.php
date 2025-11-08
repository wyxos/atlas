<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\FileListingFormatter;
use App\Support\ListingOptions;
use Illuminate\Http\JsonResponse;
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

        $serviceCache = [];

        $files = collect($ids)->map(function (int $id) use ($models, $reactions, $options, &$serviceCache) {
            $file = $models->get($id);
            $formatted = FileListingFormatter::format(
                $file,
                $reactions,
                $options->page,
                null, // No remote URL decorator needed for reels (all files have path)
                $serviceCache
            );

            if ($formatted && $file) {
                // Add extra fields specific to disliked reels
                $formatted['has_path'] = (bool) $file->path;
                $formatted['downloaded'] = (bool) $file->downloaded;
            }

            return $formatted;
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
