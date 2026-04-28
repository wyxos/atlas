<?php

namespace App\Services\Downloads;

use App\Support\VideoPreviewSamplingPlan;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FileVideoPreviewGenerator
{
    /**
     * @return array{0: string|null, 1: string|null}
     */
    public function generate(Filesystem $disk, string $absolutePath, string $finalPath): array
    {
        $ffmpegPath = $this->resolveFfmpegPath((string) config('downloads.ffmpeg_path'));
        if (! $ffmpegPath) {
            return [null, null];
        }

        $previewWidth = (int) config('downloads.video_preview_width', 450);
        $previewStartSecond = (float) config('downloads.video_preview_start_second', 1);
        $previewTakeSeconds = (float) config('downloads.video_preview_take_seconds', 5);
        $previewSkipSeconds = (float) config('downloads.video_preview_skip_seconds', 10);
        $previewTakeCount = (int) config('downloads.video_preview_take_count', 5);
        $posterSecond = (float) config('downloads.video_poster_second', $previewStartSecond);
        $timeout = (int) config('downloads.ffmpeg_timeout_seconds', 120);
        $previewFilter = VideoPreviewSamplingPlan::selectFilter(
            $previewStartSecond,
            $previewTakeSeconds,
            $previewSkipSeconds,
            $previewTakeCount,
        ).",scale={$previewWidth}:-2";

        $directory = pathinfo($finalPath, PATHINFO_DIRNAME);
        $filename = pathinfo($finalPath, PATHINFO_FILENAME);
        $previewPath = $directory.'/'.$filename.'.preview.mp4';
        $posterPath = $directory.'/'.$filename.'.poster.jpg';

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
