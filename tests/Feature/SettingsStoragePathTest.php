<?php

use App\Models\Setting;
use App\Models\User;

it('updates atlas storage path for current machine and applies config', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Hit update endpoint
    $newPath = DIRECTORY_SEPARATOR === '\\' ? 'D:/media/atlas' : '/tmp/media/atlas';
    $this->put(route('storage.update'), ['path' => $newPath])
        ->assertRedirect();

    // Stored as machine override
    $hostname = gethostname() ?: php_uname('n');
    $row = Setting::where('key', 'atlas.path')->where('machine', $hostname)->first();
    expect($row)->not->toBeNull();
    expect($row->value)->toBe($newPath);

    // Config updated for this request
    expect(config('filesystems.disks.atlas.root'))->toBe($newPath);
});
