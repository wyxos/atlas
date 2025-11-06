<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\FilePreviewUrl;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ReelsReactionsController extends Controller
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

        return Inertia::render('reels/Index', $payload);
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
            'allowed_sorts' => ['newest', 'random'],
            'default_sort' => 'newest',
        ]);

        $userId = $this->currentUserId();
        $field = self::KIND_TO_FIELD[$kind];

        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('has_path', true)
            ->where('blacklisted', false);

        if ($userId) {
            $query->whereIn($field, [(string) $userId]);
        }

        $this->applySorting($query, $options, null, 'created_at');

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
            $original = $hasPath ? URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id]) : null;
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

            $reaction = $reactions[$id] ?? null;

            return [
                'id' => $id,
                'preview' => $original ?? $thumbnail,
                'original' => $original,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => $options->page,
                'containers' => PhotoContainers::forFile($file),
                'loved' => $reaction === 'love',
                'liked' => $reaction === 'like',
                'disliked' => $reaction === 'dislike',
                'funny' => $reaction === 'funny',
            ];
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => [
                'page' => $options->page,
                'next' => $paginator->hasMorePages() ? ($options->page + 1) : null,
                'limit' => $options->limit,
                'data_url' => route('reels.reactions.data', ['kind' => $kind]),
                'title' => self::KIND_TITLES[$kind] ?? ucfirst($kind),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $options->sort,
                'rand_seed' => $options->isRandom() ? $options->randSeed : null,
            ],
        ];
    }
}
