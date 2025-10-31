<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

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
        ->postJson('/browse/files/'.$file->id.'/report-missing')
        ->assertOk()
        ->assertJsonFragment(['ok' => true, 'id' => $file->id, 'not_found' => true, 'verified' => true]);

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
        ->assertJsonFragment(['ok' => true, 'id' => $file->id, 'not_found' => false, 'verified' => true]);

    expect($file->fresh()->not_found)->toBeFalse();
});
