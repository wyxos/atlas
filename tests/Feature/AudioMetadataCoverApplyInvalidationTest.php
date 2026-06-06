<?php

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioMetadataProposalApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('applying an album cover broadcasts every file on the affected album', function () {
    Event::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Miracle Mix',
        'preview_url' => null,
    ]);
    $sibling = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Miracle Dub',
        'preview_url' => null,
    ]);
    $album = Album::factory()->create([
        'name' => 'Miracle',
        'normalized_name' => 'miracle',
    ]);
    $sourceAlbum = Album::factory()->create([
        'name' => 'Miracle',
        'normalized_name' => 'miracle',
    ]);
    $sourceCover = AlbumCover::factory()->create([
        'album_id' => $sourceAlbum->id,
        'file_id' => $file->id,
        'path' => 'imports/miracle/covers/front.jpg',
        'path_hash' => hash('sha256', 'imports/miracle/covers/front.jpg'),
        'hash' => 'source-cover-hash',
        'mime_type' => 'image/jpeg',
        'picture_type' => 'front',
        'sort_order' => 0,
        'is_default' => true,
    ]);

    $file->albums()->sync([$album->id]);
    $sibling->albums()->sync([$album->id]);

    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'local',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'options' => ['file_id' => $file->id],
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'existing_album_cover',
        'status' => 'pending',
        'confidence' => 88,
        'current_values' => ['cover_url' => null],
        'proposed_values' => ['cover_url' => "/api/audio/album-covers/{$sourceCover->id}"],
        'changes' => [
            'cover_url' => [
                'current' => null,
                'proposed' => "/api/audio/album-covers/{$sourceCover->id}",
            ],
        ],
        'evidence' => ['source' => 'existing_album_cover'],
    ]);

    app(AudioMetadataProposalApplier::class)->apply($proposal, $user, ['cover_url']);

    Event::assertDispatched('App\\Events\\AudioFilesChanged', function (object $event) use ($file, $sibling, $user): bool {
        $fileIds = data_get($event, 'fileIds');

        return data_get($event, 'userId') === $user->id
            && data_get($event, 'reason') === 'metadata_applied'
            && is_array($fileIds)
            && collect($fileIds)->sort()->values()->all() === collect([$file->id, $sibling->id])->sort()->values()->all();
    });
});
