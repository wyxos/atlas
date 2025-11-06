<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\PhotoListingFormatter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class PhotosReactionsController extends Controller
{
    use InteractsWithListings;

    protected const KIND_TO_FIELD = [
        'favorites' => 'love_user_ids',
        'liked' => 'like_user_ids',
        'funny' => 'funny_user_ids',
        'disliked' => 'dislike_user_ids',
    ];

    protected const KIND_TITLES = [
        'favorites' => 'Favorites',
        'liked' => 'Liked',
        'funny' => 'Funny',
        'disliked' => 'Disliked (mine)',
    ];

    public function index(string $kind)
    {
        $this->validateKind($kind);
        $payload = $this->getData($kind);

        return Inertia::render('photos/Index', $payload);
    }

    public function data(string $kind): JsonResponse
    {
        $this->validateKind($kind);

        return response()->json($this->getData($kind));
    }

    protected function validateKind(string $kind): void
    {
        if (! array_key_exists($kind, self::KIND_TO_FIELD)) {
            abort(404);
        }
    }

    protected function getData(string $kind): array
    {
        $options = $this->resolveListingOptions([
            'allowed_sorts' => ['newest', 'oldest', 'random'],
            'default_sort' => 'newest',
        ]);

        $userId = $this->currentUserId();
        $mimeType = $this->requestedMimeType();
        $field = self::KIND_TO_FIELD[$kind];

        $query = File::search('*')
            ->where('mime_group', 'image')
            ->where('has_path', true)
            ->where('blacklisted', false);

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        if ($userId) {
            $query->whereIn($field, [(string) $userId]);
        }

        switch ($options->sort) {
            case 'random':
                $query->orderBy('_rand('.$options->randSeed.')', 'desc');
                break;
            case 'oldest':
                $query->orderBy('downloaded_at', 'asc')->orderBy('created_at', 'asc');
                break;
            default:
                $query->orderBy('downloaded_at', 'desc')->orderBy('created_at', 'desc');
                break;
        }

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);
        $models = $this->loadFilesByIds($ids);
        $reactions = $this->reactionsForUser($ids, $userId);

        $serviceCache = [];

        $files = collect($ids)->map(function (int $id) use ($models, $reactions, $options, &$serviceCache) {
            $file = $models->get($id);

            return PhotoListingFormatter::format(
                $file,
                $reactions,
                $options->page,
                static fn (File $file, string $url, array &$cache): string => $url,
                $serviceCache
            );
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter(
                $options,
                $paginator,
                [
                    'title' => self::KIND_TITLES[$kind] ?? ucfirst($kind),
                ]
            ),
        ];
    }
}
