<?php

namespace App\Jobs;

use App\Enums\ActionType;
use App\Enums\BlacklistPreviewedCountMode;
use App\Models\Container;
use App\Services\ContainerBlacklistService;
use App\Services\MetricsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateContainerAutoBlacklist implements ShouldQueue
{
    use Queueable;

    private const int KEEP_BLACKLISTED_THRESHOLD = 30;

    private const int FEED_REMOVED_BLACKLISTED_THRESHOLD = 100;

    private const int POSITIVE_REACTION_LIMIT = 10;

    /**
     * @var list<string>
     */
    private const array POSITIVE_REACTION_TYPES = ['love', 'like', 'funny'];

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $containerId, public ?int $userId = null)
    {
        $this->onQueue('default');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(?ContainerBlacklistService $containerBlacklistService = null, ?MetricsService $metricsService = null): void
    {
        $containerBlacklistService ??= app(ContainerBlacklistService::class);
        $metricsService ??= app(MetricsService::class);

        $container = Container::query()->find($this->containerId);

        if (! $container instanceof Container) {
            return;
        }

        if ($container->blacklisted_at !== null) {
            if (
                is_int($this->userId)
                && $this->positiveFileCount($container, $this->userId) >= self::POSITIVE_REACTION_LIMIT
            ) {
                $containerBlacklistService->clear($container);
            }

            return;
        }

        if ($this->positiveFileCount($container) >= self::POSITIVE_REACTION_LIMIT) {
            return;
        }

        $mode = $this->blacklistPreviewedCountMode($this->blacklistedFileCount($container));
        if ($mode === null) {
            return;
        }

        $updated = Container::query()
            ->whereKey($container->id)
            ->whereNull('blacklisted_at')
            ->update([
                'action_type' => ActionType::BLACKLIST,
                'blacklist_previewed_count_mode' => $mode,
                'blacklisted_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return;
        }

        $metricsService->incrementMetric(MetricsService::KEY_CONTAINERS_BLACKLISTED, 1);

        $container->refresh();
        $containerBlacklistService->apply($container, $this->userId);
    }

    private function blacklistedFileCount(Container $container): int
    {
        return (int) $container->files()
            ->whereNotNull('files.blacklisted_at')
            ->count();
    }

    private function positiveFileCount(Container $container, ?int $userId = null): int
    {
        return (int) $container->files()
            ->whereHas('reactions', function (Builder $query) use ($userId): Builder {
                $query->whereIn('type', self::POSITIVE_REACTION_TYPES);

                if (is_int($userId)) {
                    $query->where('user_id', $userId);
                }

                return $query;
            })
            ->count();
    }

    private function blacklistPreviewedCountMode(int $blacklistedCount): ?string
    {
        if ($blacklistedCount >= self::FEED_REMOVED_BLACKLISTED_THRESHOLD) {
            return BlacklistPreviewedCountMode::FEED_REMOVED;
        }

        if ($blacklistedCount > self::KEEP_BLACKLISTED_THRESHOLD) {
            return BlacklistPreviewedCountMode::PRESERVE;
        }

        return null;
    }
}
