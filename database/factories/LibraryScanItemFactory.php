<?php

namespace Database\Factories;

use App\Enums\LibraryScanItemStatus;
use App\Models\LibraryScanRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LibraryScanItem>
 */
class LibraryScanItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'library_scan_run_id' => LibraryScanRun::factory(),
            'file_id' => null,
            'original_path' => fake()->filePath(),
            'imported_path' => null,
            'hash' => null,
            'mime_type' => null,
            'size' => null,
            'status' => LibraryScanItemStatus::PENDING,
            'phase' => 'pending',
            'progress' => 0,
            'duplicate' => false,
            'parser' => null,
            'parser_queued_at' => null,
        ];
    }
}
