<?php

use App\Support\DeviantArtPageUrl;

it('normalizes deviantart post urls to the canonical host and path', function () {
    expect(DeviantArtPageUrl::normalize('http://deviantart.com/Artist/art/Deviation-123'))
        ->toBe('https://www.deviantart.com/Artist/art/Deviation-123');
});

it('removes only a first deviantart file query parameter', function () {
    expect(DeviantArtPageUrl::normalize('https://www.deviantart.com/artist/art/deviation-123?file=1'))
        ->toBe('https://www.deviantart.com/artist/art/deviation-123');

    expect(DeviantArtPageUrl::normalize('https://www.deviantart.com/artist/art/deviation-123?foo=bar&file=1&baz=qux'))
        ->toBe('https://www.deviantart.com/artist/art/deviation-123');
});

it('keeps non-first deviantart file query parameters', function () {
    expect(DeviantArtPageUrl::normalize('https://www.deviantart.com/artist/art/deviation-123?file=2'))
        ->toBe('https://www.deviantart.com/artist/art/deviation-123?file=2');

    expect(DeviantArtPageUrl::normalize('https://www.deviantart.com/artist/art/deviation-123?utm_source=feed&file=3'))
        ->toBe('https://www.deviantart.com/artist/art/deviation-123?file=3');
});

it('drops non-file deviantart query parameters', function () {
    expect(DeviantArtPageUrl::normalize('https://www.deviantart.com/artist/art/deviation-123?utm_source=feed#comments'))
        ->toBe('https://www.deviantart.com/artist/art/deviation-123');
});
