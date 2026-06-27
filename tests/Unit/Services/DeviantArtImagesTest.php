<?php

use App\Services\DeviantArtImages;

it('maps image deviations to atlas files and user containers', function () {
    $service = (new DeviantArtImages)->setParams(['tag' => 'nature']);

    $result = $service->transform([
        'has_more' => true,
        'next_cursor' => 'cursor-2',
        'estimated_total' => 123,
        'results' => [[
            'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
            'url' => 'https://www.deviantart.com/artist/art/Astana-Hotel-487117484',
            'title' => 'Astana Hotel',
            'is_downloadable' => true,
            'is_mature' => false,
            'author' => [
                'username' => 'artist',
            ],
            'stats' => [
                'comments' => 3,
                'favourites' => 9,
            ],
            'published_time' => 1412745603,
            'preview' => [
                'src' => 'https://th.example.test/preview.jpg',
                'height' => 575,
                'width' => 1024,
            ],
            'content' => [
                'src' => 'https://fc.example.test/content.jpg',
                'filesize' => 204800,
                'height' => 1200,
                'width' => 1600,
            ],
            'thumbs' => [[
                'src' => 'https://th.example.test/thumb.jpg',
                'height' => 150,
                'width' => 150,
            ]],
        ]],
    ]);

    expect($result['filter']['next'])->toBe('cursor-2')
        ->and($result['meta']['total'])->toBe(123);

    $file = $result['files'][0]['file'];
    expect($file['source'])->toBe('deviantart.com')
        ->and($file['source_id'])->toBe('52BAFA97-9DB9-0E5F-FF2D-C39083F89817')
        ->and($file['url'])->toBe('https://fc.example.test/content.jpg')
        ->and($file['referrer_url'])->toBe('https://www.deviantart.com/artist/art/Astana-Hotel-487117484')
        ->and($file['preview_url'])->toBe('https://th.example.test/preview.jpg')
        ->and($file['title'])->toBe('Astana Hotel')
        ->and($file['ext'])->toBe('jpg')
        ->and($file['mime_type'])->toBe('image/jpeg')
        ->and($file['size'])->toBe(204800);

    $payload = json_decode($result['files'][0]['metadata']['payload'], true);
    expect($payload['width'])->toBe(1600)
        ->and($payload['height'])->toBe(1200)
        ->and($payload['download_mode'])->toBe('content')
        ->and($payload['author']['username'])->toBe('artist');

    $listingMetadata = json_decode($file['listing_metadata'], true);
    expect($listingMetadata['user_container_source'])->toBe('deviantart.com')
        ->and($listingMetadata['user_container_source_id'])->toBe('artist')
        ->and($listingMetadata['user_container_referrer_url'])->toBe('https://www.deviantart.com/artist/gallery')
        ->and($listingMetadata)->not->toHaveKey('post_container_source_id')
        ->and($listingMetadata)->not->toHaveKey('post_container_referrer_url');

    expect($service->containers($listingMetadata))->toMatchArray([[
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'artist',
        'referrer' => 'https://www.deviantart.com/artist/gallery',
    ]]);
});

it('infers DeviantArt user and post containers from file-index referrers', function () {
    $service = new DeviantArtImages;

    $metadata = $service->containerMetadataFromCandidateUrls([
        'https://www.deviantart.com/2821986s/art/Tohsaka-Rin-Fate-1348544787?file=4',
    ]);

    expect($metadata)->toMatchArray([
        'post_container_referrer_url' => 'https://www.deviantart.com/2821986s/art/Tohsaka-Rin-Fate-1348544787',
        'post_container_source' => 'deviantart.com',
        'post_container_source_id' => 'Tohsaka-Rin-Fate-1348544787',
        'user_container_referrer_url' => 'https://www.deviantart.com/2821986s/gallery',
        'user_container_source' => 'deviantart.com',
        'user_container_source_id' => '2821986s',
    ]);

    expect($service->containers($metadata))->toMatchArray([
        [
            'type' => 'User',
            'source' => 'deviantart.com',
            'source_id' => '2821986s',
            'referrer' => 'https://www.deviantart.com/2821986s/gallery',
        ],
        [
            'type' => 'Post',
            'source' => 'deviantart.com',
            'source_id' => 'Tohsaka-Rin-Fate-1348544787',
            'referrer' => 'https://www.deviantart.com/2821986s/art/Tohsaka-Rin-Fate-1348544787',
        ],
    ]);
});

