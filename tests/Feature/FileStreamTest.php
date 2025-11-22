<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('streams video inline with correct headers', function () {
    Storage::fake('atlas');
    Storage::disk('atlas')->put('media/test.mp4', 'fake-mp4-bytes');

    $file = File::factory()->create([
        'path' => 'media/test.mp4',
        'filename' => 'test.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $resp = $this->get(route('files.view', $file));
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toBe('video/mp4');
    expect($resp->headers->get('content-disposition'))->toContain('inline')->toContain('test.mp4');
    expect($resp->headers->get('accept-ranges'))->toBe('bytes');
});

it('returns 404 when file has no local path', function () {
    $file = File::factory()->create([
        'path' => null,
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $this->get(route('files.view', $file))->assertNotFound();
});

it('returns 404 when file is missing on disk', function () {
    Storage::fake('atlas');

    $file = File::factory()->create([
        'path' => 'media/missing.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $this->get(route('files.view', $file))->assertNotFound();
});

it('supports HTTP range requests for video seeking', function () {
    Storage::fake('atlas');
    $content = '0123456789'; // 10 bytes
    Storage::disk('atlas')->put('media/range.mp4', $content);

    $file = File::factory()->create([
        'path' => 'media/range.mp4',
        'filename' => 'range.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('files.view', $file), [
        'Range' => 'bytes=2-5',
    ]);

    $response->assertStatus(206);
    expect($response->headers->get('accept-ranges'))->toBe('bytes');
    expect($response->headers->get('content-range'))->toBe('bytes 2-5/10');
    expect((int) $response->headers->get('content-length'))->toBe(4);

    // Streamed content matching bytes 2..5 -> '2345'
    if (method_exists($response, 'streamedContent')) {
        expect($response->streamedContent())->toBe('2345');
    }
});

it('handles invalid range requests', function () {
    Storage::fake('atlas');
    $content = '0123456789'; // 10 bytes
    Storage::disk('atlas')->put('media/range.mp4', $content);

    $file = File::factory()->create([
        'path' => 'media/range.mp4',
        'filename' => 'range.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('files.view', $file), [
        'Range' => 'bytes=20-30', // Invalid: start beyond file size
    ]);

    $response->assertStatus(416);
    expect($response->headers->get('accept-ranges'))->toBe('bytes');
    expect($response->headers->get('content-range'))->toBe('bytes */10');
});

it('serves preview from thumbnail_path when available', function () {
    Storage::fake('atlas_app');
    Storage::disk('atlas_app')->put('thumbnails/preview.webp', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'media/original.mp4',
        'thumbnail_path' => 'thumbnails/preview.webp',
        'filename' => 'original.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $resp = $this->get(route('files.preview', $file));
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toBe('video/mp4');
    expect($resp->headers->get('content-disposition'))->toContain('inline')->toContain('original.mp4');
});

it('serves preview from path when thumbnail_path is not available', function () {
    Storage::fake('atlas');
    Storage::disk('atlas')->put('media/preview.mp4', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'media/preview.mp4',
        'thumbnail_path' => null,
        'filename' => 'preview.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $resp = $this->get(route('files.preview', $file));
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toBe('video/mp4');
});

it('returns 404 when preview has no path', function () {
    $file = File::factory()->create([
        'path' => null,
        'thumbnail_path' => null,
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $this->get(route('files.preview', $file))->assertNotFound();
});

it('serves preview from atlas_app disk', function () {
    Storage::fake('atlas_app');
    Storage::disk('atlas_app')->put('downloads/tn/preview.mp4', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'downloads/tn/preview.mp4',
        'filename' => 'preview.mp4',
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $resp = $this->get(route('files.preview', $file));
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toBe('video/mp4');
});
