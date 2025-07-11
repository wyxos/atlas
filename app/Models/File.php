<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class File extends Model
{
    use HasFactory, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
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
        'thumbnail_url',
        'tags',
        'parent_id',
        'chapter',
        'seen_preview_at',
        'seen_file_at',
        'is_blacklisted',
        'blacklist_reason',
        'liked',
        'liked_at',
        'disliked',
        'disliked_at',
        'loved',
        'loved_at',
        'funny',
        'laughed_at',
        'downloaded',
        'download_progress',
        'downloaded_at',
        'not_found',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tags' => 'array',
        'size' => 'integer',
        'parent_id' => 'integer',
        'is_blacklisted' => 'boolean',
        'liked' => 'boolean',
        'disliked' => 'boolean',
        'loved' => 'boolean',
        'funny' => 'boolean',
        'downloaded' => 'boolean',
        'download_progress' => 'integer',
        'not_found' => 'boolean',
        'seen_preview_at' => 'datetime',
        'seen_file_at' => 'datetime',
        'liked_at' => 'datetime',
        'disliked_at' => 'datetime',
        'loved_at' => 'datetime',
        'laughed_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    /**
     * Get the metadata associated with the file.
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(FileMetadata::class);
    }


    /**
     * Get the artists associated with the file.
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class);
    }

    /**
     * Get the albums associated with the file.
     */
    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class);
    }

    /**
     * Get the playlists associated with the file.
     */
    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class);
    }

    /**
     * Get all of the file's covers.
     * Ordered to prioritize album covers over artist covers.
     */
    public function covers(): MorphMany
    {
        return $this->morphMany(Cover::class, 'coverable')
            ->orderByRaw("CASE WHEN coverable_type = ? THEN 0 ELSE 1 END", [Album::class]);
    }

    /**
     * Scope a query to only include audio files.
     */
    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'audio/%');
    }

    // Customize the data sent to Typesense
    public function toSearchableArray()
    {
        // Load metadata relationship if not already loaded
        if (! $this->relationLoaded('metadata')) {
            $this->load('metadata');
        }

        // Load artists and albums relationships if not already loaded
        if (! $this->relationLoaded('artists')) {
            $this->load('artists');
        }
        if (! $this->relationLoaded('albums')) {
            $this->load('albums');
        }

        $array = [
            'id' => (string) $this->id,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'url' => $this->url,
            'referrer_url' => $this->referrer_url,
            'path' => $this->path,
            'filename' => $this->filename,
            'ext' => $this->ext,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'hash' => $this->hash,
            'title' => $this->title,
            'description' => $this->description,
            'thumbnail_url' => $this->thumbnail_url,
            'parent_id' => $this->parent_id,
            'chapter' => $this->chapter,
            'is_blacklisted' => $this->is_blacklisted,
            'blacklist_reason' => $this->blacklist_reason,
            'liked' => $this->liked,
            'disliked' => $this->disliked,
            'loved' => $this->loved,
            'downloaded' => $this->downloaded,
            'download_progress' => $this->download_progress,
            'not_found' => $this->not_found,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];

        // Include metadata fields if available
        if ($this->metadata && $this->metadata->payload) {
            $metadata = $this->metadata->payload;

            // Add common audio metadata fields
            if (isset($metadata['artist'])) {
                $array['metadata_artist'] = $metadata['artist'];
            }

            if (isset($metadata['title'])) {
                $array['metadata_title'] = $metadata['title'];
            }

            if (isset($metadata['album'])) {
                $array['metadata_album'] = $metadata['album'];
            }

            if (isset($metadata['genre'])) {
                $array['metadata_genre'] = $metadata['genre'];
            }

            if (isset($metadata['year'])) {
                $array['metadata_year'] = $metadata['year'];
            }

            if (isset($metadata['comment'])) {
                $array['metadata_comment'] = $metadata['comment'];
            }

            if (isset($metadata['track'])) {
                $array['metadata_track'] = $metadata['track'];
            }
        }

        // Include artist names from related artists
        if ($this->artists && $this->artists->isNotEmpty()) {
            $artistNames = $this->artists->pluck('name')->filter()->toArray();
            $array['artist_names'] = $artistNames;
            // Also include the first artist name for backward compatibility
            $array['artist_name'] = $artistNames[0] ?? null;
        }

        // Include album names from related albums
        if ($this->albums && $this->albums->isNotEmpty()) {
            $albumNames = $this->albums->pluck('name')->filter()->toArray();
            $array['album_names'] = $albumNames;
            // Also include the first album name for backward compatibility
            $array['album_name'] = $albumNames[0] ?? null;
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
            'seen_preview_at', 'seen_file_at', 'liked_at', 'disliked_at',
            'loved_at', 'downloaded_at',
        ];

        foreach ($timestampFields as $field) {
            $array[$field] = $this->{$field}?->timestamp;
        }

        // Remove null values for optional fields to keep the index clean
        return array_filter($array, function ($value, $key) {
            // Always keep required fields and boolean false values
            if (in_array($key, ['id', 'source', 'filename', 'created_at', 'updated_at'])) {
                return true;
            }
            // Keep boolean false values
            if (is_bool($value)) {
                return true;
            }
            // Keep zero values for numeric fields
            if (in_array($key, ['size', 'parent_id', 'download_progress']) && $value === 0) {
                return true;
            }

            // Remove null/empty values for optional fields
            return $value !== null && $value !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }
}
