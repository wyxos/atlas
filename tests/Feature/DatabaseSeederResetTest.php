<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('seeder preserves app state and storage when atlas storage has content', function () {
    Storage::fake('atlas');
    Storage::fake('local');

    File::factory()->count(2)->create();
    Tab::factory()->count(2)->create();

    Storage::disk('atlas')->put('downloads/sample.txt', 'payload');
    Storage::disk('local')->put('private/sample.txt', 'payload');

    $existingFileCount = File::count();
    $existingUserCount = User::count();

    (new DatabaseSeeder)->run();

    expect(File::count())->toBe($existingFileCount + 1000);
    expect(Tab::count())->toBe(2);
    expect(User::count())->toBe($existingUserCount + 26);
    expect(Storage::disk('atlas')->exists('downloads/sample.txt'))->toBeTrue();
    expect(Storage::disk('atlas')->allFiles())->toHaveCount(3);
    expect(Storage::disk('local')->allFiles())->toBe(['private/sample.txt']);
});

test('seeder does not reset app state in production', function () {
    app()->detectEnvironment(fn () => 'production');

    Storage::fake('atlas');
    Storage::fake('local');

    File::factory()->count(2)->create();
    Tab::factory()->count(2)->create();

    Storage::disk('atlas')->put('downloads/sample.txt', 'payload');
    Storage::disk('local')->put('private/sample.txt', 'payload');

    (new DatabaseSeeder)->run();

    expect(File::count())->toBe(2);
    expect(Tab::count())->toBe(2);
    expect(Storage::disk('atlas')->allFiles())->not()->toBe([]);
    expect(Storage::disk('local')->allFiles())->not()->toBe([]);

    app()->detectEnvironment(fn () => 'testing');
});
