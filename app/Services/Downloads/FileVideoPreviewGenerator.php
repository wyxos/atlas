<?php

namespace App\Services\Downloads;

use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasStorage;
use App\Support\VideoPreviewSamplingPlan;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FileVideoPreviewGenerator
{
    public function __construct(
        private readonly AtlasStorage $appStorage,
        private readonly MediaProbeService $probe,
    ) {}

    /**
     * @return array{0: string|null, 1: string|null}
     */
    public function generate(Filesystem $disk, string $absolutePath, string $finalPath): array
    {
        $ffmpegPath = $this->resolveFfmpegPath((string) config('downloads.ffmpeg_path'));
        if (! $ffmpegPath) {
            return [null, null];
        }

        $options = $this->previewOptions();
        $previewWidth = $options['width'];
        $previewShortMaxSeconds = $options['short_max_seconds'];
        $previewClipSeconds = $options['clip_seconds'];
        $previewClipCount = $options['clip_count'];
        $posterSecond = $options['poster_second'];
        $timeout = (int) config('downloads.ffmpeg_timeout_seconds', 120);
        $durationSeconds = $this->durationSeconds($absolutePath);
        $selectFilter = $durationSeconds === null
            ? null
            : VideoPreviewSamplingPlan::selectFilterForDuration(
                $durationSeconds,
                $previewShortMaxSeconds,
                $previewClipSeconds,
                $previewClipCount,
            );
        $previewFilter = implode(',', array_filter([
            $selectFilter,
            "scale={$previewWidth}:-2",
        ]));

        ['preview_path' => $previewPath, 'poster_path' => $posterPath] = $this->outputPaths($finalPath);
        $directory = dirname($previewPath);

        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        $previewProcess = new Process([
            $ffmpegPath,
            '-y',
            '-i',
            $absolutePath,
            '-vf',
            $previewFilter,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '28',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
            '-an',
            $disk->path($previewPath),
        ]);
        $previewProcess->setTimeout($timeout);

        try {
            $previewProcess->run();
        } catch (\Throwable) {
            // Ignore preview generation failures.
        }

        if (! $previewProcess->isSuccessful() || ! $disk->exists($previewPath)) {
            $previewPath = null;
        }

        $posterProcess = new Process([
            $ffmpegPath,
            '-y',
            '-ss',
            (string) $posterSecond,
            '-i',
            $absolutePath,
            '-frames:v',
            '1',
            '-vf',
            "scale={$previewWidth}:-2",
            $disk->path($posterPath),
        ]);
        $posterProcess->setTimeout($timeout);

        try {
            $posterProcess->run();
        } catch (\Throwable) {
            // Ignore poster generation failures.
        }

        if (! $posterProcess->isSuccessful() || ! $disk->exists($posterPath)) {
            $posterPath = null;
        }

        return [$previewPath, $posterPath];
    }

    /**
     * @return array{preview_path: string, poster_path: string}
     */
    public function outputPaths(string $finalPath): array
    {
        $filename = pathinfo(basename($finalPath), PATHINFO_FILENAME);

        return [
            'preview_path' => $this->appStorage->derivedPath($finalPath, 'preview', $filename.'.mp4'),
            'poster_path' => $this->appStorage->derivedPath($finalPath, 'preview', $filename.'.jpg'),
        ];
    }

    /**
     * @return array{width: int, short_max_seconds: float, clip_seconds: float, clip_count: int, poster_second: float}
     */
    public function previewOptions(): array
    {
        return [
            'width' => (int) config('downloads.video_preview_width', 450),
            'short_max_seconds' => (float) config('downloads.video_preview_short_max_seconds', 60),
            'clip_seconds' => (float) config('downloads.video_preview_clip_seconds', 5),
            'clip_count' => (int) config('downloads.video_preview_clip_count', 10),
            'poster_second' => (float) config('downloads.video_poster_second', 1),
        ];
    }

    private function durationSeconds(string $absolutePath): ?float
    {
        $probe = $this->probe->probe($absolutePath);
        $formatDuration = $probe['format']['duration'] ?? null;
        if (is_numeric($formatDuration)) {
            return (float) $formatDuration;
        }

        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        foreach ($streams as $stream) {
            $streamDuration = is_array($stream) ? ($stream['duration'] ?? null) : null;
            if (is_numeric($streamDuration)) {
                return (float) $streamDuration;
            }
        }

        return null;
    }

    private function resolveFfmpegPath(string $ffmpegPath): ?string
    {
        if ($ffmpegPath === '') {
            return null;
        }

        if (is_dir($ffmpegPath)) {
            $binary = DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg');
            $ffmpegPath = rtrim($ffmpegPath, '\\/').$binary;
        }

        if (is_file($ffmpegPath)) {
            return $ffmpegPath;
        }

        return $ffmpegPath !== '' ? $ffmpegPath : null;
    }
}
