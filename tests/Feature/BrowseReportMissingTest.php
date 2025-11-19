<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('returns status information when media is available', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'url' => 'https://example.com/original.jpg',
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.report-missing', ['file' => $file->id]), ['verify' => true]);

    $response->assertOk()
        ->assertJson([
            'not_found' => false,
            'verified' => true,
            'status' => 200,
        ]);

    expect($file->fresh()->not_found)->toBeFalse();
});

it('marks media as not found when upstream responds with 404', function () {
    Http::fake([
        'https://missing.example.com/*' => Http::response('', 404),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'thumbnail_url' => 'https://missing.example.com/thumb.jpg',
        'url' => 'https://missing.example.com/original.jpg',
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.report-missing', ['file' => $file->id]), ['verify' => true]);

    $response->assertOk()
        ->assertJson([
            'not_found' => true,
            'verified' => true,
            'status' => 404,
        ]);

    expect($file->fresh()->not_found)->toBeTrue();
});

it('returns upstream status without marking not found for other errors', function () {
    Http::fake([
        'https://forbidden.example.com/*' => Http::response('', 403),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'thumbnail_url' => 'https://forbidden.example.com/thumb.jpg',
        'url' => 'https://forbidden.example.com/original.jpg',
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.report-missing', ['file' => $file->id]), ['verify' => true]);

    $response->assertOk()
        ->assertJson([
            'not_found' => false,
            'verified' => true,
            'status' => 403,
        ]);

    expect($file->fresh()->not_found)->toBeFalse();
});

it('requires authentication to report missing media', function () {
    $file = File::factory()->create([
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $this->post('/browse/files/'.$file->id.'/report-missing')
        ->assertRedirect('/login');
});

it('marks file as not_found when reporting missing', function () {
    $file = File::factory()->create([
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/browse/files/'.$file->id.'/report-missing', ['verify' => false])
        ->assertOk()
        ->assertJsonFragment(['ok' => true, 'id' => $file->id, 'not_found' => true, 'verified' => false, 'status' => null]);

    expect($file->fresh()->not_found)->toBeTrue();
});

it('does not mark as missing when thumbnail exists on disk', function (): void {
    Storage::fake('atlas_app');

    Storage::disk('atlas_app')->put('thumbnails/existing.webp', 'thumb');

    $file = File::factory()->create([
        'not_found' => false,
        'blacklisted_at' => null,
        'thumbnail_path' => 'thumbnails/existing.webp',
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/browse/files/'.$file->id.'/report-missing', ['verify' => true])
        ->assertOk()
        ->assertJsonFragment([
            'ok' => true,
            'id' => $file->id,
            'not_found' => false,
            'verified' => true,
            'status' => 200,
        ]);

    expect($file->fresh()->not_found)->toBeFalse();
});

it('sends civitai headers when file source is CivitAI', function () {
    Http::fake([
        'https://image.civitai.com/*' => Http::response('', 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'thumbnail_url' => 'https://image.civitai.com/test.jpg',
        'url' => 'https://image.civitai.com/original.jpg',
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.report-missing', ['file' => $file->id]), ['verify' => true]);

    $response->assertOk();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://image.civitai.com/test.jpg'
            && $request->hasHeader('Referer', 'https://civitai.com/')
            && $request->hasHeader('User-Agent', 'Atlas/1.0')
            && $request->hasHeader('Accept', 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8');
    });
});

it('sends civitai headers when url contains civitai.com', function () {
    Http::fake([
        'https://image.civitai.com/*' => Http::response('', 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'source' => 'Other',
        'thumbnail_url' => 'https://image.civitai.com/test.jpg',
        'url' => 'https://image.civitai.com/original.jpg',
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.report-missing', ['file' => $file->id]), ['verify' => true]);

    $response->assertOk();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://image.civitai.com/test.jpg'
            && $request->hasHeader('Referer', 'https://civitai.com/')
            && $request->hasHeader('User-Agent', 'Atlas/1.0')
            && $request->hasHeader('Accept', 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8');
    });
});

it('sends civitai headers when thumbnail_url contains civitai.com', function () {
    Http::fake([
        'https://image.civitai.com/*' => Http::response('', 200),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $file = File::factory()->create([
        'source' => 'Other',
        'thumbnail_url' => 'https://image.civitai.com/thumb.jpg',
        'url' => 'https://other.com/original.jpg',
        'not_found' => false,
    ]);

    $response = $this->postJson(route('browse.files.report-missing', ['file' => $file->id]), ['verify' => true]);

    $response->assertOk();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://image.civitai.com/thumb.jpg'
            && $request->hasHeader('Referer', 'https://civitai.com/')
            && $request->hasHeader('User-Agent', 'Atlas/1.0')
            && $request->hasHeader('Accept', 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8');
    });
});
