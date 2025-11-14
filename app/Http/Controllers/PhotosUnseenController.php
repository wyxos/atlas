<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Http\Controllers\Concerns\ModeratesFiles;
use App\Models\File;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\ListingOptions;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class PhotosUnseenController extends Controller
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
     * Execute the Scout query for unseen photos (viewed_count = 0).
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
            ->where('blacklisted', false)
            ->where('viewed_count', 0);

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
                if (! $file) {
                    return null;
                }

                $remoteThumbnail = $file->thumbnail_url;
                $mime = (string) ($file->mime_type ?? '');
                $hasPath = (bool) $file->path;
                $original = null;

                if ($hasPath) {
                    $original = route('files.view', ['file' => $id]);
                } elseif ($file->url) {
                    $original = $this->decorateRemoteUrl($file, (string) $file->url, $serviceCache);
                }
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

                return [
                    'id' => $id,
                    'preview' => $original ?? $thumbnail,
                    'original' => $original,
                    'type' => $type,
                    'width' => $width,
                    'height' => $height,
                    'page' => $options->page,
                    'containers' => $this->buildContainers($file),
                    'metadata' => [
                        'prompt' => data_get($payload, 'prompt'),
                        'moderation' => data_get($payload, 'moderation'),
                    ],
                    'loved' => false,
                    'liked' => false,
                    'disliked' => false,
                    'funny' => false,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function buildContainers(File $file): array
    {
        return PhotoContainers::forFile($file);
    }
}
