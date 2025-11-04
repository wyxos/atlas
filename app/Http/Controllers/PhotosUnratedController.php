<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DecoratesRemoteUrls;
use App\Jobs\DeleteBlacklistedFileJob;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Services\BlacklistService;
use App\Services\Moderation\Moderator;
use App\Services\Plugin\PluginServiceResolver;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class PhotosUnratedController extends Controller
{
    use DecoratesRemoteUrls;

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
        $limit = max(1, min(200, (int) request('limit', 40)));
        $page = max(1, (int) request('page', 1));
        $sort = strtolower((string) request('sort', 'newest'));
        if (! in_array($sort, ['newest', 'oldest', 'random'], true)) {
            $sort = 'newest';
        }

        $randSeedInput = request('rand_seed');

        $randSeed = null;
        if ($sort === 'random') {
            if (! is_numeric($randSeedInput) || (int) $randSeedInput <= 0) {
                try {
                    $randSeed = random_int(1, 2147483646);
                } catch (\Throwable $e) {
                    $randSeed = mt_rand(1, 2147483646);
                }
            } else {
                $randSeed = (int) $randSeedInput;
            }
        }

        $userId = optional(request()->user())->id;

        $aggregateModeration = [
            'blacklisted_count' => 0,
            'previews' => [],
            'ids' => [],
        ];

        $maxCycles = 3;
        $result = $this->performSearch($limit, $page, $sort, $randSeed, $userId);

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

            $result = $this->performSearch($limit, $page, $sort, $randSeed, $userId);
        }

        $files = $this->formatFiles($result['ids'], $result['models'], $page);
        $paginator = $result['paginator'];

        return [
            'files' => $files,
            'filter' => [
                'page' => $page,
                'next' => $paginator->hasMorePages() ? ($page + 1) : null,
                'limit' => $limit,
                'data_url' => route('photos.unrated.data'),
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $sort,
                'rand_seed' => $sort === 'random' ? $randSeed : null,
            ],
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
    protected function performSearch(int $limit, int $page, string $sort, ?int $randSeed, ?int $userId): array
    {
        $query = File::search('*')
            ->where('mime_group', 'image')
            ->where('not_found', false)
            ->where('blacklisted', false);

        if ($sort === 'random') {
            $seed = $randSeed ?? 1;
            $query->orderBy('_rand('.$seed.')', 'desc');
        } elseif ($sort === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        if ($userId) {
            $query->whereNotIn('reacted_user_ids', [(string) $userId]);
        }

        $paginator = $query->paginate($limit, 'page', $page);

        $items = collect($paginator->items() ?? [])->values();
        $ids = $items->map(fn ($f) => (int) ($f['id'] ?? $f->id ?? 0))->filter()->values()->all();

        $modelsById = empty($ids)
            ? collect([])
            : File::with('metadata')->whereIn('id', $ids)->get()->keyBy('id');

        $orderedModels = collect();
        foreach ($ids as $id) {
            /** @var File|null $model */
            $model = $modelsById->get($id);
            if ($model) {
                $orderedModels->push($model);
            }
        }

        return [
            'paginator' => $paginator,
            'ids' => $ids,
            'models' => $orderedModels,
        ];
    }

    /**
     * Apply moderation rules to the current set of files, blacklisting matches.
     *
     * @return array{filtered:Collection<int, File>, removedIds:array<int>, previewBag:array<int, array{id:int, preview:?string, title:?string}>, newlyBlacklistedCount:int}
     */
    protected function moderateFiles(Collection $files): array
    {
        if ($files->isEmpty()) {
            return [
                'filtered' => $files->values(),
                'removedIds' => [],
                'previewBag' => [],
                'newlyBlacklistedCount' => 0,
            ];
        }

        $activeRules = ModerationRule::query()->where('active', true)->orderBy('id', 'asc')->get();
        if ($activeRules->isEmpty()) {
            return [
                'filtered' => $files->values(),
                'removedIds' => [],
                'previewBag' => [],
                'newlyBlacklistedCount' => 0,
            ];
        }

        $moderator = new Moderator;
        $matchedIds = [];
        $previewBag = [];
        $moderationData = [];
        $filesToDelete = [];

        foreach ($files as $file) {
            if ($file->blacklisted_at) {
                continue;
            }

            $payload = (array) optional($file->metadata)->payload;
            $prompt = data_get($payload, 'prompt');
            if (! is_string($prompt) || $prompt === '') {
                continue;
            }

            $matchedRule = null;
            $hits = [];
            foreach ($activeRules as $rule) {
                $moderator->loadRule($rule);
                if ($moderator->check($prompt)) {
                    $matchedRule = $rule;
                    $hits = $moderator->collectMatches($prompt);
                    break;
                }
            }

            if ($matchedRule) {
                $matchedIds[] = $file->id;
                $previewBag[] = [
                    'id' => $file->id,
                    'preview' => $file->thumbnail_url,
                    'title' => $file->filename ?? null,
                ];
                $moderationData[$file->id] = [
                    'reason' => 'moderation:rule',
                    'rule_id' => $matchedRule->id,
                    'rule_name' => $matchedRule->name,
                    'options' => $matchedRule->options ?? null,
                    'hits' => array_values($hits),
                ];
                if (! empty($file->path)) {
                    $filesToDelete[] = $file->path;
                }
            }
        }

        if (empty($matchedIds)) {
            return [
                'filtered' => $files->values(),
                'removedIds' => [],
                'previewBag' => [],
                'newlyBlacklistedCount' => 0,
            ];
        }

        $blacklister = new BlacklistService;
        $result = $blacklister->apply($matchedIds, 'moderation:rule');
        $newlyBlacklistedCount = (int) ($result['newly_blacklisted_count'] ?? ($result['newlyBlacklistedCount'] ?? 0));

        try {
            $existingMetadata = FileMetadata::query()
                ->whereIn('file_id', $matchedIds)
                ->get()
                ->keyBy('file_id');

            $now = now();
            $toInsert = [];
            $toUpdate = [];

            foreach ($matchedIds as $fileId) {
                $moderationInfo = $moderationData[$fileId] ?? null;
                if (! $moderationInfo) {
                    continue;
                }

                /** @var FileMetadata|null $existing */
                $existing = $existingMetadata->get($fileId);
                $payload = $existing && is_array($existing->payload)
                    ? $existing->payload
                    : (is_string($existing?->payload) ? json_decode($existing->payload, true) ?: [] : []);

                $payload['moderation'] = $moderationInfo;

                if ($existing) {
                    $toUpdate[] = [
                        'id' => $existing->id,
                        'payload' => $payload,
                        'updated_at' => $now,
                    ];
                } else {
                    $toInsert[] = [
                        'file_id' => $fileId,
                        'payload' => json_encode($payload),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (! empty($toInsert)) {
                FileMetadata::insert($toInsert);
            }

            if (! empty($toUpdate)) {
                foreach ($toUpdate as $update) {
                    FileMetadata::where('id', $update['id'])->update([
                        'payload' => $update['payload'],
                        'updated_at' => $update['updated_at'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Photos moderation: Failed to persist metadata', [
                'matched_ids' => $matchedIds,
                'exception' => $e,
            ]);
        }

        foreach ($filesToDelete as $filePath) {
            DeleteBlacklistedFileJob::dispatch($filePath);
        }

        $filtered = $files->reject(fn ($file) => in_array($file->id, $matchedIds, true))->values();

        return [
            'filtered' => $filtered,
            'removedIds' => $matchedIds,
            'previewBag' => $previewBag,
            'newlyBlacklistedCount' => $newlyBlacklistedCount,
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

    protected function formatFiles(array $orderedIds, Collection $models, int $page): array
    {
        if (empty($orderedIds)) {
            return [];
        }

        $modelsById = $models->keyBy('id');

        $serviceCache = [];

        return collect($orderedIds)
            ->map(function (int $id) use ($modelsById, $page, &$serviceCache) {
                /** @var File|null $file */
                $file = $modelsById->get($id);
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
                    'page' => $page,
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
