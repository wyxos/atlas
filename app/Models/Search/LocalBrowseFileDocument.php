<?php

namespace App\Models\Search;

use App\Models\File;
use App\Models\Reaction;
use App\Services\Local\LocalBrowseTypesenseNames;
use App\Services\Local\LocalBrowseTypesenseSchemaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

class LocalBrowseFileDocument extends File
{
    use Searchable;

    protected $table = 'files';

    public function searchableAs()
    {
        return app(LocalBrowseTypesenseNames::class)->filesAlias();
    }

    public function indexableAs()
    {
        return $this->searchableAs();
    }

    public function toSearchableArray(): array
    {
        $reactions = $this->relationLoaded('reactions')
            ? $this->getRelation('reactions')
            : $this->reactions()->get(['file_id', 'user_id', 'type']);

        [$loveUserIds, $likeUserIds, $funnyUserIds, $reactedUserIds] = $this->collectReactionUserIds($reactions);

        return [
            'id' => (string) $this->id,
            'sort_id' => (int) $this->id,
            'source' => $this->source ?: null,
            'mime_group' => $this->resolveMimeGroup(),
            'mime_type' => $this->mime_type ?: null,
            'previewed_count' => (int) ($this->previewed_count ?? 0),
            'blacklisted' => $this->blacklisted_at !== null,
            'blacklisted_at' => $this->timestampOrNull($this->blacklisted_at),
            'downloaded' => (bool) $this->downloaded,
            'downloaded_at' => $this->timestampOrNull($this->downloaded_at),
            'not_found' => (bool) $this->not_found,
            'auto_blacklisted' => (bool) $this->auto_blacklisted,
            'love_user_ids' => $loveUserIds,
            'like_user_ids' => $likeUserIds,
            'funny_user_ids' => $funnyUserIds,
            'reacted_user_ids' => $reactedUserIds,
            'created_at' => $this->timestampOrZero($this->created_at),
            'updated_at' => $this->timestampOrZero($this->updated_at),
        ];
    }

    public function typesenseCollectionSchema(): array
    {
        return app(LocalBrowseTypesenseSchemaFactory::class)->fileSchema($this->searchableAs());
    }

    public function typesenseSearchParameters(): array
    {
        return [
            'query_by' => 'source,mime_group,mime_type',
            'prefix' => false,
        ];
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class, 'file_id');
    }

    protected function makeAllSearchableUsing(Builder $query)
    {
        return $query->with([
            'reactions' => fn ($builder) => $builder->select(['id', 'file_id', 'user_id', 'type']),
        ]);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>}
     */
    private function collectReactionUserIds(Collection $reactions): array
    {
        $userIdsByType = [
            'love' => [],
            'like' => [],
            'funny' => [],
        ];
        $reactedUserIds = [];

        foreach ($reactions as $reaction) {
            if (! $reaction instanceof Reaction) {
                continue;
            }

            $userId = (string) $reaction->user_id;
            $reactedUserIds[$userId] = true;

            if (array_key_exists($reaction->type, $userIdsByType)) {
                $userIdsByType[$reaction->type][$userId] = true;
            }
        }

        return [
            array_map('strval', array_keys($userIdsByType['love'])),
            array_map('strval', array_keys($userIdsByType['like'])),
            array_map('strval', array_keys($userIdsByType['funny'])),
            array_map('strval', array_keys($reactedUserIds)),
        ];
    }

    private function resolveMimeGroup(): string
    {
        $mimeType = strtolower(trim((string) $this->mime_type));

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'other',
        };
    }

    private function timestampOrNull(mixed $value): ?int
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return $value->getTimestamp();
    }

    private function timestampOrZero(mixed $value): int
    {
        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : 0;
    }
}
