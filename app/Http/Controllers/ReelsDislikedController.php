<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use App\Support\FilePreviewUrl;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Laravel\Scout\Builder as ScoutBuilder;

class ReelsDislikedController extends Controller
{
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
        $limit = max(1, min(200, (int) request('limit', 40)));
        $page = max(1, (int) request('page', 1));
        $sort = (string) request('sort', 'newest');
        $randSeed = request('rand_seed');

        $reasons = $this->mapReasons($category);

        // Build Typesense filter_by string (for reference/debug only; actual filters are applied via Scout builder)
        $filters = [];
        $filters[] = 'mime_group:=video';
        $filters[] = 'blacklisted:=true';
        if ($category === 'auto') {
            $filters[] = 'previewed_count:=0';
        } else {
            $filters[] = 'previewed_count:<5';
        }
        if ($reasons !== null && \count($reasons) > 0) {
            $encoded = implode(',', array_map(fn ($r) => '"'.str_replace('"', '\\"', (string) $r).'"', $reasons));
            $filters[] = "blacklist_reason:=[{$encoded}]";
        }

        // Execute via Scout builder to keep full Scout pipeline
        $query = $this->buildScoutQuery($category, $sort, $randSeed);

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

            $remoteThumbnail = $file->thumbnail_url;
            $mime = (string) ($file->mime_type ?? '');
            $hasPath = (bool) $file->path;
            $original = $hasPath ? (URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id])) : $file->url;
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

            $rt = $reactions[$id] ?? null;

            $containers = PhotoContainers::forFile($file);

            return [
                'id' => $id,
                'preview' => $thumbnail,
                'original' => $original,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => (int) request('page', 1),
                'has_path' => $hasPath,
                'downloaded' => (bool) $file->downloaded,
                'previewed_count' => (int) ($file->previewed_count ?? 0),
                'containers' => $containers,
                'metadata' => [
                    'prompt' => data_get(optional($file->metadata)->payload ?? [], 'prompt'),
                    'moderation' => data_get(optional($file->metadata)->payload ?? [], 'moderation'),
                ],
                'loved' => $rt === 'love',
                'liked' => $rt === 'like',
                'disliked' => $rt === 'dislike',
                'funny' => $rt === 'funny',
            ];
        })->filter()->values()->all();

        $current = (int) $paginator->currentPage();
        $next = $paginator->hasMorePages() ? ($current + 1) : null;

        return [
            'files' => $files,
            'filter' => [
                'page' => $current,
                'next' => $next,
                'limit' => $limit,
                'data_url' => route('reels.disliked.data', ['category' => $category]),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => ($sort === 'newest') ? null : (int) $randSeed,
            ],
        ];
    }

    /**
     * Build the Scout/Typesense query for disliked reels with all constraints.
     */
    protected function buildScoutQuery(string $category, string $sort = 'newest', $randSeed = null): ScoutBuilder
    {
        $reasons = $this->mapReasons($category);

        // Determine current user id using auth() to support non-HTTP contexts
        $userId = (string) (auth()->id() ?? '');

        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('blacklisted', true)
            ->where('not_found', false);

        // previewed_count constraint depends on category
        if ($category === 'auto') {
            // Only never-previewed items in Auto
            $query->whereIn('previewed_count', [0]);
        } else {
            // For all other disliked categories, keep items previewed less than 5 times
            $query->whereIn('previewed_count', [0, 1, 2, 3, 4, 5]);
        }

        if ($reasons !== null) {
            $query->whereIn('blacklist_reason', $reasons);
        }

        if ($category === 'not-disliked' && $userId !== '') {
            $query->whereNotIn('dislike_user_ids', [(string) $userId]);
        }

        // Stable sort to avoid page loops on ties (support seeded random)
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
