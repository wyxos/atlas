<?php

use App\Jobs\DeleteStoredFileJob;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('dry run reports reacted downloaded deviant art tier gates without mutating rows', function () {
    Bus::fake();

    $target = reactedDeviantArtTierFile();
    $watcherGate = reactedDeviantArtTierFile([
        'listing_metadata' => [
            'premium_folder_data' => [
                'type' => 'watchers',
                'has_access' => false,
            ],
        ],
    ]);
    $notDownloaded = reactedDeviantArtTierFile([
        'downloaded' => false,
        'downloaded_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'download_progress' => 0,
    ]);
    $civitaiFile = reactedDeviantArtTierFile([
        'source' => 'civitai.com',
    ]);

    Artisan::call('atlas:clean-reacted-deviantart-tier-downloads', [
        '--dry-run' => true,
        '--chunk' => 1,
    ]);

    $output = Artisan::output();

    expect($target->fresh()->downloaded)->toBeTrue()
        ->and(Reaction::query()->where('file_id', $target->id)->exists())->toBeTrue()
        ->and($watcherGate->fresh()->downloaded)->toBeTrue()
        ->and($notDownloaded->fresh()->downloaded)->toBeFalse()
        ->and($civitaiFile->fresh()->downloaded)->toBeTrue()
        ->and($output)->toContain('candidate files: 2')
        ->and($output)->toContain('Matched tier-gated files: 1')
        ->and($output)->toContain('Reactions that would be removed: 1')
        ->and($output)->toContain('Downloads that would be cleared: 1')
        ->and($output)->toContain('Dry run only. No rows were changed.');

    Bus::assertNothingDispatched();
});

test('force mode clears reactions and downloaded state for tier gated deviant art files', function () {
    Bus::fake();

    $target = reactedDeviantArtTierFile();

    Artisan::call('atlas:clean-reacted-deviantart-tier-downloads', [
        '--id' => [$target->id],
        '--force' => true,
        '--chunk' => 1,
    ]);

    $output = Artisan::output();
    $freshTarget = $target->fresh();

    expect($freshTarget->downloaded)->toBeFalse()
        ->and($freshTarget->downloaded_at)->toBeNull()
        ->and($freshTarget->path)->toBeNull()
        ->and($freshTarget->preview_path)->toBeNull()
        ->and($freshTarget->poster_path)->toBeNull()
        ->and($freshTarget->download_progress)->toBe(0)
        ->and(Reaction::query()->where('file_id', $target->id)->exists())->toBeFalse()
        ->and($output)->toContain('scope: file IDs '.$target->id)
        ->and($output)->toContain('candidate files: 1')
        ->and($output)->toContain('Matched tier-gated files: 1')
        ->and($output)->toContain('Reactions removed: 1')
        ->and($output)->toContain('Downloads cleared: 1');

    Bus::assertDispatched(DeleteStoredFileJob::class);
});

test('invalid id input fails before cleanup', function () {
    Bus::fake();

    $target = reactedDeviantArtTierFile();

    $exitCode = Artisan::call('atlas:clean-reacted-deviantart-tier-downloads', [
        '--id' => ['3159276,nope'],
        '--force' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and($target->fresh()->downloaded)->toBeTrue()
        ->and(Reaction::query()->where('file_id', $target->id)->exists())->toBeTrue()
        ->and(Artisan::output())->toContain('Every --id value must be a positive integer.');

    Bus::assertNothingDispatched();
});

function reactedDeviantArtTierFile(array $attributes = [], string $reactionType = 'love'): File
{
    $user = User::factory()->create();
    $file = File::factory()->create(array_merge([
        'source' => 'deviantart.com',
        'listing_metadata' => [
            'tier_access' => 'locked',
            'primary_tier' => [
                'id' => 123,
                'title' => 'Tier',
            ],
        ],
        'downloaded' => true,
        'downloaded_at' => '2026-06-01 10:15:00',
        'path' => 'downloads/deviantart/original.jpg',
        'preview_path' => 'downloads/deviantart/preview.jpg',
        'poster_path' => null,
        'download_progress' => 100,
    ], $attributes));

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => $reactionType,
    ]);

    return $file;
}
