<?php

use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Audio\AudioFingerprint;
use App\Services\Audio\AudioFingerprintService;
use App\Services\Audio\AudioMetadataAiReviewer;
use App\Services\Audio\AudioMetadataCandidateAggregator;
use App\Services\Audio\AudioMetadataCandidateFieldReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('metadata proposal collapses duplicate options and recommends consensus track and disc values', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => '01 Life Was All A Dream',
    ]);

    $currentValues = [
        'album' => 'The Dawn Of My Death',
        'cover_url' => null,
        'track_number' => null,
        'disc_number' => null,
        'release_label' => null,
        'release_date' => null,
        'release_country' => null,
        'musicbrainz_release_id' => null,
    ];
    $releaseId = 'c4d17ca3-4f85-45c0-a13e-471bc88e4ab0';
    $coverUrl = 'https://cover.test/release/dawn-of-my-death/front.jpg';
    $candidates = [
        [
            'provider' => 'acoustid_musicbrainz',
            'confidence' => 96,
            'values' => [
                'track_number' => '1',
                'disc_number' => '1',
                'cover_url' => $coverUrl,
                'musicbrainz_release_id' => $releaseId,
            ],
            'evidence' => [
                'source' => 'acoustid_fingerprint',
                'musicbrainz_release_id' => $releaseId,
                'musicbrainz_recording_id' => '496d2447-f598-4f40-8caf-11603baa0ca1',
                'manual_review_required' => true,
            ],
        ],
        [
            'provider' => 'musicbrainz_cover_art',
            'confidence' => 82,
            'values' => [
                'album' => 'The Dawn Of My Death',
                'cover_url' => $coverUrl,
                'release_label' => 'Rise Records',
                'release_date' => '2008-10-28',
                'release_country' => 'US',
                'musicbrainz_release_id' => $releaseId,
            ],
            'evidence' => [
                'source' => 'musicbrainz_release_search',
                'musicbrainz_release_id' => $releaseId,
                'release_detail_source' => 'musicbrainz_release_lookup',
                'cover_source' => 'cover_art_archive',
            ],
        ],
        [
            'provider' => 'local',
            'confidence' => 70,
            'values' => [
                'track_number' => '1',
                'disc_number' => '1',
            ],
            'evidence' => [
                'source' => 'embedded_tags',
            ],
        ],
    ];

    $this->mock(AudioMetadataCandidateFieldReviewer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('review')
            ->times(3)
            ->andReturnUsing(fn (File $file, array $currentValues, array $candidate): ?array => ($candidate['evidence']['manual_review_required'] ?? false) === true
                ? null
                : $candidate);
    });

    $candidate = app(AudioMetadataCandidateAggregator::class)->aggregate(
        $file,
        $currentValues,
        $candidates,
        fn (array $values): array => metadataOptionChanges($currentValues, $values),
    );

    expect($candidate)->not->toBeNull()
        ->and(data_get($candidate, 'values.track_number'))->toBe('1')
        ->and(data_get($candidate, 'values.disc_number'))->toBe('1')
        ->and(data_get($candidate, 'evidence.field_options.track_number'))->toHaveCount(1)
        ->and(data_get($candidate, 'evidence.field_options.track_number.0.recommended'))->toBeTrue()
        ->and(data_get($candidate, 'evidence.field_options.disc_number'))->toHaveCount(1)
        ->and(data_get($candidate, 'evidence.field_options.disc_number.0.recommended'))->toBeTrue()
        ->and(data_get($candidate, 'evidence.field_options.cover_url'))->toHaveCount(1)
        ->and(data_get($candidate, 'evidence.field_options.cover_url.0.confidence'))->toBe(96)
        ->and(data_get($candidate, 'evidence.field_options.cover_url.0.recommended'))->toBeTrue()
        ->and(data_get($candidate, 'evidence.field_options.musicbrainz_release_id'))->toHaveCount(1)
        ->and(data_get($candidate, 'evidence.field_options.musicbrainz_release_id.0.recommended'))->toBeTrue();
});

