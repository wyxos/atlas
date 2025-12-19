<?php

namespace App\Services;

use App\Models\BrowseTab;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing browse_tab_file pivot table operations.
 * Provides efficient bulk operations for detaching files from tabs.
 */
class BrowseTabFileService
{
    /**
     * Detach a single file from all tabs belonging to a user.
     *
     * @param  int  $userId
     * @param  int  $fileId
     * @return void
     */
    public function detachFileFromUserTabs(int $userId, int $fileId): void
    {
        $userTabIds = BrowseTab::forUser($userId)->pluck('id');
        if ($userTabIds->isNotEmpty()) {
            DB::table('browse_tab_file')
                ->whereIn('browse_tab_id', $userTabIds)
                ->where('file_id', $fileId)
                ->delete();
        }
    }

    /**
     * Detach multiple files from all tabs belonging to a user.
     *
     * @param  int  $userId
     * @param  array<int>  $fileIds
     * @return void
     */
    public function detachFilesFromUserTabs(int $userId, array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        $userTabIds = BrowseTab::forUser($userId)->pluck('id');
        if ($userTabIds->isNotEmpty()) {
            DB::table('browse_tab_file')
                ->whereIn('browse_tab_id', $userTabIds)
                ->whereIn('file_id', $fileIds)
                ->delete();
        }
    }
}

