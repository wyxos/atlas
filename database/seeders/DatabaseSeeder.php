<?php

namespace Database\Seeders;

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->resetAppIfStorageNotEmpty();

        // Demo user (admin)
        User::create([
            'name' => 'Demo User',
            'email' => 'demo@atlas.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);

        // Seed additional users using factory
        User::factory()->count(25)->create();
    }

    /**
     * Reset app state if atlas storage contains files or directories.
     */
    private function resetAppIfStorageNotEmpty(): void
    {
        $atlasDisk = Storage::disk('atlas-app');
        $localDisk = Storage::disk('local');

        $hasAtlasDirectories = $atlasDisk->directories() !== [];
        $hasAtlasFiles = collect($atlasDisk->files())
            ->reject(fn (string $file) => str_starts_with(basename($file), '.'))
            ->isNotEmpty();
        $hasPrivateFiles = $localDisk->exists('private');

        if (! $hasAtlasDirectories && ! $hasAtlasFiles && ! $hasPrivateFiles) {
            return;
        }

        Tab::query()->delete();
        File::query()->delete();

        foreach ($atlasDisk->directories() as $directory) {
            $atlasDisk->deleteDirectory($directory);
        }

        foreach ($atlasDisk->files() as $file) {
            if (str_starts_with(basename($file), '.')) {
                continue;
            }

            $atlasDisk->delete($file);
        }

        if ($localDisk->exists('private')) {
            $localDisk->deleteDirectory('private');
        }
    }
}
