<?php

namespace App\Services\Playlists;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SystemPlaylistSyncService
{
    public function __construct(
        private readonly SystemPlaylistCatalog $catalog,
    ) {}

    /**
     * @return array{processed: int, created: int, updated: int, deleted: int}
     */
    public function syncForUser(User $user): array
    {
        $created = 0;
        $updated = 0;
        $definitions = $this->catalog->definitions($this->audioSourceKeys());

        foreach ($definitions as $definition) {
            $playlist = Playlist::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'slug' => $definition['slug'],
                ],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'kind' => 'system',
                    'membership_mode' => 'rules',
                    'membership_rules' => $definition['membership_rules'],
                    'source_key' => $definition['source_key'],
                    'is_system' => true,
                    'is_smart' => false,
                    'is_editable' => false,
                    'is_deletable' => false,
                    'sort_order' => $definition['sort_order'],
                ],
            );

            $playlist->wasRecentlyCreated ? $created++ : $updated++;
        }

        $deleted = Playlist::query()
            ->where('user_id', $user->id)
            ->where('is_system', true)
            ->whereNotIn('slug', array_column($definitions, 'slug'))
            ->delete();

        return [
            'processed' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    /**
     * @return array{users: int, processed: int, created: int, updated: int, deleted: int}
     */
    public function syncAllUsers(): array
    {
        $summary = [
            'users' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];

        User::query()
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$summary): void {
                foreach ($users as $user) {
                    $result = $this->syncForUser($user);
                    $summary['users']++;
                    $summary['processed'] += $result['processed'];
                    $summary['created'] += $result['created'];
                    $summary['updated'] += $result['updated'];
                    $summary['deleted'] += $result['deleted'];
                }
            });

        return $summary;
    }

    /**
     * @return list<string>
     */
    public function audioSourceKeys(): array
    {
        $sourceKeys = DB::table('files')
            ->where('mime_type', 'like', 'audio/%')
            ->whereNotNull('source')
            ->selectRaw('LOWER(TRIM(source)) as source_key')
            ->distinct()
            ->pluck('source_key')
            ->map(fn (mixed $sourceKey): string => $this->normalizeSourceKey((string) $sourceKey))
            ->filter(fn (string $sourceKey): bool => $sourceKey !== '')
            ->values()
            ->all();

        return array_values(array_unique($sourceKeys));
    }

    private function normalizeSourceKey(string $sourceKey): string
    {
        $sourceKey = strtolower(trim($sourceKey));

        return match ($sourceKey) {
            'nas' => 'local',
            default => $sourceKey,
        };
    }
}
