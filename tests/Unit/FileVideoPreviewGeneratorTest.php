<?php

use App\Services\Downloads\FileVideoPreviewGenerator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

function fakeSuccessfulFfmpegPath(): string
{
    $path = storage_path('framework/testing/fake-video-ffmpeg'.(PHP_OS_FAMILY === 'Windows' ? '.bat' : ''));

    if (PHP_OS_FAMILY === 'Windows') {
        file_put_contents($path, implode(PHP_EOL, [
            '@echo off',
            'set "out=%~1"',
            ':loop',
            'if "%~2"=="" goto done',
            'shift',
            'set "out=%~1"',
            'goto loop',
            ':done',
            'echo generated>"%out%"',
            'exit /b 0',
        ]));

        return $path;
    }

    file_put_contents($path, implode(PHP_EOL, [
        '#!/bin/sh',
        'for last do :; done',
        'mkdir -p "$(dirname "$last")"',
        'printf generated > "$last"',
    ]));
    chmod($path, 0755);

    return $path;
}

it('stores video preview assets inside the source file preview directory', function () {
    Storage::fake('atlas');
    config()->set('downloads.ffmpeg_path', fakeSuccessfulFfmpegPath());

    $disk = Storage::disk('atlas');
    $disk->put('downloads/12/34/video.mp4', 'video');

    [$previewPath, $posterPath] = app(FileVideoPreviewGenerator::class)->generate(
        $disk,
        $disk->path('downloads/12/34/video.mp4'),
        'downloads/12/34/video.mp4',
    );

    expect($previewPath)->toBe('downloads/12/34/preview/video.mp4')
        ->and($posterPath)->toBe('downloads/12/34/preview/video.jpg');

    $disk->assertExists($previewPath);
    $disk->assertExists($posterPath);
});
