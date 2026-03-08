<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\CivitAiImages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class BackfillCivitAiVideoMimeTypes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $afterId = 0,
        public int $chunk = 500,
        public string $queueName = 'processing',
    ) {
        $this->afterId = max(0, $this->afterId);
        $this->chunk = max(1, $this->chunk);
        $this->queueName = trim($this->queueName) !== '' ? trim($this->queueName) : 'processing';

        $this->onQueue($this->queueName);
    }

    public function handle(): void
    {
        $files = $this->filesForChunk();
        if ($files->isEmpty()) {
            return;
        }

        foreach ($files as $file) {
            if ($file->mime_type === 'video/mp4') {
                continue;
            }

            $file->forceFill([
                'mime_type' => 'video/mp4',
            ])->save();
        }

        if ($files->count() === $this->chunk) {
            static::dispatch((int) $files->last()->id, $this->chunk, $this->queueName)->onQueue($this->queueName);
        }
    }

    private function filesForChunk(): Collection
    {
        return File::query()
            ->where('id', '>', $this->afterId)
            ->where('source', CivitAiImages::SOURCE)
            ->where('ext', 'mp4')
            ->where('mime_type', 'application/mp4')
            ->orderBy('id')
            ->limit($this->chunk)
            ->get();
    }
}
