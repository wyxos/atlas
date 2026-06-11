<?php

use App\Models\File;
use App\Services\Audio\AudioMetadataCandidateAggregator;
use App\Services\Audio\AudioMetadataCandidateFieldReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('catalog provider options carry source links', function () {
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'The Soultaker',
    ]);
    $currentValues = [
        'spotify_uri' => null,
        'release_date' => null,
        'isrc' => null,
    ];

    $this->mock(AudioMetadataCandidateFieldReviewer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('review')
            ->times(3)
            ->andReturnUsing(fn (File $file, array $currentValues, array $candidate): array => $candidate);
    });

    $candidate = app(AudioMetadataCandidateAggregator::class)->aggregate(
        $file,
        $currentValues,
        [
            [
                'provider' => 'spotify_catalog',
                'confidence' => 88,
                'values' => [
                    'spotify_uri' => 'spotify:track:1WUjJPUznd8NJVdzqUpT1M',
                ],
                'evidence' => [
                    'source' => 'spotify_catalog_search',
                    'spotify_track_id' => '1WUjJPUznd8NJVdzqUpT1M',
                    'spotify_track_url' => 'https://open.spotify.com/track/1WUjJPUznd8NJVdzqUpT1M',
                ],
            ],
            [
                'provider' => 'apple_music',
                'confidence' => 84,
                'values' => [
                    'release_date' => '2001-04-21',
                ],
                'evidence' => [
                    'source' => 'apple_music_search',
                    'apple_track_url' => 'https://music.apple.com/album/the-soultaker/123?i=456',
                    'apple_collection_url' => 'https://music.apple.com/album/the-soultaker/123',
                ],
            ],
            [
                'provider' => 'deezer',
                'confidence' => 84,
                'values' => [
                    'isrc' => 'JPVIC0100010',
                ],
                'evidence' => [
                    'source' => 'deezer_search',
                    'deezer_track_url' => 'https://www.deezer.com/track/98765',
                ],
            ],
        ],
        fn (array $values): array => catalogProviderLinkChanges($currentValues, $values),
    );

    expect($candidate)->not->toBeNull()
        ->and(data_get($candidate, 'evidence.field_options.spotify_uri.0.source_label'))->toBe('Spotify track')
        ->and(data_get($candidate, 'evidence.field_options.spotify_uri.0.source_url'))->toBe('https://open.spotify.com/track/1WUjJPUznd8NJVdzqUpT1M')
        ->and(data_get($candidate, 'evidence.field_options.release_date.0.source_label'))->toBe('Apple Music track')
        ->and(data_get($candidate, 'evidence.field_options.release_date.0.source_url'))->toBe('https://music.apple.com/album/the-soultaker/123?i=456')
        ->and(data_get($candidate, 'evidence.field_options.isrc.0.source_label'))->toBe('Deezer track')
        ->and(data_get($candidate, 'evidence.field_options.isrc.0.source_url'))->toBe('https://www.deezer.com/track/98765');
});

function catalogProviderLinkChanges(array $currentValues, array $proposedValues): array
{
    $changes = [];

    foreach ($proposedValues as $field => $proposed) {
        $current = $currentValues[$field] ?? null;
        if (catalogProviderLinkComparableValue($current) === catalogProviderLinkComparableValue($proposed)) {
            continue;
        }

        $changes[$field] = [
            'current' => $current,
            'proposed' => $proposed,
        ];
    }

    return $changes;
}

function catalogProviderLinkComparableValue(mixed $value): string
{
    if (is_array($value)) {
        return implode('|', array_map(fn (mixed $entry): string => catalogProviderLinkComparableValue($entry), $value));
    }

    return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?? '';
}
