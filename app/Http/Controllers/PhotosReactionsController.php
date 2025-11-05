<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use App\Support\PhotoListingFormatter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class PhotosReactionsController extends Controller
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
        $limit = max(1, min(200, (int) request('limit', 40)));
        $page = max(1, (int) request('page', 1));
        $sort = strtolower((string) request('sort', 'newest'));
        if (! in_array($sort, ['newest', 'oldest', 'random'], true)) {
            $sort = 'newest';
        }
        $randSeed = request('rand_seed');
        $resolvedRandSeed = null;

        $userId = optional(request()->user())->id;
        $field = self::KIND_TO_FIELD[$kind];

        // Base Scout search: images that exist locally
        $query = File::search('*')
            ->where('mime_group', 'image')
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
                    $resolvedRandSeed = random_int(1, 2147483646);
                } catch (\Throwable $e) {
                    $resolvedRandSeed = mt_rand(1, 2147483646);
                }
            } else {
                $resolvedRandSeed = (int) $randSeed;
            }
            $query->orderBy('_rand('.$resolvedRandSeed.')', 'desc');
        } elseif ($sort === 'oldest') {
            $query->orderBy('downloaded_at', 'asc')->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('downloaded_at', 'desc')->orderBy('created_at', 'desc');
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

        $serviceCache = [];

        // Preserve TS order
        $files = collect($ids)->map(function (int $id) use ($models, $reactions, $page, &$serviceCache) {
            $file = $models->get($id);

            return PhotoListingFormatter::format(
                $file,
                $reactions,
                $page,
                static fn (File $file, string $url, array &$cache): string => $url,
                $serviceCache
            );
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => [
                'page' => $page,
                'next' => $paginator->hasMorePages() ? ($page + 1) : null,
                'limit' => $limit,
                'data_url' => route('photos.reactions.data', ['kind' => $kind]),
                'title' => self::KIND_TITLES[$kind] ?? ucfirst($kind),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => $sort === 'random' && $resolvedRandSeed !== null ? (int) $resolvedRandSeed : null,
            ],
        ];
    }
}
