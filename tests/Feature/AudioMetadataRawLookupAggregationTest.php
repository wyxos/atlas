<?php

use App\Models\File;
use App\Services\Audio\AudioMetadataCandidateAggregator;
use App\Services\Audio\AudioMetadataCandidateFieldReviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('metadata proposal aggregation returns raw lookup options without final field review recommendations', function () {
    $file = File::factory()->create([
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
        'title' => 'Original Title',
    ]);

    $currentValues = [
        'title' => 'Original Title',
        'artists' => ['Existing Artist'],
        'album' => 'Existing Album',
        'cover_url' => null,
        'track_number' => null,
        'disc_number' => null,
        'discogs_release_id' => null,
    ];

    $candidate = [
        'provider' => 'discogs_release',
        'confidence' => 92,
        'values' => [
            'album' => 'Lookup Album',
            'cover_url' => 'https://cover.test/lookup.jpg',
            'track_number' => '4',
            'discogs_release_id' => '123456',
        ],
        'evidence' => [
            'source' => 'discogs_release_search',
            'discogs_release_id' => '123456',
        ],
    ];

    $fieldReviewCalls = 0;
    $this->mock(AudioMetadataCandidateFieldReviewer::class, function (MockInterface $mock) use (&$fieldReviewCalls): void {
        $mock->shouldReceive('review')
            ->andReturnUsing(function (File $file, array $currentValues, array $candidate, array $changes) use (&$fieldReviewCalls): array {
                $fieldReviewCalls++;
                $candidate['evidence']['field_review'] = [
                    'verdict' => 'accept',
                    'confidence' => 0.95,
                    'reason' => 'AI accepted every changed field.',
                    'model' => 'qwen-test',
                    'safe_fields' => array_keys($changes),
                    'field_reviews' => [],
                ];

                return $candidate;
            });
    });

    $proposalCandidate = app(AudioMetadataCandidateAggregator::class)->aggregate(
        $file,
        $currentValues,
        [$candidate],
        fn (array $values): array => rawLookupAggregationChanges($currentValues, $values),
    );

    expect($fieldReviewCalls)->toBe(0)
        ->and($proposalCandidate)->not->toBeNull()
        ->and(data_get($proposalCandidate, 'values'))->toBe([])
        ->and(data_get($proposalCandidate, 'evidence.field_review'))->toBeNull()
        ->and(data_get($proposalCandidate, 'evidence.field_options.album.0.value'))->toBe('Lookup Album')
        ->and(data_get($proposalCandidate, 'evidence.field_options.album.0.recommended'))->toBeFalse()
        ->and(data_get($proposalCandidate, 'evidence.field_options.cover_url.0.recommended'))->toBeFalse()
        ->and(data_get($proposalCandidate, 'evidence.field_options.track_number.0.recommended'))->toBeFalse()
        ->and(data_get($proposalCandidate, 'evidence.provider_candidates.0.recommended_fields'))->toBe([]);
});

/**
 * @param  array<string, mixed>  $currentValues
 * @param  array<string, mixed>  $proposedValues
 * @return array<string, array{current:mixed,proposed:mixed}>
 */
function rawLookupAggregationChanges(array $currentValues, array $proposedValues): array
{
    $changes = [];

    foreach ($proposedValues as $field => $proposed) {
        $current = $currentValues[$field] ?? null;
        if ($current === $proposed) {
            continue;
        }

        $changes[$field] = [
            'current' => $current,
            'proposed' => $proposed,
        ];
    }

    return $changes;
}
