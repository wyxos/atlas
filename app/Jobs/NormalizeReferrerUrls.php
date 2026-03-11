<?php

namespace App\Jobs;

use App\Models\File;
use App\Support\ReferrerUrlCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NormalizeReferrerUrls implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $queryParamsToStrip
     */
    public function __construct(
        public string $domain,
        public array $queryParamsToStrip,
        public int $afterId = 0,
        public int $chunk = 500,
        public string $queueName = 'processing',
    ) {
        $this->domain = ReferrerUrlCleanup::normalizeDomain($this->domain) ?? '';
        $this->queryParamsToStrip = ReferrerUrlCleanup::normalizeQueryParams($this->queryParamsToStrip);
        $this->afterId = max(0, $this->afterId);
        $this->chunk = max(1, $this->chunk);
        $this->queueName = trim($this->queueName) !== '' ? trim($this->queueName) : 'processing';

        $this->onQueue($this->queueName);
    }

    public function handle(): void
    {
        if ($this->domain === '' || $this->queryParamsToStrip === []) {
            return;
        }

        $files = $this->filesForChunk();
        if ($files->isEmpty()) {
            return;
        }

        $updatedAt = now();

        foreach ($files as $file) {
            $normalizedReferrerUrl = ReferrerUrlCleanup::cleanupForDomain(
                $file->referrer_url,
                $this->domain,
                $this->queryParamsToStrip,
            );

            if (! is_string($normalizedReferrerUrl) || $normalizedReferrerUrl === '' || $normalizedReferrerUrl === $file->referrer_url) {
                continue;
            }

            DB::table('files')
                ->where('id', $file->id)
                ->update([
                    'referrer_url' => $normalizedReferrerUrl,
                    'referrer_url_hash' => hash('sha256', $normalizedReferrerUrl),
                    'updated_at' => $updatedAt,
                ]);
        }

        if ($files->count() === $this->chunk) {
            static::dispatch(
                $this->domain,
                $this->queryParamsToStrip,
                (int) $files->last()->id,
                $this->chunk,
                $this->queueName,
            )->onQueue($this->queueName);
        }
    }

    private function filesForChunk(): Collection
    {
        return File::query()
            ->select(['id', 'referrer_url'])
            ->where('id', '>', $this->afterId)
            ->whereNotNull('referrer_url')
            ->where('referrer_url', 'like', '%'.$this->domain.'%')
            ->orderBy('id')
            ->limit($this->chunk)
            ->get();
    }
}
