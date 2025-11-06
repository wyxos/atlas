<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\ListingOptions;
use App\Support\PhotoListingFormatter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Scout\Builder as ScoutBuilder;

class PhotosDislikedController extends Controller
{
    use DecoratesRemoteUrls;
    use InteractsWithListings;

    public function __construct(private PluginServiceResolver $serviceResolver) {}

    public function index(string $category)
    {
        $payload = $this->getData($category);

        return Inertia::render('photos/Index', $payload);
    }

    public function data(string $category): JsonResponse
    {
        return response()->json($this->getData($category));
    }

    /**
     * IMPORTANT: This feed MUST use Typesense via Laravel Scout.
     * - Do NOT replace with DB/Eloquent queries (400k+ docs; must leverage the search engine).
     * - Sorting relies on blacklisted_at being present in the index as a numeric timestamp.
     */
    protected function getData(string $category): array
    {
        $options = $this->resolveListingOptions([
            'allowed_sorts' => ['newest', 'oldest', 'random'],
            'default_sort' => 'newest',
        ]);

        $userId = $this->currentUserId();
        $mimeType = $this->requestedMimeType();

        $query = $this->buildScoutQuery($category, $options, $mimeType);

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);
        $models = $this->loadFilesByIds($ids);
        $reactions = $this->reactionsForUser($ids, $userId);

        $serviceCache = [];

        $files = collect($ids)->map(function (int $id) use ($models, $reactions, &$serviceCache, $options) {
            /** @var File|null $file */
            $file = $models->get($id);

            $formatted = PhotoListingFormatter::format(
                $file,
                $reactions,
                $options->page,
                fn (File $file, string $url, array &$cache): string => $this->decorateRemoteUrl($file, $url, $cache),
                $serviceCache
            );

            if (! $formatted || ! $file) {
                return null;
            }

            return array_merge($formatted, [
                'has_path' => (bool) $file->path,
                'downloaded' => (bool) $file->downloaded,
                'previewed_count' => (int) ($file->previewed_count ?? 0),
            ]);
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter($options, $paginator),
        ];
    }

    /**
     * Build the Scout/Typesense query for disliked photos with all constraints.
     */
    protected function buildScoutQuery(string $category, ListingOptions $options, ?string $mimeType): ScoutBuilder
    {
        $reasons = $this->mapReasons($category);

        $userId = (string) ($this->currentUserId() ?? '');

        $query = File::search('*')
            ->where('mime_group', 'image')
            ->where('blacklisted', true)
            ->where('not_found', false);

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        if ($category === 'auto') {
            $query->whereIn('previewed_count', [0]);
        } else {
            $query->whereIn('previewed_count', [0, 1, 2, 3, 4]);
        }

        if ($reasons !== null) {
            $query->whereIn('blacklist_reason', $reasons);
        }

        if ($category === 'not-disliked' && $userId !== '') {
            $query->whereNotIn('dislike_user_ids', [(string) $userId]);
        }

        $sort = $options->sort;

        if ($sort === 'random') {
            $query->orderBy('_rand('.$options->randSeed.')', 'desc');
        } elseif ($sort === 'oldest') {
            $query->orderBy('blacklisted_at', 'asc')->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('blacklisted_at', 'desc')->orderBy('created_at', 'desc');
        }

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
