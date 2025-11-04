<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ReelsReactionsController extends Controller
{
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
        $limit = max(1, min(200, (int) request('limit', 40)));
        $page = max(1, (int) request('page', 1));
        $sort = (string) request('sort', 'newest');
        $randSeed = request('rand_seed');

        $userId = optional(request()->user())->id;
        $field = self::KIND_TO_FIELD[$kind];

        // Base Scout search: videos that exist locally
        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('has_path', true)
            ->where('blacklisted', false);

        // Filter by the per-user reaction array; use driver-provided whereIn for array fields
        if ($userId) {
            $query->whereIn($field, [(string) $userId]);
        }

        // Sorting (newest by default; support seeded random)
        if ($sort === 'random') {
            if (! is_numeric($randSeed) || (int) $randSeed <= 0) {
                try {
                    $randSeed = random_int(1, 2147483646);
                } catch (\Throwable $e) {
                    $randSeed = mt_rand(1, 2147483646);
                }
            } else {
                $randSeed = (int) $randSeed;
            }
            $query->orderBy('_rand('.$randSeed.')', 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $paginator = $query->paginate($limit, 'page', $page);

        $items = collect($paginator->items() ?? [])->values();
        $ids = $items->map(fn ($f) => (int) ($f['id'] ?? $f->id ?? 0))->filter()->values()->all();

        // Eager-load models + metadata
        $models = empty($ids)
            ? collect([])
            : File::with('metadata')->whereIn('id', $ids)->get()->keyBy('id');

        // Build map of reactions for current user to populate flags
        $reactions = [];
        if ($userId && ! empty($ids)) {
            $reactions = Reaction::query()
                ->whereIn('file_id', $ids)
                ->where('user_id', $userId)
                ->pluck('type', 'file_id')
                ->toArray();
        }

        // Preserve TS order
        $files = collect($ids)->map(function (int $id) use ($models, $reactions) {
            $file = $models->get($id);
            if (! $file) {
                return null;
            }

            $thumbnail = $file->thumbnail_url;
            $mime = (string) ($file->mime_type ?? '');
            $hasPath = (bool) $file->path;
            $original = $hasPath ? (URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id])) : null;
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

            $rt = $reactions[$id] ?? null;

            return [
                'id' => $id,
                'preview' => $original ?? $thumbnail,
                'original' => $original,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => (int) request('page', 1),
                'containers' => PhotoContainers::forFile($file),
                'loved' => $rt === 'love',
                'liked' => $rt === 'like',
                'disliked' => $rt === 'dislike',
                'funny' => $rt === 'funny',
            ];
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => [
                'page' => $page,
                'next' => $paginator->hasMorePages() ? ($page + 1) : null,
                'limit' => $limit,
                'data_url' => route('reels.reactions.data', ['kind' => $kind]),
                'title' => self::KIND_TITLES[$kind] ?? ucfirst($kind),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => ($sort === 'newest') ? null : (int) $randSeed,
            ],
        ];
    }
}
