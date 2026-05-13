<?php

use App\Services\LibraryScans\BrowserVideoSupport;

it('treats silent H264 MP4 videos as browser-supported', function () {
    $policy = new BrowserVideoSupport;

    $probe = [
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 3840, 'height' => 2160],
        ],
    ];

    expect($policy->isBrowserSupported('video/mp4', $probe))->toBeTrue()
        ->and($policy->shouldCreateStreamableVideo('video/mp4', $probe))->toBeFalse();
});

it('treats silent VP9 WebM videos as browser-supported', function () {
    $policy = new BrowserVideoSupport;

    $probe = [
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'vp9'],
        ],
    ];

    expect($policy->isBrowserSupported('video/webm', $probe))->toBeTrue()
        ->and($policy->shouldCreateStreamableVideo('video/webm', $probe))->toBeFalse();
});

it('requires conversion for unsupported audio codecs', function () {
    $policy = new BrowserVideoSupport;

    $probe = [
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264'],
            ['codec_type' => 'audio', 'codec_name' => 'flac'],
        ],
    ];

    expect($policy->isBrowserSupported('video/mp4', $probe))->toBeFalse()
        ->and($policy->shouldCreateStreamableVideo('video/mp4', $probe))->toBeTrue();
});

it('requires conversion for unsupported containers even with browser-supported codecs', function () {
    $policy = new BrowserVideoSupport;

    $probe = [
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264'],
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ],
    ];

    expect($policy->isBrowserSupported('video/x-matroska', $probe))->toBeFalse()
        ->and($policy->shouldCreateStreamableVideo('video/x-matroska', $probe))->toBeTrue();
});

it('requires conversion for video mime types when support cannot be proven by probe data', function () {
    $policy = new BrowserVideoSupport;

    expect($policy->isBrowserSupported('video/mp4', []))->toBeFalse()
        ->and($policy->shouldCreateStreamableVideo('video/mp4', []))->toBeTrue()
        ->and($policy->shouldCreateStreamableVideo('image/jpeg', []))->toBeFalse();
});
