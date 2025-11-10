<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('does not dispatch download job when file url is empty on react-download', function () {
    Bus::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    // File with no URL (local-only metadata record)
    $file = File::factory()->create([
        'url' => null,
        'path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.react-download', ['file' => $file->id]), ['type' => 'love']);

    $response->assertOk()->assertJsonFragment(['id' => $file->id, 'reaction' => 'love']);

    // Ensure no download job was dispatched
    Bus::assertNotDispatched(DownloadFile::class);
});

it('batch react only dispatches download jobs for files with non-empty url', function () {
    Bus::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $noUrl1 = File::factory()->create([
        'url' => null,
        'path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $noUrl2 = File::factory()->create([
        'url' => '',
        'path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $withUrl = File::factory()->create([
        'url' => 'https://example.com/file.jpg',
        'path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $payload = [
        'ids' => [$noUrl1->id, $noUrl2->id, $withUrl->id],
        'type' => 'love',
    ];

    $response = $this->postJson(route('browse.files.batch-react'), $payload);

    $response->assertOk()->assertJsonFragment(['type' => 'love']);

    // Only the file with a URL should be queued for download
    Bus::assertDispatchedTimes(DownloadFile::class, 1);
    Bus::assertDispatched(DownloadFile::class, function (DownloadFile $job) use ($withUrl) {
        return (int) $job->file->id === (int) $withUrl->id;
    });
});


