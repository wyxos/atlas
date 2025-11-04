<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Models\File;
use App\Models\Reaction;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class PhotosController extends Controller
{
    use DecoratesRemoteUrls;

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
        $limit = max(1, min(200, (int) request('limit', 20)));
        $page = max(1, (int) request('page', 1));
        $sort = strtolower((string) request('sort', 'newest'));
        if (! in_array($sort, ['newest', 'oldest', 'random'], true)) {
            $sort = 'newest';
        }
        $randSeed = request('rand_seed');
        $source = request('source'); // optional source filter

        $user = request()->user();
        $userId = $user?->id;

        // Scout-only search (Typesense): images
        // Filter logic: show local files (all) OR non-local files with reactions
        $query = File::search('*')
            ->where('mime_group', 'image');

        if ($userId) {
            $query->query(function ($eloquent) use ($userId) {
                $eloquent->whereDoesntHave('reactions', function ($relation) use ($userId) {
                    $relation->where('user_id', $userId)->where('type', 'dislike');
                });
            });
        }

        // Apply filtering based on source and reactions
        if ($source && is_string($source) && $source !== '') {
            if ($source === 'local') {
                // Show all local files
                $query->where('source', 'local');
            } else {
                // Show non-local files that have reactions
                $query->where('source', $source)
                    ->where('has_reactions', true);
            }
        } else {
            // No source filter: show local files OR non-local with reactions
            $query->options([
                'filter_by' => "mime_group:='image' && (source:='local' || (source:!='local' && has_reactions:=true))",
            ]);
        }

        // Sorting
        if ($sort === 'newest') {
            $query->orderBy('downloaded_at', 'desc')->orderBy('created_at', 'desc');
        } elseif ($sort === 'oldest') {
            $query->orderBy('downloaded_at', 'asc')->orderBy('created_at', 'asc');
        } else {
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
        }

        $paginator = $query->paginate($limit, 'page', $page);

        $items = collect($paginator->items() ?? [])->values();

        $extractId = static fn ($f) => (int) ($f['id'] ?? $f->id ?? 0);

        $ids = $items->map($extractId)->filter()->values();
        $idList = $ids->all();

        // Build map of reactions for current user
        $reactions = [];
        if ($userId && ! empty($idList)) {
            $reactions = Reaction::query()
                ->whereIn('file_id', $idList)
                ->where('user_id', $userId)
                ->pluck('type', 'file_id')
                ->toArray();
        }

        // Eager-load full models with metadata in one query
        $models = empty($idList)
            ? collect([])
            : File::with('metadata')->whereIn('id', $idList)->get()->keyBy('id');

        // Preserve original order from hits
        $serviceCache = [];

        $files = collect($idList)->map(function (int $id) use ($models, $reactions, &$serviceCache) {
            $file = $models->get($id);
            if (! $file) {
                return null;
            }

            $thumbnail = $file->thumbnail_url;
            $mime = (string) ($file->mime_type ?? '');
            $hasPath = (bool) $file->path;
            $original = null;
            if ($hasPath) {
                $original = URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id]);
            } elseif ($file->url) {
                $original = $this->decorateRemoteUrl($file, (string) $file->url, $serviceCache);
            }
            $type = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'other'));

            $detailMetadata = $file->metadata?->payload ?? [];
            if (! is_array($detailMetadata)) {
                $detailMetadata = is_string($detailMetadata) ? json_decode($detailMetadata, true) ?: [] : [];
            }

            $listingMetadata = $file->listing_metadata;
            if (! is_array($listingMetadata)) {
                $listingMetadata = is_string($listingMetadata) ? json_decode($listingMetadata, true) ?: [] : [];
            }

            $width = (int) ($detailMetadata['width'] ?? 0);
            $height = (int) ($detailMetadata['height'] ?? 0);
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

            $prompt = $detailMetadata['prompt'] ?? data_get($listingMetadata, 'meta.prompt');
            $moderation = $detailMetadata['moderation'] ?? null;

            return [
                'id' => $id,
                'preview' => $thumbnail ?? $original,
                'original' => $original,
                'true_original_url' => $file->url ?: null,
                'true_thumbnail_url' => $thumbnail ?: null,
                'referrer_url' => $file->referrer_url ?: null,
                'is_local' => $hasPath,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => (int) request('page', 1),
                'containers' => $this->buildContainers($file),
                'metadata' => [
                    'prompt' => is_string($prompt) ? $prompt : null,
                    'moderation' => is_array($moderation) ? $moderation : null,
                ],
                'listing_metadata' => $listingMetadata,
                'detail_metadata' => $detailMetadata,
                'previewed_count' => (int) $file->previewed_count,
                'seen_count' => (int) $file->seen_count,
                'not_found' => (bool) $file->not_found,
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
                'data_url' => route('photos.data'),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => ($sort === 'random') ? (int) $randSeed : null,
                'source' => $source ?? null,
            ],
        ];
    }

    protected function buildContainers(File $file): array
    {
        return PhotoContainers::forFile($file);
    }
}
