<?php

namespace App\Models;

use App\Enums\ActionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

class File extends Model
{
    use HasFactory, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'source_id',
        'url',
        'referrer_url',
        'path',
        'filename',
        'ext',
        'size',
        'mime_type',
        'hash',
        'title',
        'description',
        'preview_url',
        'preview_path',
        'poster_path',
        'tags',
        'parent_id',
        'chapter',
        'previewed_at',
        'previewed_count',
        'seen_at',
        'seen_count',
        'blacklisted_at',
        'blacklist_reason',
        'not_found',
        'listing_metadata',
        'detail_metadata',
        'downloaded',
        'downloaded_at',
        'download_progress',
        'auto_disliked',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'listing_metadata' => 'array',
            'detail_metadata' => 'array',
            'previewed_at' => 'datetime',
            'seen_at' => 'datetime',
            'blacklisted_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'not_found' => 'boolean',
            'downloaded' => 'boolean',
            'auto_disliked' => 'boolean',
        ];
    }

    /**
     * Generate a subfolder-based storage path for a file.
     *
     * This creates a hierarchical directory structure to prevent having
     * millions of files in a single directory, which can cause performance issues.
     *
     * Structure: {type}/{hash[0:2]}/{hash[2:4]}/{filename}
     * Example: images/ab/cd/abcdef123456.jpg
     */
    public static function generateStoragePath(string $type, string $filename, ?string $hash = null): string
    {
        // Use provided hash or generate one from filename
        $hashValue = self::normalizeHash($hash) ?? hash('sha256', $filename);

        // Take first 4 characters for 2-level subfolder structure (256^2 = 65,536 possible folders)
        // This distributes files evenly and keeps folder sizes manageable
        $subfolder1 = substr($hashValue, 0, 2);
        $subfolder2 = substr($hashValue, 2, 2);

        return "private/{$type}/{$subfolder1}/{$subfolder2}/{$filename}";
    }

    private static function normalizeHash(?string $hash): ?string
    {
        if (! $hash) {
            return null;
        }

        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{4,}$/', $hash) === 1 ? $hash : null;
    }

    /**
     * Get the full storage path for a file, ensuring directories exist.
     */
    public static function getStoragePath(string $type, string $filename, ?string $hash = null): string
    {
        $relativePath = self::generateStoragePath($type, $filename, $hash);
        $fullPath = storage_path("app/{$relativePath}");

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $fullPath;
    }

    /**
     * Get the file metadata.
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(FileMetadata::class);
    }

    /**
     * Get the containers that this file belongs to.
     */
    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(Container::class);
    }

    /**
     * Get all reactions for this file.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function moderationActions(): HasMany
    {
        return $this->hasMany(FileModerationAction::class);
    }

    public function autoDislikeModerationAction(): HasOne
    {
        return $this->hasOne(FileModerationAction::class)
            ->where('action_type', ActionType::DISLIKE);
    }

    public function autoBlacklistModerationAction(): HasOne
    {
        return $this->hasOne(FileModerationAction::class)
            ->where('action_type', ActionType::BLACKLIST);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = [
            'id' => (string) $this->id,
            'source' => $this->source ?? 'unknown',
            'source_id' => $this->source_id,
            'url' => $this->url,
            'referrer_url' => $this->referrer_url,
            'path' => $this->path ?? '__missing__',
            'has_path' => (bool) $this->path,
            'filename' => $this->filename,
            'ext' => $this->ext,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'hash' => $this->hash,
            'title' => $this->title,
            'description' => $this->description,
            'preview_url' => $this->preview_url,
            'preview_path' => $this->preview_path,
            'poster_path' => $this->poster_path,
            'parent_id' => $this->parent_id,
            'chapter' => $this->chapter,
            'blacklisted' => $this->blacklisted_at !== null,
            'blacklist_reason' => $this->blacklist_reason,
            'downloaded' => $this->downloaded,
            'download_progress' => $this->download_progress,
            'not_found' => $this->not_found,
            'auto_disliked' => $this->auto_disliked,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
            'previewed_count' => (int) ($this->previewed_count ?? 0),
            'seen_count' => (int) ($this->seen_count ?? 0),
        ];

        if ($this->blacklisted_at !== null) {
            $array['blacklist_type'] = $this->blacklist_reason ? 'manual' : 'auto';
        }

        // mime_group for easy filtering - always calculate from current mime_type
        $mime = $this->mime_type ? (string) $this->mime_type : '';
        if ($mime === '') {
            $array['mime_group'] = 'other';
        } elseif (str_starts_with($mime, 'audio/')) {
            $array['mime_group'] = 'audio';
        } elseif (str_starts_with($mime, 'video/')) {
            $array['mime_group'] = 'video';
        } elseif (str_starts_with($mime, 'image/')) {
            $array['mime_group'] = 'image';
        } else {
            $array['mime_group'] = 'other';
        }

        // Include metadata fields if available
        if ($this->metadata && $this->metadata->payload) {
            $metadata = $this->metadata->payload;

            if (isset($metadata['title'])) {
                $array['metadata_title'] = $metadata['title'];
            }

            if (isset($metadata['genre'])) {
                $array['metadata_genre'] = $metadata['genre'];
            }

            if (isset($metadata['year'])) {
                $array['metadata_year'] = trim((string) $metadata['year']);
            }

            if (isset($metadata['comment'])) {
                $array['metadata_comment'] = $metadata['comment'];
            }

            if (isset($metadata['track'])) {
                $track = $metadata['track'];
                if (is_array($track)) {
                    $track = implode('/', array_filter(array_map(fn ($v) => trim((string) $v), $track)));
                } else {
                    $track = trim((string) $track);
                }
                $array['metadata_track'] = $track;
            }
        }

        // Per-user reaction arrays for Typesense filtering
        try {
            $groups = $this->relationLoaded('reactions')
                ? $this->reactions->groupBy('type')
                : Reaction::query()->select(['user_id', 'type'])->where('file_id', $this->id)->get()->groupBy('type');

            $toIds = function ($col) {
                return collect($col)
                    ->pluck('user_id')
                    ->filter()
                    ->map(fn ($id) => (string) (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            };

            $loveIds = $toIds($groups->get('love', collect()));
            $likeIds = $toIds($groups->get('like', collect()));
            $dislikeIds = $toIds($groups->get('dislike', collect()));
            $funnyIds = $toIds($groups->get('funny', collect()));

            $reactedIds = collect([$loveIds, $likeIds, $dislikeIds, $funnyIds])
                ->flatten()
                ->unique()
                ->values()
                ->all();

            if (! empty($loveIds)) {
                $array['love_user_ids'] = $loveIds;
            }
            if (! empty($likeIds)) {
                $array['like_user_ids'] = $likeIds;
            }
            if (! empty($dislikeIds)) {
                $array['dislike_user_ids'] = $dislikeIds;
            }
            if (! empty($funnyIds)) {
                $array['funny_user_ids'] = $funnyIds;
            }
            if (! empty($reactedIds)) {
                $array['reacted_user_ids'] = $reactedIds;
            }

            // Add has_reactions boolean for efficient filtering
            $array['has_reactions'] = ! empty($reactedIds);
            $array['has_love'] = ! empty($loveIds);
            $array['has_like'] = ! empty($likeIds);
            $array['has_dislike'] = ! empty($dislikeIds);
            $array['has_funny'] = ! empty($funnyIds);
        } catch (\Throwable $e) {
            // If reactions table is not ready or any error occurs, skip embedding arrays
        }

        // Handle tags - ensure it's an array or null
        if ($this->tags) {
            $tags = is_array($this->tags) ? $this->tags : json_decode($this->tags, true);
            $array['tags'] = is_array($tags) ? array_values(array_filter($tags)) : null;
        } else {
            $array['tags'] = null;
        }

        // Handle timestamp fields - convert to Unix timestamps
        $timestampFields = [
            'previewed_at', 'seen_at', 'downloaded_at', 'blacklisted_at',
        ];

        foreach ($timestampFields as $field) {
            $array[$field] = $this->{$field}?->timestamp;
        }

        // Remove null values for optional fields to keep the index clean
        return array_filter($array, function ($value, $key) {
            // Always keep required fields and boolean false values
            if (in_array($key, ['id', 'source', 'filename', 'path', 'created_at', 'updated_at', 'mime_group'])) {
                return true;
            }
            // Keep boolean false values
            if (is_bool($value)) {
                return true;
            }
            // Keep zero values for numeric fields
            if (in_array($key, ['size', 'parent_id', 'download_progress', 'previewed_count', 'seen_count']) && $value === 0) {
                return true;
            }

            // Remove null/empty values for optional fields
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Modify the query used to retrieve models when making all of the models searchable.
     * This ensures reactions are eager-loaded during bulk indexing.
     */
    public function makeAllSearchableUsing($query)
    {
        return $query->with(['metadata', 'reactions']);
    }
}
