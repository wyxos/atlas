<?php

namespace Database\Factories;

use App\Enums\LibraryScanMediaTask;
use App\Models\LibraryScanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LibraryScanMediaTask>
 */
class LibraryScanMediaTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'library_scan_item_id' => LibraryScanItem::factory(),
            'file_id' => null,
            'type' => LibraryScanMediaTask::TASK_PREVIEW_ASSETS,
            'status' => LibraryScanMediaTask::STATUS_PENDING,
            'phase' => LibraryScanMediaTask::PHASE_QUEUED,
            'progress' => 0,
            'result' => null,
            'error_code' => null,
            'error_message' => null,
            'error_context' => null,
        ];
    }
}
