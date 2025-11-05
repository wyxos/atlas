<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Models\File;
use App\Models\Reaction;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\PhotoListingFormatter;
use Illuminate\Http\JsonResponse;
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
        if (is_string($source)) {
            $source = trim($source);

            if ($source === '' || $source === 'null' || $source === 'undefined' || $source === 'all') {
                $source = null;
            }
        }

        $user = request()->user();
        $userId = $user?->id;

        // Scout-only search (Typesense): images
        // Filter logic: show local files (all) OR non-local files with reactions
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
//            $query->options([
//                'filter_by' => "mime_group:='image' && (source:='local' || (source:!='local' && has_reactions:=true))",
//            ]);
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

//        dd($paginator);

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

        $files = collect($idList)->map(function (int $id) use ($models, $reactions, &$serviceCache, $page) {
            /** @var File|null $file */
            $file = $models->get($id);

            return PhotoListingFormatter::format(
                $file,
                $reactions,
                $page,
                fn (File $file, string $url, array &$cache): string => $this->decorateRemoteUrl($file, $url, $cache),
                $serviceCache
            );
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

}
