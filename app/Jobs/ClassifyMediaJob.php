<?php

namespace App\Jobs;

use App\Events\StorageProcessingProgress;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClassifyMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $disk,
        public string $path
    ) {}

    public function handle(): void
    {
        if (Cache::get($this->cancelKey(), false)) {
            return;
        }

        try {
            $mime = (string) (Storage::disk($this->disk)->mimeType($this->path) ?? '');
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if ($mime === '') {
            return;
        }

        $file = File::query()->firstOrNew(['path' => $this->path]);

        $filename = basename($this->path) ?: $this->path;
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: null;

        if (! $file->exists && ! $file->source) {
            $file->source = 'local';
        }

        if (! $file->filename) {
            $file->filename = $filename;
        }

        if ($extension && $file->ext !== $extension) {
            $file->ext = strtolower($extension);
        }

        $file->mime_type = $mime;

        if ($file->isDirty()) {
            $file->save();
        }

        if ($file->exists) {
            $file->refresh();
        }

        $dispatcher = match (true) {
            Str::startsWith($mime, 'image/') => function () use ($file): void {
                ProcessImageJob::dispatch($this->userId, $this->disk, $file)->onQueue('processing');
            },
            Str::startsWith($mime, 'audio/') => function () use ($file): void {
                ProcessAudioJob::dispatch($this->userId, $this->disk, $file)->onQueue('processing');
            },
            Str::startsWith($mime, 'video/') => function () use ($file): void {
                ProcessVideoJob::dispatch($this->userId, $this->disk, $file)->onQueue('processing');
            },
            default => null,
        };

        if ($dispatcher === null) {
            return;
        }

        Cache::increment($this->totalKey());

        $dispatcher();

        $this->broadcastProgress();
    }

    protected function totalKey(): string
    {
        return 'storage_processing:'.$this->userId.':total';
    }

    protected function doneKey(): string
    {
        return 'storage_processing:'.$this->userId.':done';
    }

    protected function failedKey(): string
    {
        return 'storage_processing:'.$this->userId.':failed';
    }

    protected function cancelKey(): string
    {
        return 'storage_scan:'.$this->userId.':cancel';
    }

    protected function broadcastProgress(): void
    {
        $total = (int) Cache::get($this->totalKey(), 0);
        $done = (int) Cache::get($this->doneKey(), 0);
        $failed = (int) Cache::get($this->failedKey(), 0);

        event(new StorageProcessingProgress($this->userId, $total, $done, $failed));
    }
}
