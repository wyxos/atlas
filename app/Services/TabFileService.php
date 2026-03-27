<?php

namespace App\Services;

use App\Models\Tab;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing tab_file pivot table operations.
 * Provides efficient bulk operations for detaching files from tabs.
 */
class TabFileService
{
    /**
     * Detach a single file from all tabs belonging to a user.
     */
    public function detachFileFromUserTabs(int $userId, int $fileId): void
    {
        $userTabIds = Tab::forUser($userId)->pluck('id');
        if ($userTabIds->isNotEmpty()) {
            DB::table('tab_file')
                ->whereIn('tab_id', $userTabIds)
                ->where('file_id', $fileId)
                ->delete();
        }
    }

    /**
     * Detach multiple files from all tabs belonging to a user.
     */
    public function detachFilesFromUserTabs(int $userId, array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $userTabIds = Tab::forUser($userId)->pluck('id');
        if ($userTabIds->isNotEmpty()) {
            DB::table('tab_file')
                ->whereIn('tab_id', $userTabIds)
                ->whereIn('file_id', $fileIds)
                ->delete();
        }
    }

    /**
     * Detach a file from every tab it is currently attached to.
     *
     * @return array<int, array{user_id: int, tab_ids: array<int>}>
     */
    public function detachFileFromAllTabs(int $fileId): array
    {
        $attachments = DB::table('tab_file')
            ->join('tabs', 'tabs.id', '=', 'tab_file.tab_id')
            ->where('tab_file.file_id', $fileId)
            ->select('tabs.user_id', 'tab_file.tab_id')
            ->get();

        if ($attachments->isEmpty()) {
            return [];
        }

        DB::table('tab_file')
            ->where('file_id', $fileId)
            ->delete();

        return $attachments
            ->groupBy('user_id')
            ->map(function ($rows, $userId): array {
                return [
                    'user_id' => (int) $userId,
                    'tab_ids' => collect($rows)
                        ->pluck('tab_id')
                        ->map(fn ($tabId): int => (int) $tabId)
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
