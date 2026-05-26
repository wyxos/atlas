<?php

namespace App\Services\Audio;

use App\Models\AudioTrackStat;
use Illuminate\Support\Collection;

class AudioPlaybackStatsService
{
    public function record(int $userId, int $fileId, string $event): AudioTrackStat
    {
        $stat = AudioTrackStat::query()->firstOrCreate(
            [
                'file_id' => $fileId,
                'user_id' => $userId,
            ],
            [
                'play_count' => 0,
                'skip_count' => 0,
            ],
        );

        $now = now();
        $stat->increment(
            $event === 'play' ? 'play_count' : 'skip_count',
            1,
            $event === 'play' ? ['last_played_at' => $now] : ['last_skipped_at' => $now],
        );

        return $stat->refresh();
    }

    /**
     * @param  list<int>  $fileIds
     * @return Collection<int, AudioTrackStat>
     */
    public function forFiles(int $userId, array $fileIds): Collection
    {
        $fileIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $fileId): int => (int) $fileId, $fileIds),
            static fn (int $fileId): bool => $fileId > 0,
        )));

        if ($fileIds === []) {
            return collect();
        }

        return AudioTrackStat::query()
            ->where('user_id', $userId)
            ->whereIn('file_id', $fileIds)
            ->get()
            ->keyBy('file_id');
    }
}
