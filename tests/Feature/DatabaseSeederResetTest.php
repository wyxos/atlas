<?php

use App\Models\File;
use App\Models\Tab;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('seeder resets app state when atlas storage has content', function () {
    Storage::fake('atlas-app');
    Storage::fake('local');

    File::factory()->count(2)->create();
    Tab::factory()->count(2)->create();

    Storage::disk('atlas-app')->put('downloads/sample.txt', 'payload');
    Storage::disk('local')->put('private/sample.txt', 'payload');

    (new DatabaseSeeder)->run();

    expect(File::count())->toBe(0);
    expect(Tab::count())->toBe(0);
    expect(Storage::disk('atlas-app')->allFiles())->toBe([]);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('seeder does not reset app state in production', function () {
    app()->detectEnvironment(fn () => 'production');

    Storage::fake('atlas-app');
    Storage::fake('local');

    File::factory()->count(2)->create();
    Tab::factory()->count(2)->create();

    Storage::disk('atlas-app')->put('downloads/sample.txt', 'payload');
    Storage::disk('local')->put('private/sample.txt', 'payload');

    (new DatabaseSeeder)->run();

    expect(File::count())->toBe(2);
    expect(Tab::count())->toBe(2);
    expect(Storage::disk('atlas-app')->allFiles())->not()->toBe([]);
    expect(Storage::disk('local')->allFiles())->not()->toBe([]);

    app()->detectEnvironment(fn () => 'testing');
});
