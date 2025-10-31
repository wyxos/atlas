<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class PhotosUnratedController extends Controller
{
    public function index()
    {
        $payload = $this->getData();

        return Inertia::render('photos/Index', $payload);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->getData());
    }

    protected function getData(): array
    {
        $limit = max(1, min(200, (int) request('limit', 40)));
        $page = max(1, (int) request('page', 1));
        $sort = (string) request('sort', 'newest');
        $randSeed = request('rand_seed');

        // Scout-only search (Typesense): images with local path
        $userId = optional(request()->user())->id;

        $query = File::search('*')
            ->where('mime_group', 'image')
            ->where('not_found', false)
            ->where('blacklisted', false);

        if (method_exists($query, 'orderBy')) {
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
        }

        if ($userId && method_exists($query, 'whereNotIn')) {
            // Use Typesense array NOT ANY OF for per-user unrated
            $query->whereNotIn('reacted_user_ids', [(string) $userId]);
        }

        $paginator = $query->paginate($limit, 'page', $page);

        $items = collect($paginator->items() ?? [])->values();
        $ids = $items->map(fn ($f) => (int) ($f['id'] ?? $f->id ?? 0))->filter()->values()->all();

        // Eager-load full models with metadata in one query
        $models = empty($ids)
            ? collect([])
            : File::with('metadata')->whereIn('id', $ids)->get()->keyBy('id');

        // Preserve original order from hits
        $files = collect($ids)
            ->map(function (int $id) use ($models) {
                /** @var File $file */
                $file = $models->get($id);
                if (! $file) {
                    return null;
                }

                $thumbnail = $file->thumbnail_url;
                $mime = (string) ($file->mime_type ?? '');
                $hasPath = (bool) $file->path;
                $original = $hasPath ? (URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id])) : $file->url;
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

                $payload = (array) optional($file->metadata)->payload;

                return [
                    'id' => $id,
                    'preview' => $original ?? $thumbnail,
                    'original' => $original,
                    'type' => $type,
                    'width' => $width,
                    'height' => $height,
                    'page' => (int) request('page', 1),
                    'containers' => $this->buildContainers($file),
                    'metadata' => [
                        'prompt' => data_get($payload, 'prompt'),
                        'moderation' => data_get($payload, 'moderation'),
                    ],
                    // By definition of unrated, all reaction flags are false
                    'loved' => false,
                    'liked' => false,
                    'disliked' => false,
                    'funny' => false,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'files' => $files,
            'filter' => [
                'page' => $page,
                'next' => $paginator->hasMorePages() ? ($page + 1) : null,
                'limit' => $limit,
                'data_url' => route('photos.unrated.data'),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => ($sort === 'newest') ? null : (int) $randSeed,
            ],
        ];
    }

    protected function buildContainers(File $file): array
    {
        return PhotoContainers::forFile($file);
    }
}
