<?php

use App\Services\Audio\AudioMetadataDiscogsSearchQueryExpander;

test('it expands conservative ai discogs searches with title and artist variants', function () {
    $queries = app(AudioMetadataDiscogsSearchQueryExpander::class)->expand([[
        'release_title' => 'GTO TV Animation Original Soundtrack 2',
        'artist' => 'Yusuke Honma',
        'reason' => 'Matches current album and artist.',
    ]]);

    expect($queries)->toContain([
        'release_title' => 'GTO TV Animation Original Soundtrack 2',
        'artist' => 'Yusuke Honma',
        'reason' => 'Matches current album and artist.',
    ])->and($queries)->toContain([
        'release_title' => 'GTO Original Soundtrack 2',
        'artist' => 'Yusuke Homma',
        'reason' => 'Expanded from AI Discogs search query.',
    ])->and($queries)->toHaveCount(4);
});
