<?php

use App\Support\FileTypeDetector;

it('detects extension and mime from url', function () {
    $url = 'https://example.com/path/image.TEST.JpG?query=1';
    expect(FileTypeDetector::extensionFromUrl($url))->toBe('jpg');
    $mime = FileTypeDetector::mimeFromUrl($url);
    expect($mime)->toBe('image/jpeg');
});

it('returns null when no extension', function () {
    $url = 'https://example.com/path/noextension';
    expect(FileTypeDetector::extensionFromUrl($url))->toBeNull();
    expect(FileTypeDetector::mimeFromUrl($url))->toBeNull();
});

it('falls back to octet-stream for unknown extension', function () {
    $url = 'https://example.com/file.customunknownext';
    // Force extension recognized but mime not mapped in Symfony may still produce something; simulate by using an unlikely ext.
    $ext = FileTypeDetector::extensionFromUrl($url);
    expect($ext)->toBe('customunknownext');
    $mime = FileTypeDetector::mimeFromUrl($url);
    // If Symfony does not know the mime, our code returns application/octet-stream
    expect($mime)->toBe('application/octet-stream');
});
