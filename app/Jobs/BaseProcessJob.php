<?php

namespace App\Jobs;

use App\Events\StorageProcessingProgress;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

abstract class BaseProcessJob implements ShouldQueue
{
    use Batchable, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $disk,
        public \App\Models\File $file,
    ) {}

    abstract protected function process(): void;

    public function handle(): void
    {
        $this->ensureProgressCounters();

        if ($this->isCancelled()) {
            return;
        }

        $this->process();

        $this->markCompleted();
    }

    public function failed(Throwable $e): void
    {
        $this->ensureProgressCounters();
        Cache::increment($this->failedKey());
        $total = (int) Cache::get($this->totalKey(), 0);
        $done = (int) Cache::get($this->doneKey(), 0);
        $failed = (int) Cache::get($this->failedKey(), 0);
        event(new StorageProcessingProgress($this->userId, $total, $done, $failed));
    }

    protected function ensureProgressCounters(): void
    {
        $ttl = now()->addMinutes(60);
        Cache::add($this->totalKey(), 0, $ttl);
        Cache::add($this->doneKey(), 0, $ttl);
        Cache::add($this->failedKey(), 0, $ttl);
    }

    protected function isCancelled(): bool
    {
        return (bool) Cache::get($this->cancelKey(), false);
    }

    protected function markCompleted(): void
    {
        $done = (int) Cache::increment($this->doneKey());
        $total = (int) Cache::get($this->totalKey(), 0);
        $failed = (int) Cache::get($this->failedKey(), 0);
        event(new StorageProcessingProgress($this->userId, $total, $done, $failed));
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
}
