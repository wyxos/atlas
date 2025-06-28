<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
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
        'downloaded' => 'boolean',
        'download_progress' => 'integer',
        'seen_preview_at' => 'datetime',
        'seen_file_at' => 'datetime',
        'liked_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];
}
