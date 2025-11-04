<?php

use App\Jobs\RenameDownloadedFile;
use App\Models\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('queues rename jobs for eligible files', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    Storage::disk('atlas_app')->put('downloads/legacy.jpg', 'file');
    Storage::disk('atlas')->put('downloads/legacy.jpg', 'file');

    $eligible = File::factory()->create([
        'downloaded' => true,
        'filename' => 'legacy.jpg',
        'path' => 'downloads/legacy.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    File::factory()->create([
        'downloaded' => true,
        'filename' => Str::random(40).'.jpg',
        'path' => 'downloads/'.Str::random(40).'.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    Bus::fake();

    Artisan::call('files:randomize-downloaded');

    Bus::assertDispatched(RenameDownloadedFile::class, function ($job) use ($eligible) {
        return $job->fileId === $eligible->id;
    });
});

it('supports dry run without dispatching jobs', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    Storage::disk('atlas_app')->put('downloads/dry-run.jpg', 'content');

    $file = File::factory()->create([
        'downloaded' => true,
        'filename' => 'dry-run.jpg',
        'path' => 'downloads/dry-run.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    Bus::fake();

    Artisan::call('files:randomize-downloaded', ['--dry-run' => true]);

    Bus::assertNothingDispatched();

    $output = Artisan::output();

    expect($output)->toContain((string) $file->id)
        ->and($output)->toContain('Dry run complete');
});
