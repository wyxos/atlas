<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ReelsController extends Controller
{
    public function index()
    {
        // Initial page render with first batch via data() to keep one source of truth
        $payload = $this->getData();

        return Inertia::render('reels/Index', $payload);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->getData());
    }

    protected function getData(): array
    {
        $limit = max(1, min(200, (int) request('limit', 40)));
        $page = max(1, (int) request('page', 1));
        $sort = (string) request('sort', 'newest'); // default to newest
        $randSeed = request('rand_seed');
        $source = request('source'); // optional source filter

        // Scout-only search (Typesense): videos with local path
        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('has_path', true);

        // Apply source filter if provided
        if ($source && is_string($source) && $source !== '') {
            $query->where('source', $source);
        }

        // Sorting
        if (method_exists($query, 'orderBy')) {
            if ($sort === 'newest') {
                // Prefer most recently downloaded, then created
                $query->orderBy('downloaded_at', 'desc')->orderBy('created_at', 'desc');
            } else {
                // Seeded random via Typesense _rand(seed)
                // If no seed provided, generate a positive int for reproducibility and echo back
                if (! is_numeric($randSeed) || (int) $randSeed <= 0) {
                    try {
                        $randSeed = random_int(1, 2147483646);
                    } catch (\Throwable $e) {
                        $randSeed = mt_rand(1, 2147483646);
                    }
                } else {
                    $randSeed = (int) $randSeed;
                }
                // Apply random sort first; you can chain additional sorts if needed
                $query->orderBy('_rand('.$randSeed.')', 'desc');
            }
        }

        $paginator = $query->paginate($limit, 'page', $page);

        $items = collect($paginator->items() ?? [])->values();
        $ids = $items->map(fn ($f) => (int) ($f['id'] ?? $f->id ?? 0))->filter()->values()->all();

        // Eager-load full models with metadata in one query
        $models = empty($ids)
            ? collect([])
            : File::with('metadata')->whereIn('id', $ids)->get()->keyBy('id');

        // Build map of reactions for current user
        $userId = optional(request()->user())->id;
        $reactions = [];
        if ($userId && ! empty($ids)) {
            $reactions = Reaction::query()
                ->whereIn('file_id', $ids)
                ->where('user_id', $userId)
                ->pluck('type', 'file_id')
                ->toArray();
        }

        // Preserve original order from hits
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
            // Ensure positive dimensions for Masonry layout
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
                'preview' => $thumbnail ?? $original,
                'original' => $original,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => (int) request('page', 1),
                'containers' => $this->buildContainers($file),
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
                'data_url' => route('reels.data'),
                // include the overall total number of matching files
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => ($sort === 'newest') ? null : (int) $randSeed,
                'source' => $source ?? null,
            ],
        ];
    }

    protected function buildContainers(File $file): array
    {
        return PhotoContainers::forFile($file);
    }
}