test('partial fingerprint review does not hide later album cover candidates', function () {
    config([
        'services.audio_metadata.acoustid_client_key' => 'acoustid-client',
        'services.audio_metadata.acoustid_api_base_url' => 'https://acoustid.test/v2',
        'services.audio_metadata.musicbrainz_api_base_url' => 'https://musicbrainz.test',
        'services.audio_metadata.cover_art_archive_base_url' => 'https://cover.test',
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'ollama',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    $this->mock(AudioFingerprintService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('forFile')
            ->once()
            ->andReturn(new AudioFingerprint('one-more-time-fingerprint', 322, '/tmp/one-more-time.mp3'));
    });

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://acoustid.test/v2/lookup')) {
            return Http::response([
                'status' => 'ok',
                'results' => [[
                    'id' => 'acoustid-one-more-time',
                    'score' => 1.0,
                    'recordings' => [[
                        'id' => 'one-more-time-recording-mbid',
                        'title' => 'One More Time',
                        'duration' => 319000,
                        'artists' => [
                            ['name' => 'Daft Punk'],
                        ],
                        'releases' => [[
                            'id' => 'nrj-story-release-mbid',
                            'title' => 'NRJ Story',
                            'date' => '2002',
                            'country' => 'FR',
                        ]],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/nrj-story-release-mbid') {
            return Http::response([
                'id' => 'nrj-story-release-mbid',
                'title' => 'NRJ Story',
                'date' => '2002',
                'country' => 'FR',
                'media' => [[
                    'position' => 1,
                    'tracks' => [[
                        'number' => '4',
                        'position' => 4,
                        'recording' => ['id' => 'one-more-time-recording-mbid'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/nrj-story-release-mbid') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/nrj-story/front.jpg',
                ]],
            ]);
        }

        if (str_starts_with($url, 'https://musicbrainz.test/ws/2/release?')) {
            return Http::response([
                'releases' => [[
                    'id' => 'discovery-release-mbid',
                    'title' => 'Discovery',
                    'date' => '2001-03-12',
                    'country' => 'XE',
                    'score' => 100,
                    'artist-credit' => [[
                        'name' => 'Daft Punk',
                        'artist' => ['name' => 'Daft Punk'],
                    ]],
                ]],
            ]);
        }

        if ($url === 'https://musicbrainz.test/ws/2/release/discovery-release-mbid') {
            return Http::response([
                'id' => 'discovery-release-mbid',
                'title' => 'Discovery',
                'date' => '2001-03-12',
                'country' => 'XE',
                'barcode' => '724354278228',
                'label-info' => [[
                    'catalog-number' => '724354278228',
                    'label' => ['name' => 'Virgin'],
                ]],
            ]);
        }

        if ($url === 'https://cover.test/release/discovery-release-mbid') {
            return Http::response([
                'images' => [[
                    'front' => true,
                    'image' => 'https://cover.test/release/discovery/front.jpg',
                    'thumbnails' => [
                        '500' => 'https://cover.test/release/discovery/front-500.jpg',
                    ],
                ]],
            ]);
        }

        if ($url === 'https://ollama.test/api/chat') {
            return Http::response([
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'ambiguous',
                        'confidence' => 0.82,
                        'reason' => 'The recording is right, but the attached MusicBrainz release is a compilation.',
                        'model' => 'qwen-test',
                        'safe_fields' => ['artists', 'duration_seconds'],
                        'field_reviews' => [
                            'artists' => [
                                'verdict' => 'accept',
                                'confidence' => 0.92,
                                'reason' => 'The artist credit matches the current recording identity.',
                            ],
                            'duration_seconds' => [
                                'verdict' => 'accept',
                                'confidence' => 0.9,
                                'reason' => 'The duration delta is small enough for the same recording.',
                            ],
                            'album' => [
                                'verdict' => 'ambiguous',
                                'confidence' => 0.35,
                                'reason' => 'The MusicBrainz release is a compilation and should not replace the current album automatically.',
                            ],
                            'cover_url' => [
                                'verdict' => 'ambiguous',
                                'confidence' => 0.35,
                                'reason' => 'The cover belongs to the compilation release, not the current album.',
                            ],
                        ],
                    ]),
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'One More Time',
    ]);
    $file->artists()->create([
        'name' => 'Daft Punk',
        'normalized_name' => 'daft punk',
    ]);
    $file->albums()->create([
        'name' => 'Discovery',
        'normalized_name' => 'discovery',
    ]);
    $file->metadata()->create([
        'payload' => [
            'title' => 'One More Time',
            'artist' => 'Daft Punk',
            'album' => 'Discovery',
            'duration' => 322,
        ],
    ]);

    $response = $this->actingAs($user)->postJson("/api/audio/{$file->id}/metadata-runs");

    $response->assertAccepted()
        ->assertJsonPath('proposal.provider', 'multi_source_review')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/release/discovery/front-500.jpg')
        ->assertJsonPath('proposal.proposed_values.musicbrainz_release_id', 'discovery-release-mbid')
        ->assertJsonPath('proposal.field_options.album.0.value', 'NRJ Story')
        ->assertJsonPath('proposal.field_options.album.0.recommended', false)
        ->assertJsonPath('proposal.field_options.album.0.reason', 'The MusicBrainz release is a compilation and should not replace the current album automatically.')
        ->assertJsonPath('proposal.field_options.album.0.review_verdict', 'ambiguous')
        ->assertJsonPath('proposal.field_options.album.0.source_label', 'MusicBrainz release')
        ->assertJsonPath('proposal.field_options.album.0.source_url', 'https://musicbrainz.org/release/nrj-story-release-mbid')
        ->assertJsonPath('proposal.field_options.album.1.value', 'Discovery')
        ->assertJsonPath('proposal.field_options.album.1.recommended', true)
        ->assertJsonPath('proposal.field_options.album.1.source_label', 'MusicBrainz release')
        ->assertJsonPath('proposal.field_options.album.1.source_url', 'https://musicbrainz.org/release/discovery-release-mbid')
        ->assertJsonPath('proposal.field_options.cover_url.0.value', 'https://cover.test/release/nrj-story/front.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.0.recommended', false)
        ->assertJsonPath('proposal.field_options.cover_url.0.reason', 'The cover belongs to the compilation release, not the current album.')
        ->assertJsonPath('proposal.evidence.field_review.field_reviews.album.reason', 'The MusicBrainz release is a compilation and should not replace the current album automatically.')
        ->assertJsonPath('proposal.field_options.cover_url.0.source_url', 'https://musicbrainz.org/release/nrj-story-release-mbid')
        ->assertJsonPath('proposal.field_options.cover_url.1.value', 'https://cover.test/release/discovery/front-500.jpg')
        ->assertJsonPath('proposal.field_options.cover_url.1.recommended', true)
        ->assertJsonPath('proposal.field_options.cover_url.1.source_url', 'https://musicbrainz.org/release/discovery-release-mbid')
        ->assertJsonPath('run.proposal_count', 1)
        ->assertJsonPath('run.failed_files', 0);
});

test('metadata proposal can apply manually selected provider option values', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);
    $file->metadata()->create(['payload' => []]);
    $run = AudioMetadataRun::query()->create([
        'user_id' => $user->id,
        'scope' => 'single',
        'source_filter' => 'all',
        'status' => 'completed',
        'total_files' => 1,
        'processed_files' => 1,
        'proposal_count' => 1,
        'failed_files' => 0,
    ]);
    $proposal = AudioMetadataProposal::query()->create([
        'audio_metadata_run_id' => $run->id,
        'file_id' => $file->id,
        'provider' => 'multi_source_review',
        'status' => 'pending',
        'current_values' => [
            'album' => 'Current Album',
            'cover_url' => null,
        ],
        'proposed_values' => [],
        'changes' => [],
        'evidence' => [
            'field_options' => [
                'album' => [[
                    'id' => 'album-discogs-option',
                    'provider' => 'discogs_release',
                    'confidence' => 90,
                    'value' => 'Selected Album',
                    'recommended' => false,
                ]],
                'cover_url' => [[
                    'id' => 'cover-discogs-option',
                    'provider' => 'discogs_release',
                    'confidence' => 90,
                    'value' => 'https://cover.test/selected.jpg',
                    'recommended' => false,
                ]],
            ],
        ],
    ]);

    Http::fake([
        'https://cover.test/selected.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->actingAs($user)->patchJson("/api/audio/metadata-proposals/{$proposal->id}", [
        'action' => 'apply',
        'fields' => ['album', 'cover_url'],
        'field_options' => [
            'album' => 'album-discogs-option',
            'cover_url' => 'cover-discogs-option',
        ],
    ])->assertSuccessful()
        ->assertJsonPath('proposal.status', 'applied')
        ->assertJsonPath('proposal.proposed_values.album', 'Selected Album')
        ->assertJsonPath('proposal.proposed_values.cover_url', 'https://cover.test/selected.jpg');

    $file = $file->fresh(['albums.defaultCover']);
    $album = $file->albums->first();

    expect($album?->name)->toBe('Selected Album')
        ->and($album?->defaultCover?->path)->not->toBeNull();
});

test('ai field review replaces placeholder and missing field reasons with explicit fallback text', function () {
    config([
        'services.audio_metadata.ai_enabled' => true,
        'services.audio_metadata.ai_driver' => 'gateway',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test',
        'services.audio_metadata.ai_token' => 'ai-token',
        'services.audio_metadata.ai_model' => 'qwen-test',
    ]);

    Http::fake([
        'https://ollama.test/v1/audio/metadata-review' => Http::response([
            'verdict' => 'ambiguous',
            'confidence' => 0.82,
            'reason' => 'short summary',
            'model' => 'qwen-test',
            'safe_fields' => ['album'],
            'field_reviews' => [
                'album' => [
                    'verdict' => 'accept',
                    'confidence' => 0.95,
                    'reason' => 'The release title matches the current album.',
                ],
                'disc_number' => [
                    'verdict' => 'ambiguous',
                    'confidence' => 0.55,
                    'reason' => 'field-specific reason',
                ],
                'musicbrainz_recording_id' => [
                    'verdict' => 'ambiguous',
                    'confidence' => 0.55,
                    'reason' => null,
                ],
            ],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'The Nighttime Is Our Time',
    ]);

    $review = app(AudioMetadataAiReviewer::class)->reviewFields($file, [
        'title' => 'The Nighttime Is Our Time',
        'album' => 'Before Their Eyes',
        'disc_number' => null,
        'musicbrainz_recording_id' => null,
    ], [
        'provider' => 'discogs_release',
        'confidence' => 96,
        'values' => [
            'album' => 'Before Their Eyes',
            'disc_number' => '1',
            'musicbrainz_recording_id' => '1604f439-ecc4-4c1e-a669-5b6eea204fc9',
        ],
        'evidence' => [
            'source' => 'discogs_release_search',
            'discogs_release_id' => '3473080',
        ],
    ], [
        'disc_number' => ['current' => null, 'proposed' => '1'],
        'musicbrainz_recording_id' => ['current' => null, 'proposed' => '1604f439-ecc4-4c1e-a669-5b6eea204fc9'],
    ]);

    expect($review)->not->toBeNull()
        ->and($review['reason'])->toBe('AI did not return a usable review summary.')
        ->and($review['field_reviews']['disc_number']['reason'])->toBe('AI marked this field ambiguous but did not return a field-specific reason.')
        ->and($review['field_reviews']['musicbrainz_recording_id']['reason'])->toBe('AI marked this field ambiguous but did not return a field-specific reason.');
});

function metadataOptionChanges(array $currentValues, array $proposedValues): array
{
    $changes = [];

    foreach ($proposedValues as $field => $proposed) {
        $current = $currentValues[$field] ?? null;
        if (metadataOptionComparableValue($current) === metadataOptionComparableValue($proposed)) {
            continue;
        }

        $changes[$field] = [
            'current' => $current,
            'proposed' => $proposed,
        ];
    }

    return $changes;
}

function metadataOptionComparableValue(mixed $value): string
{
    if (is_array($value)) {
        return implode('|', array_map(fn (mixed $entry): string => metadataOptionComparableValue($entry), $value));
    }

    return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?? '';
}
