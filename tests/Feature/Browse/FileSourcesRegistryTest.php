<?php

use App\Models\File;
use App\Models\FileSource;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('browse source options are served from the file source registry', function () {
    $user = User::factory()->create();

    File::factory()->create(['source' => 'Spotify']);
    File::factory()->create(['source' => 'Bandcamp']);
    File::factory()->create(['source' => 'FeedRemovedOnly', 'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($user)->getJson('/api/browse/services');

    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (string $query): string => strtolower($query))
        ->filter(fn (string $query): bool => str_contains($query, 'from `files`')
            || str_contains($query, 'from "files"'));

    DB::disableQueryLog();

    $response->assertOk();

    $sourceField = collect($response->json('local.schema.fields'))->firstWhere('uiKey', 'source');
    expect($sourceField['options'])->toContain(['label' => 'Spotify', 'value' => 'Spotify'])
        ->and($sourceField['options'])->toContain(['label' => 'Bandcamp', 'value' => 'Bandcamp'])
        ->and($sourceField['options'])->not->toContain(['label' => 'FeedRemovedOnly', 'value' => 'FeedRemovedOnly'])
        ->and($queries)->toBeEmpty();
});

test('file source registry tracks active source state changes', function () {
    $file = File::factory()->create(['source' => 'Transient']);

    expect(FileSource::query()->where('source', 'Transient')->value('active_file_count'))->toBe(1);

    $file->update(['previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT]);

    expect(FileSource::query()->where('source', 'Transient')->value('active_file_count'))->toBe(0);

    $file->update(['source' => 'Recovered', 'previewed_count' => 0]);

    expect(FileSource::query()->where('source', 'Transient')->value('total_file_count'))->toBe(0)
        ->and(FileSource::query()->where('source', 'Recovered')->value('active_file_count'))->toBe(1);
});

test('sync command rebuilds file sources after bulk or eventless file changes', function () {
    File::withoutEvents(function () {
        File::factory()->create(['source' => 'ManualOnly']);
        File::factory()->create(['source' => 'ManualOnly']);
        File::factory()->create(['source' => 'RemovedOnly', 'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT]);
        File::factory()->create(['source' => 'MissingOnly', 'not_found' => true]);
    });

    expect(FileSource::query()->count())->toBe(0);

    $this->artisan('atlas:sync-file-sources')->assertSuccessful();

    expect(FileSource::query()->where('source', 'ManualOnly')->value('total_file_count'))->toBe(2)
        ->and(FileSource::query()->where('source', 'ManualOnly')->value('active_file_count'))->toBe(2)
        ->and(FileSource::query()->where('source', 'RemovedOnly')->value('active_file_count'))->toBe(0)
        ->and(FileSource::query()->where('source', 'MissingOnly')->value('active_file_count'))->toBe(0);
});
