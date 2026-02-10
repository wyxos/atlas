<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('icon endpoint returns an svg for non-image/video files', function () {
    /** @var \Tests\TestCase $this */
    $admin = User::factory()->admin()->create();

    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'ext' => 'mp3',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp3',
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/icon");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
    expect($response->getContent())->toContain('<svg');
});

