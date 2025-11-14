<?php

namespace App;

use App\Http\Controllers\Concerns\ModeratesFiles;
use App\Models\File;
use App\Models\Reaction;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\Plugin\PluginServiceLoader;
use App\Support\FileListingFormatter;
use Atlas\Plugin\Contracts\ServiceRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

class Browser
{
    use ModeratesFiles;

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

        // Moderation: evaluate prompts and auto-blacklist matches using shared trait
        $moderationResult = $this->moderateFiles(collect($persisted));
        $matchedIds = $moderationResult['removedIds'];
        $previewBag = $moderationResult['previewBag'];
        $newlyBlacklistedCount = $moderationResult['newlyBlacklistedCount'];

        // Filter out blacklisted files and update ids array
        $persisted = $moderationResult['filtered']->all();
        $ids = array_map(fn (File $f) => $f->id, $persisted);

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

        // Create remote URL decorator that handles proxy logic and service decoration
        $serviceCache = [];
        $remoteUrlDecorator = function (File $file, string $url, array &$cache) use ($hotlinkSources, $service): string {
            // Check if this source needs proxying
            $needsProxy = in_array((string) $file->source, $hotlinkSources, true);
            if ($needsProxy) {
                return route('files.remote', ['file' => $file->id]);
            }

            // Otherwise, try service decoration
            $source = (string) ($file->source ?? '');
            if ($source !== '' && method_exists($service, 'decorateOriginalUrl')) {
                if (! array_key_exists($source, $cache)) {
                    $cache[$source] = $service;
                }

                try {
                    $decorated = $service->decorateOriginalUrl($file, $url, request()->user());
                    if (is_string($decorated) && $decorated !== '') {
                        return $decorated;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $url;
        };

        $files = collect($persisted)->map(function (File $file) use ($reactions, $remoteUrlDecorator, &$serviceCache) {
            $formatted = FileListingFormatter::format(
                $file,
                $reactions,
                (int) request()->input('page', 1),
                $remoteUrlDecorator,
                $serviceCache
            );

            if (! $formatted) {
                return null;
            }

            // Browser.php expects slightly different metadata structure
            // Merge listing_metadata into metadata.listing for backward compatibility
            $formatted['metadata'] = array_merge($formatted['metadata'] ?? [], [
                'listing' => $formatted['listing_metadata'] ?? null,
                'source' => null,
            ]);

            return $formatted;
        })->filter()->values()->all();

        return [
            'files' => $files,
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
