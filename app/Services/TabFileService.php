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
     *
     * @param  array<int>  $fileIds
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
}
