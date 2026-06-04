<?php

namespace App\Console\Commands;

use App\Models\Album;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AudioDedupeAlbumsCommand extends Command
{
    protected $signature = 'audio:dedupe-albums
        {--normalized-name= : Limit to one normalized album name}
        {--path-prefix= : Limit apply to files under a managed path prefix}
        {--limit=25 : Maximum duplicate groups to display in dry-run mode}
        {--apply : Merge the selected duplicate group}
        {--allow-global : Allow apply without a path prefix}';

    protected $description = 'List or merge duplicate audio album rows with a guarded dry-run-first workflow.';

    public function handle(): int
    {
        $normalizedName = $this->cleanString($this->option('normalized-name'));
        $pathPrefix = $this->pathPrefix($this->cleanString($this->option('path-prefix')));
        $apply = (bool) $this->option('apply');

        if ($apply && $normalizedName === null) {
            $this->error('Applying a merge requires --normalized-name.');

            return self::FAILURE;
        }

        if ($apply && $pathPrefix === null && ! (bool) $this->option('allow-global')) {
            $this->error('Applying a merge requires --path-prefix or --allow-global.');

            return self::FAILURE;
        }

        $groups = $this->duplicateGroups($normalizedName, (int) $this->option('limit'));
        if ($groups->isEmpty()) {
            $this->info('No duplicate album groups found.');

            return self::SUCCESS;
        }

        foreach ($groups as $group) {
            $this->line(sprintf(
                '%s | rows=%d | files=%d | %s',
                $group->normalized_name,
                $group->album_rows,
                $group->files_count,
                $group->rows_summary,
            ));
        }

        if (! $apply) {
            $this->info('Dry-run only. Re-run with --apply and a scoped --normalized-name to merge.');

            return self::SUCCESS;
        }

        return DB::transaction(function () use ($normalizedName, $pathPrefix): int {
            $albumRows = $this->scopedAlbumRows($normalizedName, $pathPrefix);
            if ($albumRows->count() < 2) {
                $this->warn('The scoped duplicate group has fewer than two album rows.');

                return self::SUCCESS;
            }

            $canonical = $albumRows
                ->sortBy([
                    ['files_count', 'desc'],
                    ['id', 'asc'],
                ])
                ->first();
            $duplicateIds = $albumRows
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->reject(fn (int $id): bool => $id === (int) $canonical->id)
                ->values()
                ->all();

            $this->moveAlbumFiles($duplicateIds, (int) $canonical->id, $pathPrefix);
            $emptyDuplicateIds = $this->emptyAlbumIds($duplicateIds);
            $this->moveAlbumCovers($emptyDuplicateIds, (int) $canonical->id);
            $this->moveMetadataAliases($emptyDuplicateIds, (int) $canonical->id);

            Album::query()
                ->whereIn('id', $emptyDuplicateIds)
                ->delete();

            $this->info('Merged '.count($emptyDuplicateIds).' duplicate album row'.(count($emptyDuplicateIds) === 1 ? '' : 's').'.');

            return self::SUCCESS;
        });
    }

    /**
     * @return Collection<int, object>
     */
    private function duplicateGroups(?string $normalizedName, int $limit): Collection
    {
        $query = DB::table('albums')
            ->leftJoin(DB::raw('(select album_id, count(*) as files_count from album_file group by album_id) as album_counts'), 'album_counts.album_id', '=', 'albums.id')
            ->select([
                'albums.normalized_name',
                DB::raw('COUNT(albums.id) as album_rows'),
                DB::raw('COALESCE(SUM(album_counts.files_count), 0) as files_count'),
                DB::raw($this->groupConcatSummarySql()),
            ])
            ->whereNotNull('albums.normalized_name')
            ->where('albums.normalized_name', '<>', '')
            ->groupBy('albums.normalized_name')
            ->havingRaw('COUNT(albums.id) > 1')
            ->havingRaw('COALESCE(SUM(album_counts.files_count), 0) > 0')
            ->orderByDesc('files_count')
            ->orderByDesc('album_rows');

        if ($normalizedName !== null) {
            $query->where('albums.normalized_name', $normalizedName);
        } else {
            $query->limit(max(1, $limit));
        }

        return $query->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function scopedAlbumRows(string $normalizedName, ?string $pathPrefix): Collection
    {
        $query = DB::table('albums')
            ->leftJoin('album_file', 'albums.id', '=', 'album_file.album_id')
            ->leftJoin('files', 'files.id', '=', 'album_file.file_id')
            ->where('albums.normalized_name', $normalizedName)
            ->groupBy('albums.id', 'albums.name')
            ->select([
                'albums.id',
                'albums.name',
                DB::raw('COUNT(files.id) as files_count'),
            ])
            ->orderBy('albums.id');

        if ($pathPrefix !== null) {
            $query->where(function ($query) use ($pathPrefix): void {
                $query->whereNull('files.id')
                    ->orWhere('files.path', 'like', $this->likePathPrefix($pathPrefix));
            });
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $duplicateIds
     */
    private function moveAlbumFiles(array $duplicateIds, int $canonicalId, ?string $pathPrefix): void
    {
        $rows = DB::table('album_file')
            ->join('files', 'files.id', '=', 'album_file.file_id')
            ->whereIn('album_id', $duplicateIds)
            ->when($pathPrefix !== null, fn ($query) => $query->where('files.path', 'like', $this->likePathPrefix($pathPrefix)))
            ->orderBy('album_file.file_id')
            ->get([
                'album_file.album_id',
                'album_file.file_id',
                'album_file.track_number',
                'album_file.disc_number',
            ]);

        $rows->each(function (object $row) use ($canonicalId): void {
            $existing = DB::table('album_file')
                ->where('album_id', $canonicalId)
                ->where('file_id', $row->file_id)
                ->first();

            if ($existing === null) {
                DB::table('album_file')->insert([
                    'album_id' => $canonicalId,
                    'file_id' => $row->file_id,
                    'track_number' => $row->track_number,
                    'disc_number' => $row->disc_number,
                ]);

                return;
            }

            DB::table('album_file')
                ->where('album_id', $canonicalId)
                ->where('file_id', $row->file_id)
                ->update([
                    'track_number' => $existing->track_number ?? $row->track_number,
                    'disc_number' => $existing->disc_number ?? $row->disc_number,
                ]);
        });

        if ($rows->isEmpty()) {
            return;
        }

        DB::table('album_file')
            ->whereIn('album_id', $duplicateIds)
            ->whereIn('file_id', $rows->pluck('file_id')->all())
            ->delete();
    }

    /**
     * @param  list<int>  $albumIds
     * @return list<int>
     */
    private function emptyAlbumIds(array $albumIds): array
    {
        return Album::query()
            ->whereIn('id', $albumIds)
            ->doesntHave('files')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $duplicateIds
     */
    private function moveAlbumCovers(array $duplicateIds, int $canonicalId): void
    {
        $hasDefault = DB::table('album_covers')
            ->where('album_id', $canonicalId)
            ->where('is_default', true)
            ->exists();

        DB::table('album_covers')
            ->whereIn('album_id', $duplicateIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $cover) use (&$hasDefault, $canonicalId): void {
                $exists = DB::table('album_covers')
                    ->where('album_id', $canonicalId)
                    ->where('path_hash', $cover->path_hash)
                    ->exists();

                if ($exists) {
                    DB::table('album_covers')->where('id', $cover->id)->delete();

                    return;
                }

                DB::table('album_covers')
                    ->where('id', $cover->id)
                    ->update([
                        'album_id' => $canonicalId,
                        'is_default' => ! $hasDefault && (bool) $cover->is_default,
                    ]);

                $hasDefault = $hasDefault || (bool) $cover->is_default;
            });
    }

    /**
     * @param  list<int>  $duplicateIds
     */
    private function moveMetadataAliases(array $duplicateIds, int $canonicalId): void
    {
        DB::table('metadata_aliases')
            ->where('aliasable_type', Album::class)
            ->whereIn('aliasable_id', $duplicateIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $alias) use ($canonicalId): void {
                DB::table('metadata_aliases')->insertOrIgnore([
                    'aliasable_type' => Album::class,
                    'aliasable_id' => $canonicalId,
                    'field' => $alias->field,
                    'value' => $alias->value,
                    'normalized_value' => $alias->normalized_value,
                    'kind' => $alias->kind,
                    'locale' => $alias->locale,
                    'source' => $alias->source,
                    'source_id' => $alias->source_id,
                    'created_at' => $alias->created_at,
                    'updated_at' => $alias->updated_at,
                ]);
            });

        DB::table('metadata_aliases')
            ->where('aliasable_type', Album::class)
            ->whereIn('aliasable_id', $duplicateIds)
            ->delete();
    }

    private function groupConcatSummarySql(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "GROUP_CONCAT('#' || albums.id || ' ' || albums.name || ' (' || COALESCE(album_counts.files_count, 0) || ')', ' | ') as rows_summary";
        }

        return "GROUP_CONCAT(CONCAT('#', albums.id, ' ', albums.name, ' (', COALESCE(album_counts.files_count, 0), ')') ORDER BY albums.id SEPARATOR ' | ') as rows_summary";
    }

    private function pathPrefix(?string $pathPrefix): ?string
    {
        if ($pathPrefix === null) {
            return null;
        }

        $pathPrefix = trim(str_replace('\\', '/', $pathPrefix), '/');

        return $pathPrefix !== '' ? $pathPrefix : null;
    }

    private function likePathPrefix(string $pathPrefix): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pathPrefix);

        return $escaped.'/%';
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }
}