it('infers DeviantArt user containers without post containers for single-item referrers', function () {
    $service = new DeviantArtImages;

    $metadata = $service->containerMetadataFromCandidateUrls([
        'https://www.deviantart.com/2821986s/art/Tohsaka-Rin-Fate-1348544787',
    ]);

    expect($metadata)->toMatchArray([
        'user_container_referrer_url' => 'https://www.deviantart.com/2821986s/gallery',
        'user_container_source' => 'deviantart.com',
        'user_container_source_id' => '2821986s',
    ])
        ->and($metadata)->not->toHaveKey('post_container_source_id')
        ->and($metadata)->not->toHaveKey('post_container_referrer_url');

    expect($service->containers($metadata))->toMatchArray([[
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => '2821986s',
        'referrer' => 'https://www.deviantart.com/2821986s/gallery',
    ]]);
});

it('ignores DeviantArt URLs that are not exact post paths for container inference', function () {
    $service = new DeviantArtImages;

    $metadata = $service->containerMetadataFromCandidateUrls([
        'https://www.deviantart.com/2821986s/art/Tohsaka-Rin-Fate-1348544787/extra?file=4',
    ]);

    expect($metadata)->toBe([]);
});

it('derives DeviantArt user containers from deviation URLs when author metadata is missing', function () {
    $service = new DeviantArtImages;

    $result = $service->transform([
        'results' => [[
            'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
            'url' => 'https://www.deviantart.com/deviantartbender/art/Visionaries-Leoric-Star-Comics-1307302462',
            'content' => [
                'src' => 'https://fc.example.test/content.jpg',
                'height' => 900,
                'width' => 1200,
            ],
        ]],
    ]);

    $file = $result['files'][0]['file'];
    $listingMetadata = json_decode($file['listing_metadata'], true);

    expect($listingMetadata['user_container_source'])->toBe('deviantart.com')
        ->and($listingMetadata['user_container_source_id'])->toBe('deviantartbender')
        ->and($listingMetadata['user_container_referrer_url'])->toBe('https://www.deviantart.com/deviantartbender/gallery')
        ->and($service->containers($listingMetadata))->toMatchArray([[
            'type' => 'User',
            'source' => 'deviantart.com',
            'source_id' => 'deviantartbender',
            'referrer' => 'https://www.deviantart.com/deviantartbender/gallery',
        ]]);
});

it('normalizes mixed-case DeviantArt usernames for container keys', function () {
    $service = new DeviantArtImages;

    $result = $service->transform([
        'results' => [[
            'deviationid' => '67B1C91B-808F-364E-4074-3DDECC03423A',
            'url' => 'https://www.deviantart.com/animeaivideos/art/Anime-Image-1329880490',
            'author' => [
                'username' => 'AnimeAIVideos',
            ],
            'content' => [
                'src' => 'https://fc.example.test/content.jpg',
                'height' => 900,
                'width' => 1200,
            ],
        ]],
    ]);

    $file = $result['files'][0]['file'];
    $listingMetadata = json_decode($file['listing_metadata'], true);

    expect($listingMetadata['author']['username'])->toBe('AnimeAIVideos')
        ->and($listingMetadata['user_container_source_id'])->toBe('animeaivideos')
        ->and($listingMetadata['user_container_referrer_url'])->toBe('https://www.deviantart.com/animeaivideos/gallery')
        ->and($service->containers($listingMetadata))->toMatchArray([[
            'type' => 'User',
            'source' => 'deviantart.com',
            'source_id' => 'animeaivideos',
            'referrer' => 'https://www.deviantart.com/animeaivideos/gallery',
        ]]);
});

