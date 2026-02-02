<?php

use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('preview request queues generation when preview is missing', function () {
    /** @var \Tests\TestCase $this */
    /** @var \App\Models\User $admin */
    $admin = User::factory()->admin()->create();

    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => null,
        'mime_type' => 'video/mp4',
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'video');

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/preview");

    $response->assertNotFound();
    Queue::assertPushed(GenerateFilePreviewAssets::class);
});
