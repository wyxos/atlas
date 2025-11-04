<?php

namespace App;

use App\Jobs\DeleteBlacklistedFileJob;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Services\BlacklistService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\Moderation\Moderator;
use App\Services\Plugin\PluginServiceLoader;
use App\Support\FilePreviewUrl;
use Atlas\Plugin\Contracts\ServiceRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

class Browser
{
    /**
     * Resolve a source key and return the service transform result.
     *
     * Request inputs:
     * - source: service key (default: 'civit-ai-images')
     * - any other inputs are forwarded to fetch()
     */
    public static function handle(): array
    {
        return (new self)->run();
    }

    /**
     * @throws ConnectionException
     */
    public function run(): array
    {
        $params = request()->all();
        $source = (string) ($params['source'] ?? CivitAiImages::key());

        app(PluginServiceLoader::class)->load();

        /** @var ServiceRegistry $registry */
        $registry = app(ServiceRegistry::class);

        if (! $registry->get(CivitAiImages::key())) {
            $registry->register(app(CivitAiImages::class));
        }

        $services = $registry->all();
        if (empty($services)) {
            throw new \RuntimeException('No browse services registered.');
        }

        $servicesMeta = [];
        $hotlinkSources = [];
        foreach ($services as $serviceInstance) {
            $servicesMeta[] = [
                'key' => $serviceInstance::key(),
                'label' => $serviceInstance::label(),
                'defaults' => $serviceInstance->defaultParams(),
            ];

            if ($serviceInstance::hotlinkProtected()) {
                $hotlinkSources[] = $serviceInstance::source();
            }
        }

        $service = $registry->get($source) ?? $registry->get(CivitAiImages::key());
        if (! $service) {
            $service = app(CivitAiImages::class);
        }

        if (\method_exists($service, 'setParams')) {
            $service->setParams($params);
        }

        $response = $service->fetch($params);

        Storage::disk('local')->put('temp/'.time().'-response.json', json_encode($response, JSON_PRETTY_PRINT));

        $transformed = $service->transform($response, $params);
        $filesPayload = $transformed['files'] ?? [];
        $filter = $transformed['filter'] ?? [];

        $persisted = app(BrowsePersister::class)->persist($filesPayload);
        $ids = array_map(fn (File $f) => $f->id, $persisted);

        // Moderation: evaluate prompts and auto-blacklist matches
        $matchedIds = [];
        $previewBag = [];
        $moderationData = []; // Collect moderation data for bulk update
        $filesToDelete = []; // Collect file paths for bulk deletion
        $newlyBlacklistedCount = 0;
        $activeRules = ModerationRule::query()->where('active', true)->orderBy('id', 'asc')->get();
        if ($activeRules->isNotEmpty()) {
            $moderator = new Moderator;

            foreach ($persisted as $f) {
                if ($f->blacklisted_at) {
                    continue;
                }
                $prompt = $f->metadata?->payload['prompt'] ?? data_get($f->listing_metadata, 'meta.prompt');
                if (! is_string($prompt) || $prompt === '') {
                    continue;
                }

                // Find the first matching rule and capture hits
                $matchedRule = null;
                $hits = [];
                foreach ($activeRules as $r) {
                    $moderator->loadRule($r);
                    if ($moderator->check($prompt)) {
                        $matchedRule = $r;
                        $hits = $moderator->collectMatches($prompt);
                        break;
                    }
                }

                if ($matchedRule) {
                    $matchedIds[] = $f->id;
                    $previewBag[] = [
                        'id' => $f->id,
                        'preview' => $f->thumbnail_url,
                        'title' => $f->filename ?? null,
                    ];

                    // Collect moderation data for bulk update
                    $moderationData[$f->id] = [
                        'reason' => 'moderation:rule',
                        'rule_id' => $matchedRule->id,
                        'rule_name' => $matchedRule->name,
                        'options' => $matchedRule->options ?? null,
                        'hits' => array_values($hits),
                    ];

                    // Collect file path for bulk deletion
                    if (! empty($f->path)) {
                        $filesToDelete[] = $f->path;
                    }
                }
            }

            // Bulk operations for matched items
            if (! empty($matchedIds)) {
                // 1. Blacklist all matched items
                $blacklister = new BlacklistService;
                $result = $blacklister->apply($matchedIds, 'moderation:rule');
                $newlyBlacklistedCount = (int) ($result['newlyBlacklistedCount'] ?? ($result['newly_blacklisted_count'] ?? 0));

                // 2. Bulk update metadata with moderation info
                try {
                    // Get or create FileMetadata records for all matched files
                    $existingMetadata = FileMetadata::query()
                        ->whereIn('file_id', $matchedIds)
                        ->get()
                        ->keyBy('file_id');

                    $now = now();
                    $toUpdate = [];
                    $toInsert = [];

                    foreach ($matchedIds as $fileId) {
                        $moderationInfo = $moderationData[$fileId] ?? null;
                        if (! $moderationInfo) {
                            continue;
                        }

                        $existingMeta = $existingMetadata->get($fileId);
                        $payload = $existingMeta && is_array($existingMeta->payload) ? $existingMeta->payload : [];
                        $payload['moderation'] = $moderationInfo;

                        if ($existingMeta) {
                            // Update existing
                            $toUpdate[] = [
                                'id' => $existingMeta->id,
                                'payload' => $payload,
                                'updated_at' => $now,
                            ];
                        } else {
                            // Insert new (must JSON-encode payload for raw insert)
                            $toInsert[] = [
                                'file_id' => $fileId,
                                'payload' => json_encode($payload),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    // Bulk insert new metadata
                    if (! empty($toInsert)) {
                        FileMetadata::insert($toInsert);
                    }

                    // Bulk update existing metadata (using upsert for efficiency)
                    if (! empty($toUpdate)) {
                        foreach ($toUpdate as $update) {
                            FileMetadata::where('id', $update['id'])
                                ->update([
                                    'payload' => $update['payload'],
                                    'updated_at' => $update['updated_at'],
                                ]);
                        }
                    }
                } catch (\Throwable $e) {
                    // Log error but don't block browse flow
                    \Log::error('Failed to bulk update moderation metadata: '.$e->getMessage(), [
                        'matched_ids' => $matchedIds,
                        'exception' => $e,
                    ]);
                }

                // 3. Dispatch async jobs to delete local files
                foreach ($filesToDelete as $filePath) {
                    DeleteBlacklistedFileJob::dispatch($filePath);
                }

                // Filter them out of the response
                $persisted = array_values(array_filter($persisted, fn (File $f) => ! in_array($f->id, $matchedIds, true)));
                $ids = array_map(fn (File $f) => $f->id, $persisted);
            }
        }

        // Gather current user's reactions in one query (after moderation filtering)
        $userId = optional(request()->user())->id;
        $reactions = [];
        if ($userId && ! empty($ids)) {
            $reactions = Reaction::query()
                ->whereIn('file_id', $ids)
                ->where('user_id', $userId)
                ->pluck('type', 'file_id')
                ->toArray();
        }

        return [
            'files' => collect($persisted)->map(function (File $file) use ($hotlinkSources, $reactions, $service) {
                // Prefer local signed route; else, for flagged sources, use proxy; else direct URL
                $original = null;
                $needsProxy = false;
                if ($file->path) {
                    try {
                        $original = \URL::temporarySignedRoute('files.view', now()->addMinutes(5), ['file' => $file->id]);
                    } catch (\Throwable $e) {
                        $original = null;
                    }
                }
                if (! $original) {
                    $needsProxy = in_array((string) $file->source, $hotlinkSources, true);
                    $original = $needsProxy ? route('files.remote', ['file' => $file->id]) : ($file->url ?: null);
                }

                if ($original && ! $needsProxy && ! $file->path && method_exists($service, 'decorateOriginalUrl')) {
                    try {
                        $decorated = $service->decorateOriginalUrl($file, $original, request()->user());
                        if (is_string($decorated) && $decorated !== '') {
                            $original = $decorated;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                // Containers provided by service (default empty)
                $listingMetadata = is_array($file->listing_metadata)
                    ? $file->listing_metadata
                    : (is_string($file->listing_metadata) ? json_decode($file->listing_metadata, true) ?: [] : []);

                $detailMetadata = $file->metadata?->payload ?? [];
                if (! is_array($detailMetadata)) {
                    $detailMetadata = is_string($detailMetadata) ? json_decode($detailMetadata, true) ?: [] : [];
                }

                $containers = $service->containers($listingMetadata, $detailMetadata);

                // Reaction flags for current user
                $type = $reactions[$file->id] ?? null;
                $loved = $type === 'love';
                $liked = $type === 'like';
                $disliked = $type === 'dislike';
                $funny = $type === 'funny';

                $trueOriginal = $file->url ?: null;
                $remoteThumbnail = $file->thumbnail_url ?: null;
                $localPreview = FilePreviewUrl::for($file);
                $preview = $localPreview ?? $remoteThumbnail ?? $original;
                $trueThumbnail = $remoteThumbnail ?? $localPreview;
                $referrer = $file->referrer_url ?: null;
                $isLocal = (bool) $file->path;

                return [
                    'id' => $file->id,
                    'preview' => $preview,
                    'original' => $original,
                    'true_original_url' => $trueOriginal,
                    'true_thumbnail_url' => $trueThumbnail,
                    'referrer_url' => $referrer,
                    'is_local' => $isLocal,
                    'type' => (str_starts_with((string) $file->mime_type, 'video/')
                        ? 'video'
                        : (str_starts_with((string) $file->mime_type, 'image/')
                            ? 'image'
                            : (str_starts_with((string) $file->mime_type, 'audio/')
                                ? 'audio'
                                : 'other'))),
                    'width' => $file->metadata->payload['width'] ?? null,
                    'height' => $file->metadata->payload['height'] ?? null,
                    'page' => request()->input('page', 1),
                    'containers' => $containers,
                    'metadata' => [
                        'prompt' => $file->listing_metadata['meta']['prompt'] ?? null,
                        'listing' => $file->listing_metadata,
                        'source' => null,
                    ],
                    'previewed_count' => (int) $file->previewed_count,
                    'seen_count' => (int) $file->seen_count,
                    'loved' => $loved,
                    'liked' => $liked,
                    'disliked' => $disliked,
                    'funny' => $funny,
                ];
            }),
            'filter' => [
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
            ],
            'services' => $servicesMeta,
            'moderation' => [
                'blacklisted_count' => (int) $newlyBlacklistedCount,
                'previews' => array_slice($previewBag, 0, 4),
                'ids' => $matchedIds,
            ],
        ];
    }
}