it('prefers the largest video when a deviation has videos', function () {
    $service = new DeviantArtImages;

    $result = $service->transform([
        'results' => [[
            'deviationid' => 'C0801604-7894-532E-BC8F-C4EE47273E6D',
            'url' => 'https://www.deviantart.com/artist/art/Video-1',
            'preview' => [
                'src' => 'https://th.example.test/video-preview.jpg',
                'height' => 720,
                'width' => 1280,
            ],
            'content' => [
                'src' => 'https://fc.example.test/video-poster.jpg',
                'height' => 720,
                'width' => 1280,
            ],
            'videos' => [
                [
                    'src' => 'https://videos.example.test/low.mp4',
                    'filesize' => 1024,
                ],
                [
                    'src' => 'https://videos.example.test/high.mp4',
                    'filesize' => 4096,
                ],
            ],
        ]],
    ]);

    $file = $result['files'][0]['file'];
    $payload = json_decode($result['files'][0]['metadata']['payload'], true);

    expect($file['url'])->toBe('https://videos.example.test/high.mp4')
        ->and($file['preview_url'])->toBe('https://th.example.test/video-preview.jpg')
        ->and($file['ext'])->toBe('mp4')
        ->and($file['mime_type'])->toBe('video/mp4')
        ->and($file['size'])->toBe(4096)
        ->and($payload['download_mode'])->toBe('video');
});

it('filters locked DeviantArt results before mapping files', function () {
    $service = new DeviantArtImages;

    $result = $service->transform([
        'results' => [
            deviantArtImagesTestRow('locked-tier', [
                'tier_access' => 'locked',
                'primary_tier' => [
                    'tier' => [
                        'name' => 'Supporter',
                    ],
                ],
            ]),
            deviantArtImagesTestRow('locked-subscription-folder', [
                'premium_folder_data' => [
                    'type' => 'subscribers',
                    'has_access' => false,
                ],
            ]),
            deviantArtImagesTestRow('unwatched-user', [
                'premium_folder_data' => [
                    'type' => 'watchers',
                    'has_access' => false,
                ],
            ]),
            deviantArtImagesTestRow('subscribed-tier', [
                'tier_access' => 'unlocked',
                'primary_tier' => [
                    'tier' => [
                        'name' => 'Supporter',
                    ],
                ],
            ]),
            deviantArtImagesTestRow('regular'),
        ],
    ]);

    expect(array_map(
        fn (array $item): string => $item['file']['source_id'],
        $result['files'],
    ))->toBe([
        'unwatched-user',
        'subscribed-tier',
        'regular',
    ]);
});

it('formats DeviantArt query params for tags, offsets, and gallery limits', function () {
    $defaultService = new DeviantArtImages;
    expect($defaultService->formatParams())->toMatchArray([
        'limit' => 20,
        'offset' => 0,
    ]);
    expect($defaultService->formatParams())->not->toHaveKey('q');

    $service = (new DeviantArtImages)->setParams([
        'tag' => 'landscape',
        'page' => 'cursor-token',
        'limit' => 80,
        'nsfw' => '1',
    ]);

    expect($service->formatParams())->toMatchArray([
        'limit' => 50,
        'cursor' => 'cursor-token',
        'mature_content' => true,
        'tag' => 'landscape',
    ]);

    $service->setParams([
        'username' => 'artist',
        'page' => '48',
        'limit' => 80,
        'nsfw' => false,
    ]);

    expect($service->formatParams())->toMatchArray([
        'limit' => 24,
        'offset' => 48,
        'mature_content' => false,
        'username' => 'artist',
    ]);
});

function deviantArtImagesTestRow(string $id, array $attributes = []): array
{
    return array_replace_recursive([
        'deviationid' => $id,
        'url' => "https://www.deviantart.com/artist/art/{$id}",
        'author' => [
            'username' => 'artist',
        ],
        'content' => [
            'src' => "https://fc.example.test/{$id}.jpg",
            'height' => 900,
            'width' => 1200,
        ],
    ], $attributes);
}
