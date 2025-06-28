<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'downloaded',
        'download_progress',
        'downloaded_at',
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
        'downloaded' => 'boolean',
        'download_progress' => 'integer',
        'seen_preview_at' => 'datetime',
        'seen_file_at' => 'datetime',
        'liked_at' => 'datetime',
        'disliked_at' => 'datetime',
        'loved_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    // Customize the data sent to Typesense
    public function toSearchableArray()
    {
        $array = [
            'id'               => (string) $this->id,
            'source'           => $this->source,
            'source_id'        => $this->source_id,
            'url'              => $this->url,
            'referrer_url'     => $this->referrer_url,
            'path'             => $this->path,
            'filename'         => $this->filename,
            'ext'              => $this->ext,
            'size'             => $this->size,
            'mime_type'        => $this->mime_type,
            'hash'             => $this->hash,
            'title'            => $this->title,
            'description'      => $this->description,
            'thumbnail_url'    => $this->thumbnail_url,
            'parent_id'        => $this->parent_id,
            'chapter'          => $this->chapter,
            'is_blacklisted'   => $this->is_blacklisted,
            'blacklist_reason' => $this->blacklist_reason,
            'liked'            => $this->liked,
            'disliked'         => $this->disliked,
            'loved'            => $this->loved,
            'downloaded'       => $this->downloaded,
            'download_progress'=> $this->download_progress,
            'created_at'       => $this->created_at?->timestamp,
            'updated_at'       => $this->updated_at?->timestamp,
        ];

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
            'loved_at', 'downloaded_at'
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
