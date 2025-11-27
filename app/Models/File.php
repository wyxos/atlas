<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
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
        ];
    }
}
