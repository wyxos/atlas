<?php

namespace App\Jobs;

use App\Services\Extension\ExtensionAssetMatchIdentityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillExtensionAssetMatchIdentities implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $rule
     */
    public function __construct(
        public array $rule,
        public int $afterId = 0,
        public int $chunk = 500,
        public string $queueName = 'processing',
    ) {
        $this->afterId = max(0, $this->afterId);
        $this->chunk = max(1, $this->chunk);
        $this->queueName = trim($this->queueName) !== '' ? trim($this->queueName) : 'processing';

        $this->onQueue($this->queueName);
    }

    public function handle(ExtensionAssetMatchIdentityService $identities): void
    {
        $files = $identities->filesForRuleChunk($this->rule, $this->afterId, $this->chunk);
        if ($files->isEmpty()) {
            return;
        }

        foreach ($files as $file) {
            $identities->upsertForFileRule($file, $this->rule);
        }

        if ($files->count() === $this->chunk) {
            static::dispatch(
                $this->rule,
                (int) $files->last()->id,
                $this->chunk,
                $this->queueName,
            )->onQueue($this->queueName);
        }
    }
}
