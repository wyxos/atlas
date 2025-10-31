<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BlacklistService
{
    /**
     * Blacklist the given files and upsert a 'dislike' reaction for the authenticated user.
     * Also removes local files on atlas_app/atlas disks when a path exists.
     *
     * Returns an array: ['newly_blacklisted_count' => int, 'ids' => array<int>]
     */
    public function apply(array $fileIds, string $reason): array
    {
        $ids = collect($fileIds)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return ['newly_blacklisted_count' => 0, 'ids' => []];
        }

        // Delete local files when present
        $withPath = File::query()->whereIn('id', $ids)->whereNotNull('path')->get(['id', 'path']);
        foreach ($withPath as $f) {
            try {
                foreach (['atlas_app', 'atlas'] as $diskName) {
                    $disk = Storage::disk($diskName);
                    if ($disk->exists($f->path)) {
                        $disk->delete($f->path);
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Apply blacklist flags (count only newly blacklisted)
        $newlyBlacklistedCount = DB::table('files')
            ->whereIn('id', $ids)
            ->whereNull('blacklisted_at')
            ->update([
                'blacklisted_at' => now(),
                'blacklist_reason' => $reason,
                'updated_at' => now(),
            ]);

        // Ensure search index reflects new flags (best-effort)
        try {
            File::whereIn('id', $ids)->searchable();
        } catch (\Throwable $e) {
            // ignore indexing errors
        }

        // Upsert dislike reactions for the authenticated user
        $userId = auth()->id();
        if ($userId) {
            $now = now();
            $rows = array_map(fn ($fid) => [
                'file_id' => $fid,
                'user_id' => $userId,
                'type' => 'dislike',
                'created_at' => $now,
                'updated_at' => $now,
            ], $ids);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('reactions')->upsert(
                    $chunk,
                    ['user_id', 'file_id'],
                    ['type', 'updated_at']
                );
            }
        }

        return [
            'newly_blacklisted_count' => (int) $newlyBlacklistedCount,
            'ids' => $ids,
        ];
    }
}
