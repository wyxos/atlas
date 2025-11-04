<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RenameDownloadedFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $fileId) {}

    public function handle(): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file || ! $file->path || ! $file->downloaded) {
            return;
        }

        $currentFilename = (string) $file->filename;
        $isAlreadyRandom = $currentFilename !== ''
            && preg_match('/^[A-Za-z0-9]{40}(\.[A-Za-z0-9]+)?$/', $currentFilename) === 1;

        if ($isAlreadyRandom) {
            return;
        }

        $disksWithFile = collect(['atlas_app', 'atlas'])->filter(function (string $disk) use ($file) {
            return Storage::disk($disk)->exists($file->path);
        })->values();

        if ($disksWithFile->isEmpty()) {
            Log::warning('RenameDownloadedFile: file missing on all disks', [
                'file_id' => $file->id,
                'path' => $file->path,
            ]);

            return;
        }

        $directory = strpos($file->path, '/') !== false
            ? Str::beforeLast($file->path, '/')
            : null;

        $extension = pathinfo($file->path, PATHINFO_EXTENSION);

        $newFilename = $this->generateUniqueFilename($extension, $directory);
        $newPath = $directory ? $directory.'/'.$newFilename : $newFilename;

        foreach ($disksWithFile as $disk) {
            $storage = Storage::disk($disk);

            if ($directory && ! $storage->exists($directory)) {
                $storage->makeDirectory($directory);
            }

            if (! $storage->move($file->path, $newPath)) {
                throw new \RuntimeException("Failed to move file on disk {$disk} from {$file->path} to {$newPath}");
            }
        }

        $file->update([
            'filename' => $newFilename,
            'path' => $newPath,
        ]);

        $file->refresh();

        try {
            $file->searchable();
        } catch (\Throwable $e) {
            Log::warning('RenameDownloadedFile: searchable failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function generateUniqueFilename(?string $extension, ?string $directory): string
    {
        $extension = $extension ? strtolower($extension) : null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $random = Str::random(40);
            $candidate = $extension ? $random.'.'.$extension : $random;
            $path = $directory ? $directory.'/'.$candidate : $candidate;

            $existsOnAnyDisk = collect(['atlas_app', 'atlas'])->contains(function (string $disk) use ($path) {
                return Storage::disk($disk)->exists($path);
            });

            if (! $existsOnAnyDisk) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique filename after multiple attempts');
    }
}
