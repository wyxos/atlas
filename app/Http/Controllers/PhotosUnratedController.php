<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Http\Controllers\Concerns\ModeratesFiles;
use App\Models\File;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\FileListingFormatter;
use App\Support\ListingOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class PhotosUnratedController extends Controller
{
    use DecoratesRemoteUrls;
    use InteractsWithListings;
    use ModeratesFiles;

    public function __construct(private PluginServiceResolver $serviceResolver) {}

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
        $options = $this->resolveListingOptions([
            'allowed_sorts' => ['newest', 'oldest', 'random'],
            'default_sort' => 'newest',
        ]);

        $userId = $this->currentUserId();
        $mimeType = $this->requestedMimeType();

        $aggregateModeration = [
            'blacklisted_count' => 0,
            'previews' => [],
            'ids' => [],
        ];

        $maxCycles = 3;
        $result = $this->performSearch($options, $userId, $mimeType);

        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $moderationResult = $this->moderateFiles($result['models']);

            $aggregateModeration['blacklisted_count'] += $moderationResult['newlyBlacklistedCount'];
            $aggregateModeration['previews'] = $this->mergePreviewBag($aggregateModeration['previews'], $moderationResult['previewBag']);
            $aggregateModeration['ids'] = array_values(array_unique(array_merge($aggregateModeration['ids'], $moderationResult['removedIds'])));

            $filteredIds = array_values(array_filter(
                $result['ids'],
                fn (int $id) => ! in_array($id, $moderationResult['removedIds'], true)
            ));

            $result['ids'] = $filteredIds;
            $result['models'] = $moderationResult['filtered'];

            if (empty($moderationResult['removedIds'])) {
                break;
            }

            if ($cycle === $maxCycles - 1) {
                break;
            }

            $result = $this->performSearch($options, $userId, $mimeType);
        }

        $files = $this->formatFiles($result['ids'], $result['models'], $options);
        $paginator = $result['paginator'];

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter($options, $paginator),
            'moderation' => [
                'blacklisted_count' => (int) $aggregateModeration['blacklisted_count'],
                'previews' => array_slice($aggregateModeration['previews'], 0, 4),
                'ids' => $aggregateModeration['ids'],
            ],
        ];
    }

    /**
     * Execute the Scout query for the unrated photos feed and normalize its output.
     *
     * @return array{paginator:\Illuminate\Contracts\Pagination\LengthAwarePaginator, ids:array<int>, models:Collection<int, File>}
     */
    protected function performSearch(ListingOptions $options, ?int $userId, ?string $mimeType): array
    {
        $fileId = $this->requestedFileId();
        $sourceId = $this->requestedSourceId();

        $query = File::search('*')
            ->where('mime_group', 'image')
            ->where('not_found', false)
            ->where('blacklisted', false);

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        if ($fileId) {
            $query->where('id', (string) $fileId);
        }

        if ($sourceId) {
            $query->where('source_id', (string) $sourceId);
        }

        $this->applySorting($query, $options, null, 'created_at');

        if ($userId) {
            $query->whereNotIn('reacted_user_ids', [(string) $userId]);
        }

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);

        $modelsById = $this->loadFilesByIds($ids);

        $orderedModels = collect($ids)
            ->map(static fn (int $id) => $modelsById->get($id))
            ->filter()
            ->values();

        return [
            'paginator' => $paginator,
            'ids' => $ids,
            'models' => $orderedModels,
        ];
    }

    protected function mergePreviewBag(array $existing, array $incoming): array
    {
        $indexed = [];
        foreach ($existing as $entry) {
            if (isset($entry['id'])) {
                $indexed[$entry['id']] = $entry;
            }
        }
        foreach ($incoming as $entry) {
            if (isset($entry['id'])) {
                $indexed[$entry['id']] = $entry;
            }
        }

        return array_values($indexed);
    }

    protected function formatFiles(array $orderedIds, Collection $models, ListingOptions $options): array
    {
        if (empty($orderedIds)) {
            return [];
        }

        $modelsById = $models->keyBy('id');

        $serviceCache = [];

        return collect($orderedIds)
            ->map(function (int $id) use ($modelsById, $options, &$serviceCache) {
                /** @var File|null $file */
                $file = $modelsById->get($id);

                return FileListingFormatter::format(
                    $file,
                    [],
                    $options->page,
                    fn (File $file, string $url, array &$cache): string => $this->decorateRemoteUrl($file, $url, $cache),
                    $serviceCache
                );
            })
            ->filter()
            ->values()
            ->all();
    }
}
