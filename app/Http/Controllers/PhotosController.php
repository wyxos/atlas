<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\PhotoListingFormatter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class PhotosController extends Controller
{
    use DecoratesRemoteUrls;
    use InteractsWithListings;

    public function __construct(private PluginServiceResolver $serviceResolver) {}

    public function index()
    {
        // Initial page render with first batch via data() to keep one source of truth
        $payload = $this->getData();

        return Inertia::render('photos/Index', $payload);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->getData());
    }

    protected function getData(): array
    {
        $options = $this->resolveListingOptions([
            'allowed_sorts' => ['newest', 'oldest', 'random'],
            'default_sort' => 'newest',
        ]);

        $sort = $options->sort;
        $randSeed = $options->randSeed;
        $source = $this->normalizeSource(request('source'));
        $mimeType = $this->requestedMimeType();
        $userId = $this->currentUserId();

        $query = File::search('*')
            ->where('mime_group', 'image');

        if ($userId) {
            $query->whereNotIn('dislike_user_ids', [(string) $userId])
                ->query(function ($eloquent) use ($userId) {
                    $eloquent->whereDoesntHave('reactions', function ($relation) use ($userId) {
                        $relation->where('user_id', $userId)->where('type', 'dislike');
                    });
                });
        }

        if ($source) {
            if ($source === 'local') {
                $query->where('source', 'local');
            } else {
                $query->where('source', $source)
                    ->where('has_reactions', true);
            }
        }

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        switch ($sort) {
            case 'newest':
                $query->orderBy('downloaded_at', 'desc')->orderBy('created_at', 'desc');
                break;
            case 'oldest':
                $query->orderBy('downloaded_at', 'asc')->orderBy('created_at', 'asc');
                break;
            case 'random':
                $query->orderBy('_rand('.$randSeed.')', 'desc');
                break;
        }

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $idList = $this->extractIdsFromPaginator($paginator);

        $reactions = $this->reactionsForUser($idList, $userId);
        $models = $this->loadFilesByIds($idList);

        $serviceCache = [];

        $files = collect($idList)->map(function (int $id) use ($models, $reactions, &$serviceCache, $options) {
            /** @var File|null $file */
            $file = $models->get($id);

            return PhotoListingFormatter::format(
                $file,
                $reactions,
                $options->page,
                fn (File $file, string $url, array &$cache): string => $this->decorateRemoteUrl($file, $url, $cache),
                $serviceCache
            );
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter($options, $paginator),
        ];
    }
}
