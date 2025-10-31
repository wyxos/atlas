<?php

namespace App\Services\Reactions;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;

class ReactionBackfiller
{
    /**
     * Backfill reactions from File table legacy columns.
     * - Honors priority: love > like > dislike > funny
     * - Uses provided userId, else first user
     * - Supports chunking, dry-run, and limit for testing
     * Returns stats array: [candidates, processed, created, updated]
     */
    public function runWithOptions(int $chunk = 1000, bool $dryRun = false, ?int $userId = null, ?int $limit = null, ?OutputStyle $output = null): array
    {
        $userId = $userId ?: DB::table('users')->orderBy('id')->value('id');
        $base = DB::table('files')
            ->select('id', 'loved', 'liked', 'disliked', 'funny', 'loved_at', 'liked_at', 'disliked_at', 'laughed_at')
            ->where(function ($q) {
                $q->where('loved', true)
                    ->orWhere('liked', true)
                    ->orWhere('disliked', true)
                    ->orWhere('funny', true);
            })
            ->orderBy('id');

        $candidates = (clone $base)->count();
        if ($limit !== null) {
            $base->limit($limit);
            $candidates = min($candidates, $limit);
        }

        $created = 0;
        $updated = 0;
        $processed = 0;
        $bar = $output ? $output->createProgressBar(max(1, $candidates)) : null;
        if ($bar) {
            $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s%');
            $bar->start();
        }

        $base->chunk($chunk, function ($rows) use (&$created, &$updated, &$processed, $dryRun, $userId, $bar) {
            $now = now();
            $inserts = [];
            foreach ($rows as $row) {
                $type = null;
                $ts = null;
                if ($row->loved) {
                    $type = 'love';
                    $ts = $row->loved_at;
                } elseif ($row->liked) {
                    $type = 'like';
                    $ts = $row->liked_at;
                } elseif ($row->disliked) {
                    $type = 'dislike';
                    $ts = $row->disliked_at;
                } elseif ($row->funny) {
                    $type = 'funny';
                    $ts = $row->laughed_at;
                }
                if (! $type) {
                    continue;
                }

                $inserts[] = [
                    'file_id' => $row->id,
                    'user_id' => $userId,
                    'type' => $type,
                    'created_at' => $ts ?: $now,
                    'updated_at' => $ts ?: $now,
                ];
                $processed++;
            }

            if (! $dryRun && ! empty($inserts)) {
                // upsert returns number of affected rows in Laravel 12; approximate split into created/updated via checking existing
                $existing = DB::table('reactions')
                    ->where('user_id', $userId)
                    ->whereIn('file_id', array_column($inserts, 'file_id'))
                    ->pluck('file_id')
                    ->all();
                $existingSet = array_flip($existing);

                DB::table('reactions')->upsert($inserts, ['user_id', 'file_id'], ['type', 'created_at', 'updated_at']);

                foreach ($inserts as $row) {
                    if (isset($existingSet[$row['file_id']])) {
                        $updated++;
                    } else {
                        $created++;
                    }
                }
            }

            if ($bar) {
                $bar->advance(count($rows));
            }
        });

        if ($bar) {
            $bar->finish();
        }

        return [
            'candidates' => (int) $candidates,
            'processed' => (int) $processed,
            'created' => (int) $created,
            'updated' => (int) $updated,
        ];
    }
}
