<?php

use App\Services\Audio\MusicBrainzReleaseMetadata;

it('ignores null MusicBrainz catalog numbers while reading release values', function () {
    $values = app(MusicBrainzReleaseMetadata::class)->values([
        'id' => 'release-1',
        'title' => 'Anjunabeats Volume One',
        'label-info' => [
            [
                'catalog-number' => null,
                'label' => ['name' => 'Anjunabeats'],
            ],
            [
                'catalog-number' => '[none]',
                'label' => ['name' => 'Anjunabeats'],
            ],
            [
                'catalog-number' => ' ANJCD001 ',
                'label' => ['name' => 'Anjunabeats'],
            ],
        ],
    ]);

    expect($values)
        ->toHaveKey('catalog_number', 'ANJCD001')
        ->and($values)
        ->toHaveKey('release_label', 'Anjunabeats');
});
