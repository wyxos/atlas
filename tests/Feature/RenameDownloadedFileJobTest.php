<?php

use App\Jobs\RenameDownloadedFile;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

it('renames downloaded files across available disks', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    Storage::disk('atlas_app')->put('downloads/original.jpg', 'test');
    Storage::disk('atlas')->put('downloads/original.jpg', 'test');

    $file = File::factory()->create([
        'downloaded' => true,
        'filename' => 'original.jpg',
        'path' => 'downloads/original.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $job = new RenameDownloadedFile($file->id);
    $job->handle();

    $file->refresh();

    expect($file->filename)
        ->toMatch('/^[A-Za-z0-9]{40}\.jpg$/');
    expect($file->path)
        ->toMatch('/^downloads\/[A-Za-z0-9]{40}\.jpg$/');

    Storage::disk('atlas_app')->assertMissing('downloads/original.jpg');
    Storage::disk('atlas')->assertMissing('downloads/original.jpg');
    Storage::disk('atlas_app')->assertExists($file->path);
    Storage::disk('atlas')->assertExists($file->path);
});

it('exits early when file is missing on disks', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $file = File::factory()->create([
        'downloaded' => true,
        'filename' => 'ghost.jpg',
        'path' => 'downloads/ghost.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $job = new RenameDownloadedFile($file->id);
    $job->handle();

    $file->refresh();

    expect($file->filename)->toBe('ghost.jpg');
    expect($file->path)->toBe('downloads/ghost.jpg');
});
