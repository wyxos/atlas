<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('streams audio inline with correct headers', function () {
    Storage::fake('atlas');
    Storage::disk('atlas')->put('media/test.mp3', 'fake-mp3-bytes');

    $file = File::factory()->create([
        'path' => 'media/test.mp3',
        'filename' => 'test.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $resp = $this->get(route('audio.stream', $file));
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toBe('audio/mpeg');
    expect($resp->headers->get('content-disposition'))->toContain('inline')->toContain('test.mp3');
});

it('returns 404 when file has no local path', function () {
    $file = File::factory()->create([
        'path' => null,
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $this->get(route('audio.stream', $file))->assertNotFound();
});

it('returns 404 when file is missing on disk', function () {
    Storage::fake('atlas');

    $file = File::factory()->create([
        'path' => 'media/missing.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $this->get(route('audio.stream', $file))->assertNotFound();
});

it('supports HTTP range requests for seeking', function () {
    Storage::fake('atlas');
    $content = '0123456789'; // 10 bytes
    Storage::disk('atlas')->put('media/range.mp3', $content);

    $file = File::factory()->create([
        'path' => 'media/range.mp3',
        'filename' => 'range.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->get(route('audio.stream', $file), [
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
