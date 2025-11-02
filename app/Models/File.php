<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class File extends Model
{
    use HasFactory, Searchable;

    public array $searchableFields = [
        'filename',
        'mime_type',
    ];

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
        'thumbnail_url',
        'thumbnail_path',
        'tags',
        'parent_id',
        'chapter',
        'previewed_at',
        'seen_at',
        'previewed_count',
        'seen_count',
        'blacklisted_at',
        'blacklist_reason',
        'downloaded',
        'download_progress',
        'downloaded_at',
        'not_found',
        'listing_metadata',
        'detail_metadata',
        'created_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'size' => 'integer',
        'parent_id' => 'integer',
        'downloaded' => 'boolean',
        'download_progress' => 'integer',
        'previewed_count' => 'integer',
        'seen_count' => 'integer',
        'not_found' => 'boolean',
        'previewed_at' => 'datetime',
        'seen_at' => 'datetime',
        'blacklisted_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'listing_metadata' => 'array',
        'detail_metadata' => 'array',
    ];

    public function metadata(): HasOne
    {
        return $this->hasOne(FileMetadata::class);
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class);
    }

    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class);
    }

    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(Queue::class)->withPivot('position')->orderBy('position');
    }

    public function covers(): MorphMany
    {
        return $this->morphMany(Cover::class, 'coverable')
            ->orderByRaw('CASE WHEN coverable_type = ? THEN 0 ELSE 1 END', [Album::class]);
    }

    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(Container::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'audio/%');
    }

    public function scopeVideo(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'video/%');
    }

    public function scopeImage(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    public function toSearchableArray()
    {
        // Relationships should already be loaded via makeAllSearchableUsing

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
            'thumbnail_url' => $this->thumbnail_url,
            'thumbnail_path' => $this->thumbnail_path,
            'parent_id' => $this->parent_id,
            'chapter' => $this->chapter,
            'blacklisted' => $this->blacklisted_at !== null,
            'blacklist_reason' => $this->blacklist_reason,
            'downloaded' => $this->downloaded,
            'download_progress' => $this->download_progress,
            'not_found' => $this->not_found,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
            // Include counts (default to 0 to keep documents filterable)
            'previewed_count' => (int) ($this->previewed_count ?? 0),
            'seen_count' => (int) ($this->seen_count ?? 0),
        ];

        // mime_group for easy filtering
        $mime = (string) $this->mime_type;
        $array['mime_group'] = str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : 'other'));

        // Include playlist IDs for engine-side filtering if relation loaded
        if ($this->playlists->count()) {
            $array['playlist_ids'] = $this->playlists->pluck('id')->values()->all();
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
                // Typesense schema expects string; normalize year to string
                $array['metadata_year'] = trim((string) $metadata['year']);
            }

            if (isset($metadata['comment'])) {
                $array['metadata_comment'] = $metadata['comment'];
            }

            if (isset($metadata['track'])) {
                // Normalize track to string; handle arrays like [track, total]
                $track = $metadata['track'];
                if (is_array($track)) {
                    $track = implode('/', array_filter(array_map(fn ($v) => trim((string) $v), $track)));
                } else {
                    $track = trim((string) $track);
                }
                $array['metadata_track'] = $track;
            }
        }

        // Include artist names from related artists
        if ($this->artists && $this->artists->isNotEmpty()) {
            $artistNames = $this->artists->pluck('name')->filter()->join(', ');
            $array['artist_names'] = $artistNames;
        }

        // Include album names from related albums
        if ($this->albums && $this->albums->isNotEmpty()) {
            $albumNames = $this->albums->pluck('name')->filter()->join(', ');
            $array['album_names'] = $albumNames;
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
            // Keep zero values for numeric fields (ensure preview/seen counts are retained)
            if (in_array($key, ['size', 'parent_id', 'download_progress', 'previewed_count', 'seen_count']) && $value === 0) {
                return true;
            }

            // Remove null/empty values for optional fields
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }

    // Ensure related data is eager-loaded during bulk indexing for Scout
    public function makeAllSearchableUsing($query)
    {
        return $query->with(['metadata', 'artists', 'albums', 'playlists', 'reactions']);
    }

    public function download(): HasOne|File
    {
        return $this->hasOne(Download::class);
    }
}
