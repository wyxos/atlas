<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('serve supports byte range requests', function () {
    $admin = User::factory()->admin()->create();

    $testContent = '0123456789abcdefghijklmnopqrstuvwxyz';
    $filePath = 'private/videos/ab/cd/range-test.mp4';
    $fullPath = storage_path("app/{$filePath}");

    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0755, true);
    }

    file_put_contents($fullPath, $testContent);

    $file = File::factory()->create([
        'path' => $filePath,
        'mime_type' => 'video/mp4',
    ]);

    $response = $this->actingAs($admin)
        ->withHeader('Range', 'bytes=0-9')
        ->get("/api/files/{$file->id}/serve");

    $response->assertStatus(206);
    $response->assertHeader('Accept-Ranges', 'bytes');
    $response->assertHeader('Content-Range', 'bytes 0-9/'.strlen($testContent));
    expect($response->streamedContent())->toBe(substr($testContent, 0, 10));

    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
});

test('serve returns 200 with Accept-Ranges when no range header is provided', function () {
    $admin = User::factory()->admin()->create();

    $testContent = 'abcdefghijklmnopqrstuvwxyz';
    $filePath = 'private/videos/ab/cd/range-test-full.mp4';
    $fullPath = storage_path("app/{$filePath}");

    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0755, true);
    }

    file_put_contents($fullPath, $testContent);

    $file = File::factory()->create([
        'path' => $filePath,
        'mime_type' => 'video/mp4',
    ]);

    $response = $this->actingAs($admin)->get("/api/files/{$file->id}/serve");

    $response->assertSuccessful();
    $response->assertHeader('Accept-Ranges', 'bytes');

    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
});
