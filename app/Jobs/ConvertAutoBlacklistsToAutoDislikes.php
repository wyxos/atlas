<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\User;
use App\Services\FileAutoDislikeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ConvertAutoBlacklistsToAutoDislikes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public int $afterId = 0,
        public int $chunk = 1000,
        public string $queueName = 'processing',
        public bool $dryRun = false,
    ) {
        $this->userId = max(1, $this->userId);
        $this->afterId = max(0, $this->afterId);
        $this->chunk = max(1, $this->chunk);
        $this->queueName = trim($this->queueName) !== '' ? trim($this->queueName) : 'processing';

        $this->onQueue($this->queueName);
    }

    public function handle(): void
    {
        if (! Schema::hasColumn('files', 'blacklist_reason')) {
            return;
        }

        if (! User::query()->whereKey($this->userId)->exists()) {
            return;
        }

        $files = $this->filesForChunk();
        if ($files->isEmpty()) {
            return;
        }

        if (! $this->dryRun) {
            app(FileAutoDislikeService::class)->apply($files, $this->userId, clearBlacklist: true);
        }

        $this->dispatchNextChunk($files);
    }

    private function filesForChunk(): Collection
    {
        return File::query()
            ->select([
                'id',
                'path',
                'preview_path',
                'poster_path',
                'downloaded',
                'downloaded_at',
                'download_progress',
                'blacklisted_at',
                'auto_disliked',
            ])
            ->where('id', '>', $this->afterId)
            ->whereNotNull('blacklisted_at')
            ->where(function ($query) {
                $query->whereNull('blacklist_reason')
                    ->orWhere('blacklist_reason', '');
            })
            ->orderBy('id')
            ->limit($this->chunk)
            ->get();
    }

    private function dispatchNextChunk(Collection $files): void
    {
        if ($files->count() !== $this->chunk) {
            return;
        }

        $lastFile = $files->last();
        if (! $lastFile instanceof File) {
            return;
        }

        static::dispatch(
            $this->userId,
            (int) $lastFile->id,
            $this->chunk,
            $this->queueName,
            $this->dryRun,
        )->onQueue($this->queueName);
    }
}
