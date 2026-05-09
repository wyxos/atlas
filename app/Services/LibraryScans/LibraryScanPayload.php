<?php

namespace App\Services\LibraryScans;

use App\Enums\LibraryScanRunMode;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;

class LibraryScanPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function run(LibraryScanRun $run): array
    {
        return [
            'id' => $run->id,
            'mode' => $run->mode ?: LibraryScanRunMode::SCAN,
            'status' => $run->status,
            'phase' => $run->phase,
            'files_found' => (int) $run->files_found,
            'files_imported' => (int) $run->files_imported,
            'files_duplicate' => (int) $run->files_duplicate,
            'files_processed' => (int) $run->files_processed,
            'files_failed' => (int) $run->files_failed,
            'files_canceled' => (int) $run->files_canceled,
            'started_at' => $run->started_at?->toIso8601String(),
            'scan_completed_at' => $run->scan_completed_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'paused_at' => $run->paused_at?->toIso8601String(),
            'canceled_at' => $run->canceled_at?->toIso8601String(),
            'error' => $run->error,
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(LibraryScanItem $item): array
    {
        return [
            'id' => $item->id,
            'library_scan_run_id' => $item->library_scan_run_id,
            'file_id' => $item->file_id,
            'original_path' => $item->original_path,
            'imported_path' => $item->imported_path,
            'hash' => $item->hash,
            'mime_type' => $item->mime_type,
            'size' => $item->size,
            'status' => $item->status,
            'phase' => $item->phase,
            'progress' => (int) $item->progress,
            'duplicate' => (bool) $item->duplicate,
            'parser' => $item->parser,
            'media_tasks' => $item->relationLoaded('mediaTasks')
                ? $item->mediaTasks->map(fn ($task): array => [
                    'id' => $task->id,
                    'type' => $task->type,
                    'status' => $task->status,
                    'phase' => $task->phase,
                    'progress' => (int) $task->progress,
                    'error_code' => $task->error_code,
                    'error_message' => $task->error_message,
                    'result' => $task->result,
                    'created_at' => $task->created_at?->toIso8601String(),
                    'updated_at' => $task->updated_at?->toIso8601String(),
                ])->values()
                : [],
            'error_code' => $item->error_code,
            'error_message' => $item->error_message,
            'error_context' => $item->error_context,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
