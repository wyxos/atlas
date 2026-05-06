<?php

namespace Database\Factories;

use App\Enums\LibraryScanRunStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LibraryScanRun>
 */
class LibraryScanRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status' => LibraryScanRunStatus::PENDING,
            'phase' => 'pending',
            'files_found' => 0,
            'files_imported' => 0,
            'files_duplicate' => 0,
            'files_processed' => 0,
            'files_failed' => 0,
            'files_canceled' => 0,
        ];
    }
}
