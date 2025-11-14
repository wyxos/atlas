<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\DeleteBlacklistedFileJob;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Services\BlacklistService;
use App\Services\Moderation\Moderator;
use App\Support\FilePreviewUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

trait ModeratesFiles
{
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
        $metadataToUpdate = []; // Files that need moderation data but are already blacklisted

        foreach ($files as $file) {
            $payload = (array) optional($file->metadata)->payload;
            // Support both metadata payload and listing_metadata fallback (for Browser.php)
            $prompt = data_get($payload, 'prompt') ?? data_get($file->listing_metadata, 'meta.prompt');
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
                $isAlreadyBlacklisted = (bool) $file->blacklisted_at;
                $hasModerationData = isset($payload['moderation']) && is_array($payload['moderation']);

                // If file is already blacklisted with moderation:rule but missing moderation data, populate it
                if ($isAlreadyBlacklisted && $file->blacklist_reason === 'moderation:rule' && ! $hasModerationData) {
                    $metadataToUpdate[] = $file->id;
                    $moderationData[$file->id] = [
                        'reason' => 'moderation:rule',
                        'rule_id' => $matchedRule->id,
                        'rule_name' => $matchedRule->name,
                        'options' => $matchedRule->options ?? null,
                        'hits' => array_values($hits),
                    ];
                } elseif (! $isAlreadyBlacklisted) {
                    // File is not blacklisted yet, add it to matchedIds for blacklisting
                    $matchedIds[] = $file->id;
                    $previewBag[] = [
                        'id' => $file->id,
                        'preview' => FilePreviewUrl::for($file) ?? $file->thumbnail_url,
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
                // If file is already blacklisted AND has moderation data, skip it
            }
        }

        // Blacklist new matches
        $newlyBlacklistedCount = 0;
        if (! empty($matchedIds)) {
            $blacklister = new BlacklistService;
            $result = $blacklister->apply($matchedIds, 'moderation:rule');
            $newlyBlacklistedCount = (int) ($result['newly_blacklisted_count'] ?? ($result['newlyBlacklistedCount'] ?? 0));
        }

        // Update moderation metadata for both newly blacklisted files and already-blacklisted files missing data
        $allFileIds = array_unique(array_merge($matchedIds, $metadataToUpdate));
        if (! empty($allFileIds)) {
            try {
                $existingMetadata = FileMetadata::query()
                    ->whereIn('file_id', $allFileIds)
                    ->get()
                    ->keyBy('file_id');

                $now = now();
                $toInsert = [];
                $toUpdate = [];

                foreach ($allFileIds as $fileId) {
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
                    'file_ids' => $allFileIds,
                    'exception' => $e,
                ]);
            }
        }

        if (empty($matchedIds) && empty($metadataToUpdate)) {
            return [
                'filtered' => $files->values(),
                'removedIds' => [],
                'previewBag' => [],
                'newlyBlacklistedCount' => 0,
            ];
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
}
